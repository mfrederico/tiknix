<?php
/**
 * Pipeline\ApiKey — per-MEMBER REST keys for pipeline APIs, stored in the INSTANCE's
 * own DB (`pipeapikey`). Only the sha256 HASH is stored; the raw `pk_…` is shown once
 * at mint. A call authenticates AS the key's bound member. Keys are minted by
 * ADMIN/ROOT (the controller gates it); this helper is the mint/verify/list/revoke.
 */

namespace app\Pipeline;

use app\Bean;
use RedBeanPHP\R;

class ApiKey {

    /** Mint a key for $memberId. Returns ['raw'=>pk_…, 'id'=>, 'prefix'=>] (raw shown ONCE). */
    public static function mint(int $memberId, string $label, int $createdBy): array {
        $raw = 'pk_' . bin2hex(random_bytes(20));
        $row = Bean::dispense('pipeapikey');
        $row->memberId  = $memberId;
        $row->keyHash   = hash('sha256', $raw);
        $row->prefix    = substr($raw, 0, 11);          // pk_ + 8 for display
        $row->label     = mb_substr(trim($label), 0, 100);
        $row->createdBy = $createdBy;
        $row->createdAt = date('Y-m-d H:i:s');
        $id = (int) Bean::store($row);
        return ['raw' => $raw, 'id' => $id, 'prefix' => (string) $row->prefix];
    }

    /** Verify a raw key → the member id it acts as, or 0. Touches last_used_at. */
    public static function verify(string $raw): int {
        $raw = trim($raw);
        if (strncmp($raw, 'pk_', 3) !== 0) return 0;
        $row = Bean::findOne('pipeapikey', 'key_hash = ?', [hash('sha256', $raw)]);
        if (!$row || !$row->id || !empty($row->revokedAt)) return 0;
        $row->lastUsedAt = date('Y-m-d H:i:s');
        Bean::store($row);
        return (int) $row->memberId;
    }

    /** All keys (never the raw value): id, prefix, label, member, timestamps, revoked. */
    public static function all(): array {
        $out = [];
        foreach (R::findAll('pipeapikey', 'ORDER BY id DESC') as $r) {
            $out[] = [
                'id' => (int) $r->id, 'prefix' => (string) $r->prefix, 'label' => (string) $r->label,
                'member_id' => (int) $r->memberId, 'created_at' => (string) $r->createdAt,
                'last_used_at' => (string) $r->lastUsedAt, 'revoked' => !empty($r->revokedAt),
            ];
        }
        return $out;
    }

    public static function revoke(int $id): bool {
        $r = R::load('pipeapikey', $id);
        if (!$r->id) return false;
        $r->revokedAt = date('Y-m-d H:i:s');
        Bean::store($r);
        return true;
    }
}
