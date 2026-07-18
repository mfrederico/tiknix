<?php
/**
 * StoreCatalog — the file-backed product catalog for the tiknix.com storefront.
 *
 * The catalog is plain JSON under the site's public/products/ folder, committed to
 * the repo, so it publishes with the site and is rendered client-side by
 * public/products/store.js:
 *
 *   public/products/index.json         manifest (fast PLP bootstrap)
 *   public/products/<sku>.json         one file per product (PDP data)
 *   public/products/media/<sku>/…      product images
 *
 * A product JSON holds the DEFINITION plus inventory intent: `serialized`,
 * `holdMinutes`, and either `stock` (fungible) or `units[]` (serial numbers). Image
 * paths are stored RELATIVE ("media/<sku>/…") so they resolve on whatever domain the
 * store is published to (the storefront sets <base href="/products/">).
 */

namespace app;

class StoreCatalog {

    private string $dir;       // <public>/products
    private string $mediaDir;  // <public>/products/media

    /** @param string $publicDir absolute path to the site's public/ folder */
    public function __construct(string $publicDir) {
        $this->dir      = rtrim($publicDir, '/') . '/products';
        $this->mediaDir = $this->dir . '/media';
    }

    /** Normalize a SKU to a safe, lowercase file/url slug. '' if nothing usable. */
    public static function normalizeSku(string $sku): string {
        $sku = strtolower(trim($sku));
        $sku = preg_replace('~[^a-z0-9]+~', '-', $sku);
        return trim((string)$sku, '-');
    }

    public function ensureDirs(): void {
        foreach ([$this->dir, $this->mediaDir] as $d) {
            if (!is_dir($d)) @mkdir($d, 0775, true);
        }
    }

    // --- products -------------------------------------------------------------

    /** All products, newest-updated first. (index.json manifest is skipped — no sku.) */
    public function listProducts(): array {
        $out = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            if (basename($f) === 'index.json') continue;
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

        $this->writeJson($this->dir . '/' . $sku . '.json', $product);
        $this->writeManifest();
        return $product;
    }

    public function deleteProduct(string $sku): bool {
        $sku = self::normalizeSku($sku);
        if ($sku === '') return false;
        $f = $this->dir . '/' . $sku . '.json';
        $ok = is_file($f) ? @unlink($f) : false;
        $imgDir = $this->mediaDir . '/' . $sku;
        if (is_dir($imgDir)) { foreach (glob($imgDir . '/*') ?: [] as $g) @unlink($g); @rmdir($imgDir); }
        $this->writeManifest();
        return $ok;
    }

    // --- images ---------------------------------------------------------------

    /**
     * Store an uploaded image under media/<sku>/ and append its RELATIVE path to the
     * product. Returns the relative path (e.g. "media/<sku>/x.jpg").
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

        $dir = $this->mediaDir . '/' . $sku;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) throw new \Exception('Could not save the image.');
        @chmod($dest, 0664);

        $rel = 'media/' . $sku . '/' . $name;   // RELATIVE — resolves under <base href="/products/">
        $product = $this->getProduct($sku);
        $product['images'][] = $rel;
        $product['images'] = array_values(array_unique($product['images']));
        $product['updatedAt'] = date('Y-m-d H:i:s');
        $this->writeJson($this->dir . '/' . $sku . '.json', $product);
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
        $this->writeJson($this->dir . '/index.json', [
            'updatedAt' => date('Y-m-d H:i:s'),
            'products'  => $products,
        ]);
    }

    private function writeJson(string $path, array $data): void {
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0664);
    }
}
