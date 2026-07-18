<?php
/**
 * StoreCatalog — the file-backed product catalog behind the /shop front controller.
 *
 * The catalog is JSON, committed to the repo, but NOT under public/ page-route dirs
 * (so Shop.php can own every /shop URL). Shop.php reads these and serves them with
 * cache headers:
 *
 *   shopdata/product/<sku>.json     product data (PDP)
 *   shopdata/catalog/<slug>.json    catalog data (a title + product-slug list)
 *   public/shopmedia/product/<sku>/ product images (served statically)
 *
 * Manifests (index) are built on demand, not written to disk. Image paths are stored
 * as absolute "/shopmedia/product/<sku>/<file>" so they resolve on any domain.
 */

namespace app;

class StoreCatalog {

    /** Hard cap on a product image, independent of PHP's ini limits. */
    public const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    private string $dir;       // <root>/shopdata/product
    private string $catDir;    // <root>/shopdata/catalog
    private string $mediaDir;  // <root>/public/shopmedia/product

    /** @param string $appRoot absolute path to the app root (the dir holding public/, controls/, …) */
    public function __construct(string $appRoot) {
        $root = rtrim($appRoot, '/');
        $this->dir      = $root . '/shopdata/product';
        $this->catDir   = $root . '/shopdata/catalog';
        $this->mediaDir = $root . '/public/shopmedia/product';
    }

    /** Normalize a SKU/slug to a safe, lowercase file/url token. '' if nothing usable. */
    public static function normalizeSku(string $sku): string {
        $sku = strtolower(trim($sku));
        $sku = preg_replace('~[^a-z0-9]+~', '-', $sku);
        return trim((string)$sku, '-');
    }

    /** Effective max upload size in bytes: the smaller of our cap and PHP's ini limits. */
    public static function maxUploadBytes(): int {
        $toBytes = function ($v): int {
            $v = trim((string)$v);
            if ($v === '') return PHP_INT_MAX;
            $n = (int)$v;
            return match (strtolower(substr($v, -1))) {
                'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => (int)$v,
            };
        };
        return (int)min(self::MAX_IMAGE_BYTES, $toBytes(ini_get('upload_max_filesize')), $toBytes(ini_get('post_max_size')));
    }

    public function ensureDirs(): void {
        foreach ([$this->dir, $this->catDir, $this->mediaDir] as $d) {
            if (!is_dir($d)) @mkdir($d, 0775, true);
        }
    }

    // --- products -------------------------------------------------------------

