<?php
/**
 * 15_MigrateEngineTokens.php — per-member engine keys → model connections (2026-09-23).
 *
 * Settings → "Your <provider> API key" (settings rows engine.<engine>.auth_token, encrypted
 * with core's app_key) is gone; a member's own key is a model connection now. Each stored
 * key becomes a connection named "<Provider> (migrated)" with that provider's endpoint and
 * model tiers from the engine registry, and the setting row is deleted.
 *
 * NOT chosen as the member's build connection: that would silently move their Claude builds
 * to another provider. They choose it on Connections → Models if they want it.
 *
 * A key that will not decrypt is NOT migrated and NOT deleted: it is reported, so the row
 * stays as evidence and the member can re-enter it. Core only — model connections live on
 * core, and an instance's own members never had these rows.
 */
use \RedBeanPHP\R;

if (is_core_install() && $_tableCheck('modelconnection') && $_tableCheck('settings')) {
    $rows = R::getAll("SELECT id, member_id, setting_key, setting_value FROM settings WHERE setting_key LIKE 'engine.%.auth_token'");
    foreach ($rows as $row) {
        if (!preg_match('/^engine\.([a-z0-9_-]+)\.auth_token$/', (string) $row['setting_key'], $m)) continue;
        $engine = $m[1];
        $mid = (int) $row['member_id'];
        if (trim((string) $row['setting_value']) === '') { R::exec('DELETE FROM settings WHERE id = ?', [(int) $row['id']]); continue; }
        try {
            $key = \app\EncryptionService::decryptWith((string) $row['setting_value'], \app\MemberEnginePrefs::coreKey());
        } catch (\Throwable $e) {
            echo "  ERROR member {$mid}: stored {$engine} key does not decrypt — left in place, not migrated ({$e->getMessage()})\n";
            continue;
        }
        $def = \app\EngineRegistry::def($engine) ?? [];
        $base = rtrim((string) ($def['anthropic_base_url'] ?? ''), '/');
        if ($base === '') {
            echo "  ERROR member {$mid}: engine '{$engine}' declares no anthropic_base_url, so its key cannot become a connection — left in place\n";
            continue;
        }
        $label = (string) ($def['label'] ?? $engine);
        $name = "{$label} (migrated)";
        if ((int) R::getCell('SELECT COUNT(*) FROM modelconnection WHERE member_id = ? AND name = ?', [$mid, $name]) === 0) {
            $c = R::dispense('modelconnection');
            $c->member_id = $mid;
            $c->name = $name;
            $c->preset = isset(\Model_Modelconnection::PRESETS[$engine]) ? $engine : 'custom';
            $c->protocol = 'anthropic';
            $c->base_url = $base;
            $c->auth = 'bearer';
            $c->key_enc = \app\EncryptionService::encryptWith($key, \app\MemberEnginePrefs::coreKey());
            foreach (['planner', 'worker', 'auditor', 'resolver', 'haiku'] as $t) $c->{$t . '_model'} = (string) ($def[$t . '_model'] ?? '');
            $c->allow_pipelines = 0;
            $c->created_at = date('Y-m-d H:i:s');
            $c->updated_at = date('Y-m-d H:i:s');
            R::store($c);
            echo "  migrated member {$mid}'s {$engine} key → model connection '{$name}' (#{$c->id}); not chosen for builds\n";
        }
        R::exec('DELETE FROM settings WHERE id = ?', [(int) $row['id']]);
    }
}
