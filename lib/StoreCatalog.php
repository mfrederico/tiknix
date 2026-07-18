<?php
/**
 * StoreCatalog — the file-backed product catalog for one store instance.
 *
 * A store's catalog is plain JSON living under the INSTANCE's public folder, so it
 * deploys with the instance to GitHub and is rendered client-side (Phase 4):
 *
 *   <instance>/public/store/index.json           manifest (fast PLP bootstrap)
 *   <instance>/public/store/products/<sku>.json   one file per product (PDP)
 *   <instance>/public/store/collections/<slug>.json  ordered product list (PLP)
 *   <instance>/public/uploads/products/<sku>/…    product images
 *
 * The product JSON holds the DEFINITION plus inventory intent: `serialized`,
 * `holdMinutes`, and either `stock` (fungible) or `units[]` (serial numbers). Live
 * hold/sold state is tracked in the instance DB by the storefront runtime later —
 * this class only authors the catalog. Image paths are stored RELATIVE
 * ("uploads/products/…") so they resolve on whatever domain the store is published to.
 */

namespace app;

class StoreCatalog {

    private string $publicDir;
    private string $storeDir;
    private string $productsDir;
    private string $collectionsDir;
    private string $uploadsDir;

    /** @param string $instanceDir absolute path to the instance root (…/<slug>.tiknix) */
    public function __construct(string $instanceDir) {
        $this->publicDir      = rtrim($instanceDir, '/') . '/public';
        $this->storeDir       = $this->publicDir . '/store';
        $this->productsDir    = $this->storeDir . '/products';
        $this->collectionsDir = $this->storeDir . '/collections';
        $this->uploadsDir     = $this->publicDir . '/uploads/products';
    }

    /** Normalize a SKU to a safe, lowercase file/url slug. '' if nothing usable. */
    public static function normalizeSku(string $sku): string {
        $sku = strtolower(trim($sku));
        $sku = preg_replace('~[^a-z0-9]+~', '-', $sku);
        return trim((string)$sku, '-');
    }

    public function ensureDirs(): void {
        foreach ([$this->storeDir, $this->productsDir, $this->collectionsDir, $this->uploadsDir] as $d) {
            if (!is_dir($d)) @mkdir($d, 0775, true);
        }
    }

    // --- products -------------------------------------------------------------

    /** All products, newest-updated first. Each is the decoded product array. */
    public function listProducts(): array {
        $out = [];
        foreach (glob($this->productsDir . '/*.json') ?: [] as $f) {
            $p = json_decode((string)@file_get_contents($f), true);
            if (is_array($p) && !empty($p['sku'])) $out[] = $p;
        }
        usort($out, fn($a, $b) => strcmp((string)($b['updatedAt'] ?? ''), (string)($a['updatedAt'] ?? '')));
        return $out;
    }

    public function getProduct(string $sku): ?array {
        $sku = self::normalizeSku($sku);
        if ($sku === '') return null;
        $f = $this->productsDir . '/' . $sku . '.json';
        if (!is_file($f)) return null;
        $p = json_decode((string)@file_get_contents($f), true);
        return is_array($p) ? $p : null;
    }

    /**
     * Validate + persist a product from raw input, merging over any existing file so
     * images/units already stored are not lost. Returns the stored product array.
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

        // Units: newline/comma-separated serials -> [{serial, status:'available'}].
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

        // Caller may replace the whole image list explicitly (e.g. after a delete).
        if (isset($in['images']) && is_array($in['images'])) {
            $product['images'] = array_values(array_filter(array_map('strval', $in['images'])));
        }

        $this->writeJson($this->productsDir . '/' . $sku . '.json', $product);
        $this->writeManifest();
        return $product;
    }

    public function deleteProduct(string $sku): bool {
        $sku = self::normalizeSku($sku);
        if ($sku === '') return false;
        $f = $this->productsDir . '/' . $sku . '.json';
        $ok = is_file($f) ? @unlink($f) : false;
        // Best-effort: drop the product's image folder too.
        $imgDir = $this->uploadsDir . '/' . $sku;
        if (is_dir($imgDir)) { foreach (glob($imgDir . '/*') ?: [] as $g) @unlink($g); @rmdir($imgDir); }
        $this->writeManifest();
        return $ok;
    }

    // --- images ---------------------------------------------------------------

    /**
     * Store an uploaded image under uploads/products/<sku>/ and append its RELATIVE
     * path to the product. Returns the relative path (e.g. "uploads/products/sku/x.jpg").
     * @param array $file a $_FILES entry
     * @throws \Exception on validation failure
     */
    public function addProductImage(string $sku, array $file): string {
        $sku = self::normalizeSku($sku);
        if ($sku === '' || !$this->getProduct($sku)) throw new \Exception('Unknown product.');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new \Exception('No file uploaded.');
        }
        if ((int)($file['size'] ?? 0) > 10 * 1024 * 1024) throw new \Exception('Image too large (max 10MB).');

        $ext = [
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif',
        ][(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])] ?? null;
        if ($ext === null) throw new \Exception('Only PNG, JPEG, WEBP, or GIF images are allowed.');

        $dir = $this->uploadsDir . '/' . $sku;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) throw new \Exception('Could not save the image.');
        @chmod($dest, 0664);

        $rel = 'uploads/products/' . $sku . '/' . $name;   // RELATIVE — no host, no leading slash
        $product = $this->getProduct($sku);
        $product['images'][] = $rel;
        $product['images'] = array_values(array_unique($product['images']));
        $product['updatedAt'] = date('Y-m-d H:i:s');
        $this->writeJson($this->productsDir . '/' . $sku . '.json', $product);
        $this->writeManifest();
        return $rel;
    }

    // --- manifest -------------------------------------------------------------

    /** Rewrite index.json — a compact catalog for the storefront JS to bootstrap. */
    private function writeManifest(): void {
        $products = [];
        foreach ($this->listProducts() as $p) {
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
        $collections = [];
        foreach (glob($this->collectionsDir . '/*.json') ?: [] as $f) {
            $c = json_decode((string)@file_get_contents($f), true);
            if (is_array($c) && !empty($c['slug'])) {
                $collections[] = ['slug' => $c['slug'], 'title' => $c['title'] ?? $c['slug']];
            }
        }
        $this->writeJson($this->storeDir . '/index.json', [
            'updatedAt'   => date('Y-m-d H:i:s'),
            'products'    => $products,
            'collections' => $collections,
        ]);
    }

    private function writeJson(string $path, array $data): void {
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0664);
    }
}