    /** All products, newest-updated first. */
    public function listProducts(): array {
        $out = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            $p = json_decode((string)@file_get_contents($f), true);
            if (is_array($p) && !empty($p['sku'])) $out[] = $p;
        }
        usort($out, fn($a, $b) => strcmp((string)($b['updatedAt'] ?? ''), (string)($a['updatedAt'] ?? '')));
        return $out;
    }

    public function getProduct(string $sku): ?array {
        $sku = self::normalizeSku($sku);
        if ($sku === '') return null;
        $f = $this->dir . '/' . $sku . '.json';
        if (!is_file($f)) return null;
        $p = json_decode((string)@file_get_contents($f), true);
        return is_array($p) ? $p : null;
    }

    /** Compact product manifest for the storefront PLP (built on demand). */
    public function manifest(): array {
        $products = [];
        $updated = '';
        foreach ($this->listProducts() as $p) {
            $updated = max($updated, (string)($p['updatedAt'] ?? ''));  // content-derived -> stable ETag
            $products[] = [
                'sku'        => $p['sku'],
                'title'      => $p['title'],
                'price'      => $p['price'] ?? 0,
                'currency'   => $p['currency'] ?? 'usd',
                'category'   => $p['category'] ?? '',
                'image'      => $p['images'][0] ?? '',
                'serialized' => !empty($p['serialized']),
                'active'     => !empty($p['active']),
            ];
        }
        return ['updatedAt' => $updated, 'products' => $products];
    }

    /**
     * Validate + persist a product, merging over any existing file so images/units
     * already stored are not lost. Returns the stored product array.
     * @throws \Exception on invalid input
     */
    public function saveProduct(array $in): array {
        $this->ensureDirs();
        $sku = self::normalizeSku((string)($in['sku'] ?? ''));
        if ($sku === '') throw new \Exception('A product needs a SKU (letters, numbers, dashes).');
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') throw new \Exception('A product needs a title.');

        $existing = $this->getProduct($sku) ?? [];
        $now = date('Y-m-d H:i:s');
        $serialized  = !empty($in['serialized']);
        $holdMinutes = max(0, (int)($in['holdMinutes'] ?? ($existing['holdMinutes'] ?? 10)));

        $units = $existing['units'] ?? [];
        if ($serialized && isset($in['units'])) {
            $units = [];
            $raw = is_array($in['units']) ? $in['units'] : preg_split('~[\r\n,]+~', (string)$in['units']);
            foreach ($raw as $s) {
                $s = trim((string)(is_array($s) ? ($s['serial'] ?? '') : $s));
                if ($s !== '') $units[] = ['serial' => $s, 'status' => 'available'];
            }
        }

        $product = [
            'sku'           => $sku,
            'title'         => $title,
            'description'   => trim((string)($in['description'] ?? ($existing['description'] ?? ''))),
            'price'         => round((float)($in['price'] ?? ($existing['price'] ?? 0)), 2),
            'currency'      => strtolower(trim((string)($in['currency'] ?? ($existing['currency'] ?? 'usd')))) ?: 'usd',
            'stripePriceId' => trim((string)($in['stripePriceId'] ?? ($existing['stripePriceId'] ?? ''))),
            'category'      => trim((string)($in['category'] ?? ($existing['category'] ?? ''))),
            'images'        => array_values($existing['images'] ?? []),
            'serialized'    => $serialized,
            'holdMinutes'   => $holdMinutes,
            'stock'         => $serialized ? count($units) : max(0, (int)($in['stock'] ?? ($existing['stock'] ?? 0))),
            'units'         => $serialized ? $units : [],
            'active'        => array_key_exists('active', $in) ? !empty($in['active']) : (bool)($existing['active'] ?? true),
            'createdAt'     => $existing['createdAt'] ?? $now,
            'updatedAt'     => $now,
        ];
        if (isset($in['images']) && is_array($in['images'])) {
            $product['images'] = array_values(array_filter(array_map('strval', $in['images'])));
        }
        $this->writeJson($this->dir . '/' . $sku . '.json', $product);
        return $product;
    }

    /**
     * Fulfill one paid unit: mark the next available serialized unit sold, or
     * decrement simple stock; floored at zero, then persisted. Idempotency is the
     * CALLER's job — Shop::recordOrder dedups on the provider session id, so this
     * runs exactly once per order. Deliberately simple (a direct JSON write, no DB
     * inventory ledger): fine for the low order volumes this store targets.
     * Returns ['sku','fulfilled','stock'(remaining),'unit'(serial|null),'oversold'].
     */
    public function fulfill(string $sku): array {
        $sku = self::normalizeSku($sku);
        $product = $sku !== '' ? $this->getProduct($sku) : null;
        if (!$product) return ['sku' => $sku, 'fulfilled' => false, 'stock' => 0, 'unit' => null, 'oversold' => false];

        $now = date('Y-m-d H:i:s');
        $unit = null;
        if (!empty($product['serialized'])) {
            $units = is_array($product['units'] ?? null) ? $product['units'] : [];
            foreach ($units as &$u) {
                if (($u['status'] ?? '') === 'available') {
                    $u['status'] = 'sold';
                    $u['soldAt'] = $now;
                    $unit = (string)($u['serial'] ?? '');
                    break;
                }
            }
            unset($u);
            $product['units'] = $units;
            $product['stock'] = count(array_filter($units, fn($x) => ($x['status'] ?? '') === 'available'));
            $fulfilled = $unit !== null;   // no available unit -> paid but oversold
        } else {
            $have = max(0, (int)($product['stock'] ?? 0));
            $fulfilled = $have > 0;
            $product['stock'] = max(0, $have - 1);
        }
        $product['updatedAt'] = $now;
        $this->writeJson($this->dir . '/' . $sku . '.json', $product);
        return [
            'sku'      => $sku,
            'fulfilled'=> $fulfilled,
            'stock'    => (int)$product['stock'],
            'unit'     => $unit,
            'oversold' => !$fulfilled,
        ];
    }

    public function deleteProduct(string $sku): bool {
        $sku = self::normalizeSku($sku);
        if ($sku === '') return false;
        $f = $this->dir . '/' . $sku . '.json';
        $ok = is_file($f) ? @unlink($f) : false;
        $imgDir = $this->mediaDir . '/' . $sku;
        if (is_dir($imgDir)) { foreach (glob($imgDir . '/*') ?: [] as $g) @unlink($g); @rmdir($imgDir); }
        return $ok;
    }

    // --- images ---------------------------------------------------------------

    /**
     * Store an uploaded image and append its absolute URL to the product. Returns the
     * stored path (e.g. "/shopmedia/product/<sku>/x.jpg" — served statically).
     * @throws \Exception on validation failure
     */
    public function addProductImage(string $sku, array $file): string {
        $sku = self::normalizeSku($sku);
        if ($sku === '' || !$this->getProduct($sku)) throw new \Exception('Unknown product.');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new \Exception('No file uploaded.');
        }
        $max = self::maxUploadBytes();
        if ((int)($file['size'] ?? 0) > $max) throw new \Exception('Image too large (max ' . round($max / 1048576, 1) . 'MB).');
        $ext = [
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif',
        ][(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])] ?? null;
        if ($ext === null) throw new \Exception('Only PNG, JPEG, WEBP, or GIF images are allowed.');

        $dir = $this->mediaDir . '/' . $sku;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new \Exception('Could not save the image.');
        @chmod($dir . '/' . $name, 0664);

        $rel = '/shopmedia/product/' . $sku . '/' . $name;   // absolute, served statically
        $product = $this->getProduct($sku);
        $product['images'][] = $rel;
        $product['images'] = array_values(array_unique($product['images']));
        $product['updatedAt'] = date('Y-m-d H:i:s');
        $this->writeJson($this->dir . '/' . $sku . '.json', $product);
        return $rel;
    }

    // --- categories (catalogs) ------------------------------------------------

    public function listCategories(): array {
        $out = [];
        foreach (glob($this->catDir . '/*.json') ?: [] as $f) {
            $c = json_decode((string)@file_get_contents($f), true);
            if (is_array($c) && !empty($c['slug'])) $out[] = $c;
        }
        usort($out, fn($a, $b) => strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
        return $out;
    }

    public function getCategory(string $slug): ?array {
        $slug = self::normalizeSku($slug);
        if ($slug === '') return null;
        $f = $this->catDir . '/' . $slug . '.json';
        if (!is_file($f)) return null;
        $c = json_decode((string)@file_get_contents($f), true);
        return is_array($c) ? $c : null;
    }

    /** Compact catalog list for the storefront (built on demand). */
    public function categoryManifest(): array {
        $cats = [];
        $updated = '';
        foreach ($this->listCategories() as $c) {
            $updated = max($updated, (string)($c['updatedAt'] ?? ''));  // content-derived -> stable ETag
            $cats[] = ['slug' => $c['slug'], 'title' => $c['title'] ?? $c['slug'], 'count' => count($c['products'] ?? [])];
        }
        return ['updatedAt' => $updated, 'categories' => $cats];
    }

    /**
     * Persist a catalog: a title + an ordered list of product slugs (only slugs that
     * resolve to an existing product are kept). Returns the stored category.
     * @throws \Exception on invalid input
     */
    public function saveCategory(array $in): array {
        $slug = self::normalizeSku((string)($in['slug'] ?? ''));
        if ($slug === '') throw new \Exception('A catalog needs a slug (letters, numbers, dashes).');
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') $title = ucfirst(str_replace('-', ' ', $slug));

        $valid = [];
        foreach ((array)($in['products'] ?? []) as $s) {
            $s = self::normalizeSku((string)$s);
            if ($s !== '' && !in_array($s, $valid, true) && $this->getProduct($s)) $valid[] = $s;
        }
        $existing = $this->getCategory($slug) ?? [];
        $now = date('Y-m-d H:i:s');
        $cat = [
            'slug' => $slug, 'title' => $title, 'products' => $valid,
            'createdAt' => $existing['createdAt'] ?? $now, 'updatedAt' => $now,
        ];
        if (!is_dir($this->catDir)) @mkdir($this->catDir, 0775, true);
        $this->writeJson($this->catDir . '/' . $slug . '.json', $cat);
        return $cat;
    }

    public function deleteCategory(string $slug): bool {
        $slug = self::normalizeSku($slug);
        if ($slug === '') return false;
        $f = $this->catDir . '/' . $slug . '.json';
        return is_file($f) ? @unlink($f) : false;
    }

    private function writeJson(string $path, array $data): void {
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0664);
    }
}
