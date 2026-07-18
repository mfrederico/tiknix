<?php
/**
 * Ecommerce — the feature-flagged storefront toolset hub + product catalog admin.
 *
 * Gated by the per-member `ecommerce` feature flag (see app\Feature): toggled by an
 * admin on the Edit Member page, available only to ADMIN/ROOT, and the left-nav
 * "Ecommerce" tab is shown by the same check.
 *
 * INSTANCE-SCOPED — a member's stores are their AI Builder instances. Product
 * catalog CRUD writes plain JSON into the INSTANCE's public/store folder via
 * app\StoreCatalog, so the catalog deploys with the instance to GitHub and is
 * rendered client-side later. Each feature card surfaces the connection it depends
 * on (Payments -> that instance's Stripe connection).
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Feature;
use app\Bean;
use app\StoreCatalog;
use RedBeanPHP\R;

class Ecommerce extends Control {

    /** Require login AND the ecommerce feature flag; redirect otherwise. */
    private function requireFeature(): bool {
        if (!$this->requireLogin()) return false;
        if (!Feature::isEnabled('ecommerce', (int)$this->member->id, (int)$this->member->level)) {
            $this->flash('error', 'The Ecommerce feature is not enabled for your account.');
            Flight::redirect('/dashboard');
            return false;
        }
        return true;
    }

    private function instanceDir(string $slug): string {
        return '/var/www/html/default/' . $slug . '.tiknix';
    }

    /** Load an instance the current member owns and that exists on disk, or null. */
    private function ownedInstance($id) {
        $id = (int)$id;
        if (!$id) return null;
        $inst = R::load('instance', $id);
        if (!$inst->id || (int)$inst->memberId !== (int)$this->member->id) return null;
        if (!is_file($this->instanceDir($inst->slug) . '/public/index.php')) return null;
        return $inst;
    }

    private function catalog($inst): StoreCatalog {
        return new StoreCatalog($this->instanceDir($inst->slug));
    }

    /** Public base URL of an instance (<slug>.<control-plane-host>) — where its images are served. */
    private function instancePublicUrl($inst): string {
        $host = parse_url((string)Flight::get('app.baseurl'), PHP_URL_HOST) ?: 'tiknix.com';
        return 'https://' . $inst->slug . '.' . $host;
    }

    /** GET /ecommerce?id=<instance> — the storefront tools hub for one store. */
    public function index($params = []): void {
        if (!$this->requireFeature()) return;

        $instances = R::find('instance', 'member_id = ? ORDER BY created_at DESC', [(int)$this->member->id]);

        // Focus one store: ?id= when it is the member's, else the most recent.
        $wantId   = (int)$this->getParam('id', 0);
        $selected = null;
        foreach ($instances as $i) {
            if ($wantId && (int)$i->id === $wantId) { $selected = $i; break; }
        }
        if (!$selected && !$wantId && count($instances)) {
            $selected = $instances[array_key_first($instances)];
        }

        // Live Stripe status for the selected store — this is the Payments tie.
        $stripe = null;
        $productCount = 0;
        if ($selected) {
            $ad = Flight::get('cachedDatabaseAdapter');
            if ($ad instanceof \app\CachedDatabaseAdapter) $ad->invalidateTable('connections');
            $conns = [];
            foreach (Bean::find('connections',
                'member_id = ? AND instance_id = ? AND connector_type = ? AND enabled = 1',
                [(int)$this->member->id, (int)$selected->id, 'stripe']) as $c) {
                if (!empty($c->revokedAt)) continue;
                $conns[] = [
                    'environment' => $c->environment ?: 'production',
                    'name'        => $c->externalName ?: $c->externalEid,
                ];
            }
            $stripe = ['connected' => count($conns) > 0, 'connections' => $conns];
            $productCount = count($this->catalog($selected)->listProducts());
        }

        $this->render('ecommerce/index', [
            'title'        => 'Ecommerce',
            'instances'    => $instances,
            'selected'     => $selected,
            'stripe'       => $stripe,
            'productCount' => $productCount,
        ]);
    }

    /** GET /ecommerce/products?id=<instance> — product list for one store. */
    public function products($params = []): void {
        if (!$this->requireFeature()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::redirect('/ecommerce'); return; }
        $this->render('ecommerce/products', [
            'title'       => 'Products',
            'instance'    => $inst,
            'instanceUrl' => $this->instancePublicUrl($inst),
            'products'    => $this->catalog($inst)->listProducts(),
        ]);
    }

    /** GET /ecommerce/productedit?id=<instance>&sku=<sku> — new/edit product form. */
    public function productedit($params = []): void {
        if (!$this->requireFeature()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::redirect('/ecommerce'); return; }
        $sku = (string)$this->getParam('sku', '');
        $product = $sku !== '' ? $this->catalog($inst)->getProduct($sku) : null;
        $this->render('ecommerce/product-edit', [
            'title'       => $product ? 'Edit product' : 'New product',
            'instance'    => $inst,
            'instanceUrl' => $this->instancePublicUrl($inst),
            'product'     => $product,
        ]);
    }

    /**
     * GET /ecommerce/preview?id=<instance>[&sku=<sku>] — rendered storefront preview.
     * With a sku, previews that product's PDP; without, previews the PLP (all active
     * products). This is the control-plane preview; the real storefront ships on the
     * instance in Phase 4.
     */
    public function preview($params = []): void {
        if (!$this->requireFeature()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::redirect('/ecommerce'); return; }
        $catalog = $this->catalog($inst);
        $iu  = $this->instancePublicUrl($inst);
        $sku = (string)$this->getParam('sku', '');

        if ($sku !== '') {
            $product = $catalog->getProduct($sku);
            if (!$product) { Flight::redirect('/ecommerce/products?id=' . (int)$inst->id); return; }
            $this->render('ecommerce/preview', [
                'title'       => 'Preview — ' . ($product['title'] ?? ''),
                'instance'    => $inst,
                'instanceUrl' => $iu,
                'product'     => $product,
            ]);
            return;
        }
        $products = array_values(array_filter($catalog->listProducts(), fn($p) => !empty($p['active'])));
        $this->render('ecommerce/preview-list', [
            'title'       => 'Storefront preview',
            'instance'    => $inst,
            'instanceUrl' => $iu,
            'products'    => $products,
        ]);
    }

    /** POST /ecommerce/productsave — validate + write a product JSON. */
    public function productsave($params = []): void {
        if (!$this->requireFeature()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Store not found', 404); return; }
        try {
            $product = $this->catalog($inst)->saveProduct([
                'sku'           => $this->getParam('sku', ''),
                'title'         => $this->getParam('title', ''),
                'description'   => $this->getParam('description', ''),
                'price'         => $this->getParam('price', 0),
                'currency'      => $this->getParam('currency', 'usd'),
                'stripePriceId' => $this->getParam('stripe_price_id', ''),
                'category'      => $this->getParam('category', ''),
                'serialized'    => filter_var($this->getParam('serialized', false), FILTER_VALIDATE_BOOLEAN),
                'holdMinutes'   => $this->getParam('hold_minutes', 10),
                'stock'         => $this->getParam('stock', 0),
                'units'         => $this->getParam('units', ''),
                'active'        => filter_var($this->getParam('active', '0'), FILTER_VALIDATE_BOOLEAN),
            ]);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400); return;
        }
        $this->jsonSuccess(['sku' => $product['sku'], 'product' => $product], 'Product saved');
    }

    /** POST /ecommerce/productimage — upload one product image (relative path). */
    public function productimage($params = []): void {
        if (!$this->requireFeature()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Store not found', 404); return; }
        try {
            $rel = $this->catalog($inst)->addProductImage((string)$this->getParam('sku', ''), $_FILES['image'] ?? []);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400); return;
        }
        $this->jsonSuccess(['path' => $rel], 'Image added');
    }

    /** POST /ecommerce/productdelete — remove a product JSON (+ its images). */
    public function productdelete($params = []): void {
        if (!$this->requireFeature()) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { $this->jsonError('Store not found', 404); return; }
        $ok = $this->catalog($inst)->deleteProduct((string)$this->getParam('sku', ''));
        $this->jsonSuccess(['deleted' => $ok], 'Product removed');
    }
}
