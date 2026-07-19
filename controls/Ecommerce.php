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
use app\Inventory;
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
        $mid = (int)$this->member->id;
        $sid = $selected ? (int)$selected->id : 0;
        // Orders are scoped to the instance they were paid through (recordOrder stamps
        // instance_id from the resolved payment connection), so the hub shows THIS
        // store's orders — switching stores switches the orders shown.
        $this->render('ecommerce/index', [
            'title'         => 'Ecommerce',
            'instances'     => $instances,
            'selected'      => $selected,
            'stripe'        => $stripe,
            'productCount'  => count($this->catalog()->listProducts()),
            'orderCount'    => $sid ? Bean::count('shoporder', 'member_id = ? AND instance_id = ?', [$mid, $sid]) : 0,
            'subscriberCount' => $sid ? Bean::count('shopsubscription', "member_id = ? AND instance_id = ? AND status IN ('active','trialing')", [$mid, $sid]) : 0,
            'paymentSource' => [
                'instance' => (int)\Flight::getSetting('shop.payment_instance', $sys),
                'env'      => (string)(\Flight::getSetting('shop.payment_env', $sys) ?: 'production'),
            ],
            'shipping' => [
                'countries' => (string)(\Flight::getSetting('shop.ship_countries', $sys) ?: 'US'),
                'flat'      => number_format(((int)\Flight::getSetting('shop.ship_flat_cents', $sys)) / 100, 2, '.', ''),
                'label'     => (string)(\Flight::getSetting('shop.ship_label', $sys) ?: 'Standard shipping'),
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

    /** Format a JSON-stored normalized address into a short, escaped multi-line block. */
    private static function fmtAddress($json): string {
        $a = json_decode((string)$json, true);
        if (!is_array($a)) return '';
        $l1 = trim(($a['line1'] ?? '') . ' ' . ($a['line2'] ?? ''));
        $l2 = trim(implode(' ', array_filter([$a['city'] ?? '', $a['state'] ?? '', $a['postal'] ?? ''])));
        $l3 = strtoupper((string)($a['country'] ?? ''));
        return implode('<br>', array_map(fn($s) => htmlspecialchars((string)$s), array_filter([$l1, $l2, $l3])));
    }

    /** POST /ecommerce/shipping — store-wide flat shipping (countries / rate / label). */
    public function shipping($params = []): void {
        if (!$this->requireFeature()) return;
        if (!$this->validateCSRF()) return;
        $sys = defined('SYSTEM_ADMIN_ID') ? SYSTEM_ADMIN_ID : 1;
        // Normalize allowed countries to a clean CSV of 2-letter ISO codes.
        $countries = implode(',', array_values(array_filter(array_map(
            fn($c) => strtoupper(substr(trim((string)$c), 0, 2)),
            explode(',', (string)$this->getParam('countries', 'US'))
        ))) ?: ['US']);
        $cents = (int)round(((float)$this->getParam('flat', 0)) * 100);
        $label = trim((string)$this->getParam('label', 'Standard shipping')) ?: 'Standard shipping';
        \Flight::setSetting('shop.ship_countries', $countries, $sys);
        \Flight::setSetting('shop.ship_flat_cents', max(0, $cents), $sys);
        \Flight::setSetting('shop.ship_label', $label, $sys);
        $this->jsonSuccess(['countries' => $countries, 'flat_cents' => max(0, $cents), 'label' => $label], 'Shipping saved');
    }

    /** GET /ecommerce/orders?instance=<id> — recorded (paid) orders for one store. */
    public function orders($params = []): void {
        if (!$this->requireFeature()) return;
        $mid = (int)$this->member->id;
        $sid = (int)$this->getParam('instance', 0);
        $this->render('ecommerce/orders', [
            'title'      => 'Orders',
            'instanceId' => $sid,
            'orderCount' => $sid
                ? Bean::count('shoporder', 'member_id = ? AND instance_id = ?', [$mid, $sid])
                : Bean::count('shoporder', 'member_id = ?', [$mid]),
        ]);
    }

    /**
     * AJAX feed for the hub orders DataTable (server-side protocol via
     * DataTableResponse). Scoped to the member + the selected store's instance so
     * one operator never sees another's orders. Columns MUST match the <thead> in
     * views/ecommerce/index.php.
     */
    public function ordersdata($params = []): void {
        if (!$this->requireFeature()) return;
        $mid  = (int)$this->member->id;
        $sid  = (int)$this->getParam('instance', 0);
        $full = filter_var($this->getParam('full', false), FILTER_VALIDATE_BOOLEAN);

        $columns = [
            ['db' => 'created_at',   'search' => null],     // 0  When
            ['db' => 'sku',          'search' => 'like'],   // 1  Product
            ['db' => 'email',        'search' => 'like'],   // 2  Customer
            ['db' => 'amount_total', 'search' => null],     // 3  Amount
            ['db' => 'status',       'search' => 'exact'],  // 4  Status
        ];

        $resp = DataTableResponse::build('shoporder', $columns, $this->getParams(), [
            'baseWhere'  => 'member_id = ? AND instance_id = ?',
            'baseParams' => [$mid, $sid],
            'globalCols' => ['sku', 'title', 'email', 'unit_serial', 'ship_name', 'phone'],
            'row' => function (array $r) use ($full): array {
                $cur   = strtoupper((string)($r['currency'] ?? 'usd'));
                $money = $cur . ' ' . number_format(((int)($r['amount_total'] ?? 0)) / 100, 2);
                $unit  = !empty($r['unit_serial'])
                    ? ' <span class="badge bg-info-subtle text-info-emphasis border">' . h($r['unit_serial']) . '</span>' : '';
                $recurring = ($r['billing_type'] ?? '') === 'subscription'
                    ? ' <span class="badge bg-primary-subtle text-primary-emphasis border">' . h($r['billing_interval'] ?: 'recurring') . '</span>' : '';
                $oversold = ($r['status'] ?? '') === 'paid-oversold';
                $tone = $oversold ? 'warning' : 'success';

                // Customer cell: email always; name/phone/ship-to only on the full view.
                $customer = h($r['email'] ?: '—');
                if ($full) {
                    if (!empty($r['customer_name'])) $customer .= '<div class="small text-body-secondary">' . h($r['customer_name']) . '</div>';
                    if (!empty($r['phone']))         $customer .= '<div class="small text-body-secondary">' . h($r['phone']) . '</div>';
                    $ship = self::fmtAddress($r['ship_address'] ?? '');
                    if ($ship !== '') {
                        $customer .= '<div class="small mt-1"><span class="badge bg-secondary-subtle text-secondary-emphasis border">Ship to</span>'
                            . '<div class="text-body-secondary">' . (!empty($r['ship_name']) ? h($r['ship_name']) . '<br>' : '') . $ship . '</div></div>';
                    }
                }
                $amount = '<span class="text-nowrap">' . h($money) . '</span>';
                if ((int)($r['amount_shipping'] ?? 0) > 0) {
                    $amount .= '<div class="small text-body-secondary">incl. ' . h($cur . ' ' . number_format(((int)$r['amount_shipping']) / 100, 2)) . ' ship</div>';
                }
                return [
                    '<span class="small text-body-secondary text-nowrap">' . h($r['created_at'] ?? '') . '</span>',
                    '<div class="fw-semibold">' . h($r['title'] ?: $r['sku']) . '</div>'
                        . '<div class="small text-body-secondary"><code>' . h($r['sku'] ?? '') . '</code>' . $unit . $recurring . '</div>',
                    $customer,
                    $amount,
                    '<span class="badge bg-' . $tone . '-subtle text-' . $tone . '-emphasis border text-capitalize" '
                        . ($oversold ? 'title="Paid but stock was already depleted"' : '') . '>' . h($r['status'] ?? '') . '</span>',
                ];
            },
        ]);
        \Flight::json($resp);
    }

    /** GET /ecommerce/subscribers?instance=<id> — recurring subscriptions for one store. */
    public function subscribers($params = []): void {
        if (!$this->requireFeature()) return;
        $mid = (int)$this->member->id;
        $sid = (int)$this->getParam('instance', 0);
        $this->render('ecommerce/subscribers', [
            'title'       => 'Subscribers',
            'instanceId'  => $sid,
            'activeCount' => $sid
                ? Bean::count('shopsubscription', "member_id = ? AND instance_id = ? AND status IN ('active','trialing')", [$mid, $sid])
                : Bean::count('shopsubscription', "member_id = ? AND status IN ('active','trialing')", [$mid]),
            'total'       => $sid
                ? Bean::count('shopsubscription', 'member_id = ? AND instance_id = ?', [$mid, $sid])
                : Bean::count('shopsubscription', 'member_id = ?', [$mid]),
        ]);
    }

    /**
     * AJAX feed for the subscribers DataTable — the current-state subscription mirror,
     * scoped to member + store instance. Columns MUST match views/ecommerce/subscribers.php.
     */
    public function subscribersdata($params = []): void {
        if (!$this->requireFeature()) return;
        $mid = (int)$this->member->id;
        $sid = (int)$this->getParam('instance', 0);

        $columns = [
            ['db' => 'created_at',         'search' => null],     // 0  Started
            ['db' => 'email',              'search' => 'like'],   // 1  Subscriber
            ['db' => 'sku',                'search' => 'like'],   // 2  Product
            ['db' => 'status',             'search' => 'exact'],  // 3  Status
            ['db' => 'current_period_end', 'search' => null],     // 4  Renews
        ];
        // How a subscription status maps to a badge tone.
        $tones = ['active' => 'success', 'trialing' => 'info', 'past_due' => 'warning',
                  'unpaid' => 'warning', 'canceled' => 'secondary', 'incomplete' => 'secondary'];

        $resp = DataTableResponse::build('shopsubscription', $columns, $this->getParams(), [
            'baseWhere'  => 'member_id = ? AND instance_id = ?',
            'baseParams' => [$mid, $sid],
            'globalCols' => ['email', 'customer_name', 'sku', 'title', 'subscription_id'],
            'row' => function (array $r) use ($tones): array {
                $cur    = strtoupper((string)($r['currency'] ?? 'usd'));
                $amount = $cur . ' ' . number_format(((int)($r['amount'] ?? 0)) / 100, 2);
                $iv     = !empty($r['interval']) ? ' / ' . h((string)$r['interval']) : '';
                $status = (string)($r['status'] ?? '');
                $tone   = $tones[$status] ?? 'secondary';
                $pe     = (int)($r['current_period_end'] ?? 0);
                $renews = $pe > 0 ? date('Y-m-d', $pe) : '—';
                $cancel = !empty($r['cancel_at_period_end'])
                    ? ' <span class="badge bg-warning-subtle text-warning-emphasis border">ending</span>' : '';
                $product = ((string)($r['title'] ?? '')) ?: ((string)($r['sku'] ?? '')) ?: '—';
                return [
                    '<span class="small text-body-secondary text-nowrap">' . h((string)($r['created_at'] ?? '')) . '</span>',
                    '<div>' . h(((string)($r['email'] ?? '')) ?: '—') . '</div>'
                        . (!empty($r['customer_name']) ? '<div class="small text-body-secondary">' . h((string)$r['customer_name']) . '</div>' : ''),
                    '<div class="fw-semibold">' . h($product) . '</div>'
                        . '<div class="small text-body-secondary">' . h($amount) . $iv . '</div>',
                    '<span class="badge bg-' . $tone . '-subtle text-' . $tone . '-emphasis border text-capitalize">' . h($status ?: 'unknown') . '</span>' . $cancel,
                    '<span class="small text-body-secondary text-nowrap">' . $renews . '</span>',
                ];
            },
        ]);
        \Flight::json($resp);
    }

    /** GET /ecommerce/products — product list for the tiknix.com store. */
    public function products($params = []): void {
        if (!$this->requireFeature()) return;
        $this->render('ecommerce/products', [
            'title'    => 'Products',
            'products' => array_map([Inventory::class, 'decorate'], $this->catalog()->listProducts()),
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
                'billingType'   => (string)$this->getParam('billing_type', 'one_time'),
                'billingInterval' => (string)$this->getParam('billing_interval', 'month'),
                'requiresShipping' => filter_var($this->getParam('requires_shipping', '0'), FILTER_VALIDATE_BOOLEAN),
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
