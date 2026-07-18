<?php
/**
 * Ecommerce — the feature-flagged storefront toolset hub + product catalog admin.
 *
 * Gated by the per-member `ecommerce` feature flag (see app\Feature): toggled by an
 * admin on the Edit Member page, available only to ADMIN/ROOT, and the left-nav
 * "Ecommerce" tab is shown by the same check.
 *
 * The STORE is tiknix.com itself: product CRUD writes plain JSON into this site's
 * public/products/ folder via app\StoreCatalog (committed to the repo, published
 * with the site, rendered client-side at /products/ by public/products/store.js).
 * The hub still lists the member's instances for the per-instance Payments (Stripe)
 * tie; products are one shared catalog and are not instance-scoped.
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

    /** The catalog for the tiknix.com store (reads shopdata/ under the app root). */
    private function catalog(): StoreCatalog {
        return new StoreCatalog(dirname(__DIR__));
    }

    /** GET /ecommerce?id=<instance> — storefront tools hub (Payments tie is per-instance). */
    public function index($params = []): void {
        if (!$this->requireFeature()) return;

        $instances = R::find('instance', 'member_id = ? ORDER BY created_at DESC', [(int)$this->member->id]);
        $wantId   = (int)$this->getParam('id', 0);
        $selected = null;
        foreach ($instances as $i) {
            if ($wantId && (int)$i->id === $wantId) { $selected = $i; break; }
        }
        if (!$selected && !$wantId && count($instances)) {
            $selected = $instances[array_key_first($instances)];
        }

        $stripe = null;
        if ($selected) {
            $ad = Flight::get('cachedDatabaseAdapter');
            if ($ad instanceof \app\CachedDatabaseAdapter) $ad->invalidateTable('connections');
            $conns = [];
            foreach (Bean::find('connections',
                'member_id = ? AND instance_id = ? AND connector_type = ? AND enabled = 1',
                [(int)$this->member->id, (int)$selected->id, 'stripe']) as $c) {
                if (!empty($c->revokedAt)) continue;
                $conns[] = ['environment' => $c->environment ?: 'production', 'name' => $c->externalName ?: $c->externalEid];
            }
            $stripe = ['connected' => count($conns) > 0, 'connections' => $conns];
        }

        $sys = defined('SYSTEM_ADMIN_ID') ? SYSTEM_ADMIN_ID : 1;
        $this->render('ecommerce/index', [
            'title'         => 'Ecommerce',
            'instances'     => $instances,
            'selected'      => $selected,
            'stripe'        => $stripe,
            'productCount'  => count($this->catalog()->listProducts()),
            'paymentSource' => [
                'instance' => (int)\Flight::getSetting('shop.payment_instance', $sys),
                'env'      => (string)(\Flight::getSetting('shop.payment_env', $sys) ?: 'production'),
            ],
        ]);
    }

    /** POST /ecommerce/paymentsource — set which instance+environment checkout draws payments from. */
    public function paymentsource($params = []): void {
        if (!$this->requireFeature()) return;
        if (!$this->validateCSRF()) return;
        $instId = (int)$this->getParam('instance', 0);
        $env    = strtolower(trim((string)$this->getParam('env', 'production')));
        if (!in_array($env, ['development', 'production'], true)) $env = 'production';
        $inst = R::load('instance', $instId);
        if (!$inst->id || (int)$inst->memberId !== (int)$this->member->id) {
            $this->jsonError('Choose one of your stores', 400); return;
        }
        $sys = defined('SYSTEM_ADMIN_ID') ? SYSTEM_ADMIN_ID : 1;
        \Flight::setSetting('shop.payment_member', (int)$this->member->id, $sys);
        \Flight::setSetting('shop.payment_instance', $instId, $sys);
        \Flight::setSetting('shop.payment_env', $env, $sys);
        $this->jsonSuccess(['instance' => $instId, 'env' => $env], 'Payment source saved');
    }

    /** GET /ecommerce/orders — recorded (paid) orders from the storefront webhook. */
    public function orders($params = []): void {
        if (!$this->requireFeature()) return;
        $this->render('ecommerce/orders', [
            'title'  => 'Orders',
            'orders' => Bean::find('shoporder', ' ORDER BY created_at DESC LIMIT 200'),
        ]);
    }

    /** GET /ecommerce/products — product list for the tiknix.com store. */
    public function products($params = []): void {
        if (!$this->requireFeature()) return;
        $this->render('ecommerce/products', [
            'title'    => 'Products',
            'products' => $this->catalog()->listProducts(),
        ]);
    }

    /** GET /ecommerce/productedit?sku=<sku> — new/edit product form. */
    public function productedit($params = []): void {
        if (!$this->requireFeature()) return;
        $sku = (string)$this->getParam('sku', '');
        $this->render('ecommerce/product-edit', [
            'title'   => $sku !== '' ? 'Edit product' : 'New product',
            'product' => $sku !== '' ? $this->catalog()->getProduct($sku) : null,
        ]);
    }

    /** POST /ecommerce/productsave — validate + write a product JSON. */
    public function productsave($params = []): void {
        if (!$this->requireFeature()) return;
        if (!$this->validateCSRF()) return;
        try {
            $product = $this->catalog()->saveProduct([
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
        try {
            $rel = $this->catalog()->addProductImage((string)$this->getParam('sku', ''), $_FILES['image'] ?? []);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400); return;
        }
        $this->jsonSuccess(['path' => $rel], 'Image added');
    }

    /** POST /ecommerce/productdelete — remove a product JSON (+ its images). */
    public function productdelete($params = []): void {
        if (!$this->requireFeature()) return;
        if (!$this->validateCSRF()) return;
        $ok = $this->catalog()->deleteProduct((string)$this->getParam('sku', ''));
        $this->jsonSuccess(['deleted' => $ok], 'Product removed');
    }

    // --- catalogs (categories) ------------------------------------------------

    /** GET /ecommerce/categories — catalog (category) list. */
    public function categories($params = []): void {
        if (!$this->requireFeature()) return;
        $this->render('ecommerce/categories', [
            'title'      => 'Catalogs',
            'categories' => $this->catalog()->listCategories(),
        ]);
    }

    /** GET /ecommerce/categoryedit?slug= — new/edit catalog (title + product picker). */
    public function categoryedit($params = []): void {
        if (!$this->requireFeature()) return;
        $slug = (string)$this->getParam('slug', '');
        $this->render('ecommerce/category-edit', [
            'title'    => $slug !== '' ? 'Edit catalog' : 'New catalog',
            'category' => $slug !== '' ? $this->catalog()->getCategory($slug) : null,
            'products' => $this->catalog()->listProducts(),
        ]);
    }

    /** POST /ecommerce/categorysave — write a catalog JSON from picked product slugs. */
    public function categorysave($params = []): void {
        if (!$this->requireFeature()) return;
        if (!$this->validateCSRF()) return;
        $products = $this->getParam('products', []);
        if (!is_array($products)) $products = ($products === '' ? [] : [$products]);
        try {
            $cat = $this->catalog()->saveCategory([
                'slug'     => $this->getParam('slug', ''),
                'title'    => $this->getParam('title', ''),
                'products' => $products,
            ]);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400); return;
        }
        $this->jsonSuccess(['slug' => $cat['slug'], 'category' => $cat], 'Catalog saved');
    }

    /** POST /ecommerce/categorydelete — remove a catalog JSON. */
    public function categorydelete($params = []): void {
        if (!$this->requireFeature()) return;
        if (!$this->validateCSRF()) return;
        $ok = $this->catalog()->deleteCategory((string)$this->getParam('slug', ''));
        $this->jsonSuccess(['deleted' => $ok], 'Catalog removed');
    }
}
