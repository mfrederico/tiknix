<?php
/**
 * Inventory — derived availability, never stored.
 *
 * The catalog JSON (shopdata/product/*.json) holds STARTING stock: immutable at
 * runtime, committed to the repo, restored on every deploy. Every paid order is a
 * row in `shoporder` (the ledger — reused, not a new table). Availability is
 * computed on read:  available = starting stock − sold rows,  never written back.
 * So a redeploy that restores the catalog JSON never loses the sold count, and
 * there is no runtime file mutation to race on.
 *
 * saveProduct() sets a serialized product's `stock` to its unit count, so
 * `stock − soldCount` gives the right available quantity for BOTH product kinds;
 * the per-serial sold status (for display / allocation) comes from soldSerials().
 */

namespace app;

use app\Bean;
use app\StoreCatalog;
use RedBeanPHP\R;

class Inventory {

    /** Order statuses that consumed a unit (a captured payment did sell it). */
    private const SOLD_STATUSES = ['paid', 'paid-oversold'];

    /** ['?,?', [statuses]] for an IN() clause; params are 0-indexed (array_merge-safe). */
    private static function inClause(): array {
        $ph = implode(',', array_fill(0, count(self::SOLD_STATUSES), '?'));
        return [$ph, self::SOLD_STATUSES];
    }

    /** How many units of $sku have been sold (one per order). */
    public static function soldCount(string $sku): int {
        $sku = StoreCatalog::normalizeSku($sku);
        if ($sku === '') return 0;
        [$ph, $statuses] = self::inClause();
        try {
            return (int) R::count('shoporder', "sku = ? AND status IN ($ph)", array_merge([$sku], $statuses));
        } catch (\Throwable $e) { return 0; }  // ledger table not created yet -> nothing sold
    }

    /** Set of already-sold serials for $sku, as [serial => true] for O(1) lookup. */
    public static function soldSerials(string $sku): array {
        $sku = StoreCatalog::normalizeSku($sku);
        if ($sku === '') return [];
        [$ph, $statuses] = self::inClause();
        $out = [];
        try {
            foreach (Bean::find('shoporder', "sku = ? AND unit_serial != '' AND status IN ($ph)",
                     array_merge([$sku], $statuses)) as $o) {
                $s = (string) $o->unitSerial;
                if ($s !== '') $out[$s] = true;
            }
        } catch (\Throwable $e) { /* ledger table not created yet */ }
        return $out;
    }

    /** Available quantity now (floored at zero) — starting stock minus sold. */
    public static function available(array $product): int {
        return max(0, (int)($product['stock'] ?? 0) - self::soldCount((string)($product['sku'] ?? '')));
    }

    /**
     * Decorate a full product for the storefront: add `available` + `startingStock`
     * and reflect per-unit sold status — WITHOUT mutating the stored `stock`.
     */
    public static function decorate(array $product): array {
        $product['startingStock'] = (int)($product['stock'] ?? 0);
        $product['available']     = self::available($product);
        if (!empty($product['serialized'])) {
            $sold = self::soldSerials((string)($product['sku'] ?? ''));
            $product['units'] = array_map(function ($u) use ($sold) {
                $u['status'] = empty($sold[(string)($u['serial'] ?? '')]) ? 'available' : 'sold';
                return $u;
            }, is_array($product['units'] ?? null) ? $product['units'] : []);
        }
        return $product;
    }

    /** Add `available` to each item of a compact manifest (PLP index.json). */
    public static function decorateManifest(array $manifest): array {
        if (empty($manifest['products']) || !is_array($manifest['products'])) return $manifest;
        $manifest['products'] = array_map(function ($p) {
            $p['available'] = self::available($p);
            return $p;
        }, $manifest['products']);
        return $manifest;
    }

    /** Next unsold serial to allocate to a new order (serialized only), or null. */
    public static function nextSerial(array $product): ?string {
        if (empty($product['serialized'])) return null;
        $sold = self::soldSerials((string)($product['sku'] ?? ''));
        foreach ((is_array($product['units'] ?? null) ? $product['units'] : []) as $u) {
            $s = (string)($u['serial'] ?? '');
            if ($s !== '' && empty($sold[$s])) return $s;
        }
        return null;
    }
}
