<?php
/**
 * Settings — let an instance owner change how their own instance behaves,
 * without handing them a text editor pointed at conf/config.ini.
 *
 * TWO tiers on purpose:
 *
 *   /settings         ADMIN. A curated list of toggles (2FA, registration,
 *                     mail). Every field is declared in fields() below, so the
 *                     page can only ever write keys somebody chose to expose.
 *
 *   /settings/ini     ROOT.  The raw round-trip INI editor — every file in
 *                     conf/, every key, plus add/delete/templates.
 *
 * The split is not ceremony. conf/config.ini holds [security] app_key, the
 * EncryptionService key: change it and every value encrypted under the old one
 * becomes unreadable. It also holds mail credentials and the pipeline trigger
 * secret. An instance owner needs to turn 2FA off; they do not need a field
 * that can silently destroy their stored connections.
 *
 * Why this exists at all: 2FA on mileage read as "off" to its owner while
 * running enabled+optional, and pd carried two_factor_auth = false — a key
 * nothing reads, in the wrong section, while policyEnabled() defaults to TRUE
 * when absent. Both were config the operator believed they had set. A form that
 * names the real keys and shows the value actually in force is the fix for that
 * class of problem; a bigger warning comment is not.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\services\Config\IniFileService;

class Settings extends Control {

    /**
     * The curated surface. section/key are the REAL ini coordinates, so what
     * this page writes is what the app reads back — no aliases, no shadow
     * settings table that drifts from the file.
     *
     * Adding a row here is the whole job of exposing a new setting.
     */
    private static function fields(): array {
        return [
            [
                'group' => 'Security',
                'items' => [
                    ['section' => 'security', 'key' => 'two_factor_enabled', 'type' => 'bool',
                     'label'   => 'Two-factor authentication',
                     'hint'    => 'Master switch. Off means no setup and no verification, for everyone.',
                     // policyEnabled() returns true when the key is missing, so the
                     // form has to show ON for an absent key or it would lie.
                     'default' => true],
                    ['section' => 'security', 'key' => 'two_factor_enforce', 'type' => 'bool',
                     'label'   => 'Require 2FA (no skipping)',
                     'hint'    => 'On: eligible admins must set it up. Off: they are prompted but may skip. Ignored entirely when the master switch is off.',
                     'default' => true],
                    ['section' => 'security', 'key' => 'csrf_enabled', 'type' => 'bool',
                     'label'   => 'CSRF protection',
                     'hint'    => 'Leave on outside local development.',
                     'default' => true],
                    ['section' => 'security', 'key' => 'max_login_attempts', 'type' => 'int',
                     'label'   => 'Max login attempts', 'hint' => 'Before lockout.', 'default' => 5,
                     'min'     => 1, 'max' => 100],
                ],
            ],
            [
                'group' => 'Access',
                'items' => [
                    ['section' => 'features', 'key' => 'registration_enabled', 'type' => 'bool',
                     'label'   => 'Public sign-up', 'hint' => 'Off means accounts are created by an admin only.',
                     'default' => true],
                    ['section' => 'features', 'key' => 'email_verification', 'type' => 'bool',
                     'label'   => 'Require email verification', 'hint' => 'New accounts must confirm their address.',
                     'default' => false],
                ],
            ],
            [
                'group' => 'Mail',
                'items' => [
                    ['section' => 'mail', 'key' => 'enabled', 'type' => 'bool',
                     'label'   => 'Outbound email', 'hint' => 'Off suppresses all sending. Credentials live in the raw editor.',
                     'default' => false],
                ],
            ],
        ];
    }

    /**
     * GET /settings — the curated form.
     */
    public function index(): void {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $path = IniFileService::resolvePath('config.ini');
        if (!$path) {
            $this->flash('danger', t('conf/config.ini was not found.'));
            Flight::redirect('/dashboard');
            return;
        }

        $this->render('settings/index', [
            'title'    => t('Settings'),
            'groups'   => self::hydrate($path),
            'writable' => is_writable($path),
            'isRoot'   => Flight::hasLevel(LEVELS['ROOT']),
        ]);
    }

    /**
     * Read the current value of every declared field straight from the file.
     *
     * Deliberately re-parsed rather than read from Flight's config: the point of
     * the page is to show what the FILE says, and a stale runtime cache is
     * exactly how somebody ends up believing a setting they never applied.
     */
    private static function hydrate(string $path): array {
        $parsed = IniFileService::parse($path);
        $groups = [];

        foreach (self::fields() as $group) {
            $items = [];
            foreach ($group['items'] as $f) {
                $raw     = $parsed['sections'][$f['section']]['keys'][$f['key']]['value'] ?? null;
                $present = $raw !== null;

                if ($f['type'] === 'bool') {
                    $value = $present ? self::truthy($raw) : (bool) ($f['default'] ?? false);
                } else {
                    $value = $present ? $raw : ($f['default'] ?? '');
                }

                $items[] = $f + ['value' => $value, 'present' => $present];
            }
            $groups[] = ['group' => $group['group'], 'items' => $items];
        }
        return $groups;
    }

    /** Same truth table the app itself uses for ini booleans. */
    private static function truthy($v): bool {
        if (is_bool($v)) return $v;
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * POST /settings/save — write the curated form back.
     */
    public function save(): void {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        if (!$this->validateCSRF()) return;

        $path = IniFileService::resolvePath('config.ini');
        if (!$path) {
            $this->flash('danger', t('conf/config.ini was not found.'));
            Flight::redirect('/settings');
            return;
        }

        $posted  = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
        $sections = [];
        $changed  = 0;

        // Index current state so we only write what actually moved. Rewriting
        // every key on every save would churn the file (and its comments), which
        // makes a diff useless for spotting a real change.
        $now = [];
        foreach (self::hydrate($path) as $g) {
            foreach ($g['items'] as $it) { $now[$it['section'] . '.' . $it['key']] = $it; }
        }

        foreach (self::fields() as $group) {
            foreach ($group['items'] as $f) {
                $id  = $f['section'] . '.' . $f['key'];
                $cur = $now[$id] ?? null;

                if ($f['type'] === 'bool') {
                    // An unchecked checkbox posts nothing at all, which is the
                    // difference between "off" and "absent" — and absent means
                    // the app falls back to its default, which for 2FA is ON.
                    // So a bool is always written explicitly, never left out.
                    $new = isset($posted[$id]) ? 'true' : 'false';
                    $old = ($cur && $cur['present']) ? (self::truthy($cur['value']) ? 'true' : 'false') : null;
                } else {
                    $new = trim((string) ($posted[$id] ?? ''));
                    if ($new === '') continue;
                    if ($f['type'] === 'int') {
                        if (!ctype_digit($new)) {
                            $this->flash('danger', t(':label must be a whole number.', ['label' => $f['label']]));
                            Flight::redirect('/settings');
                            return;
                        }
                        $n = (int) $new;
                        if ((isset($f['min']) && $n < $f['min']) || (isset($f['max']) && $n > $f['max'])) {
                            $this->flash('danger', t(':label is out of range.', ['label' => $f['label']]));
                            Flight::redirect('/settings');
                            return;
                        }
                    }
                    $old = ($cur && $cur['present']) ? (string) $cur['value'] : null;
                }

                if ($old !== null && $old === $new) continue;   // untouched
                $sections[$f['section']]['keys'][$f['key']] = $new;
                $changed++;
            }
        }

        if ($changed === 0) {
            $this->flash('info', t('Nothing changed.'));
            Flight::redirect('/settings');
            return;
        }

        $result = IniFileService::save($path, ['sections' => $sections, 'newSections' => []]);
        if (!$result['ok']) {
            foreach ($result['errors'] ?? [] as $e) $this->flash('danger', $e);
            Flight::redirect('/settings');
            return;
        }

        $this->logger->info('Settings updated via curated editor', [
            'member_id' => $this->member->id ?? null,
            'changed'   => array_keys($sections),
        ]);
        $this->flash('success', t('Saved. :n setting(s) updated.', ['n' => $changed]));
        Flight::redirect('/settings');
    }

    // ---------------------------------------------------------------- raw editor

    /**
     * GET /settings/ini — every ini file in conf/. ROOT only.
     */
    public function ini(): void {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        $this->render('settings/ini-list', [
            'title' => t('Configuration Files'),
            'files' => IniFileService::listFiles(),
        ]);
    }

    /**
     * GET /settings/iniedit?file=<basename>
     * Query string, not a path segment: filenames contain dots and the
     * auto-router splits on them.
     */
    public function iniedit($params = []): void {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;

        $basename = (string) $this->getParam('file', '');
        $path = IniFileService::resolvePath($basename);
        if (!$path) {
            $this->flash('danger', t('INI file not found.'));
            Flight::redirect('/settings/ini');
            return;
        }
        if (IniFileService::isExample($basename)) {
            $this->flash('warning', t('That file is a template — create a real config from it instead.'));
            Flight::redirect('/settings/ini');
            return;
        }

        $this->render('settings/ini-edit', [
            'title'    => t('Edit :file', ['file' => $basename]),
            'basename' => $basename,
            'parsed'   => IniFileService::parse($path),
            'writable' => is_writable($path),
        ]);
    }

    /**
     * POST /settings/saveini — the raw editor's writer.
     */
    public function saveini(): void {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        if (!$this->validateCSRF()) return;

        $basename = (string) ($_POST['file'] ?? '');
        $path = IniFileService::resolvePath($basename);
        if (!$path) {
            $this->flash('danger', t('INI file not found.'));
            Flight::redirect('/settings/ini');
            return;
        }
        if (IniFileService::isExample($basename)) {
            $this->flash('danger', t('Templates cannot be edited.'));
            Flight::redirect('/settings/ini');
            return;
        }

        $sections = is_array($_POST['sections'] ?? null) ? $_POST['sections'] : [];
        // Top-of-file keys parse under section '', but an HTML name of
        // sections[][...] collapses to sections[0] in PHP — the view emits the
        // sentinel __top__ instead, translated back here.
        if (isset($sections['__top__'])) {
            $sections[''] = $sections['__top__'];
            unset($sections['__top__']);
        }

        $parsed = IniFileService::parse($path);
        foreach ($sections as $secName => &$secChanges) {
            $secMeta = $parsed['sections'][$secName]['meta'] ?? [];

            // A blank obfuscated field means "leave it alone". Without this, an
            // admin who edits one visible key saves the masked placeholder over
            // every secret on the page.
            if (isset($secChanges['keys']) && is_array($secChanges['keys'])) {
                foreach ($secChanges['keys'] as $k => $v) {
                    $keyMeta = $parsed['sections'][$secName]['keys'][$k]['meta'] ?? [];
                    if ($v === '' && IniFileService::shouldObfuscate($k, $secMeta, $keyMeta)) {
                        unset($secChanges['keys'][$k]);
                    }
                }
            }

            // The "Add key" UI posts parallel name/value arrays.
            $names  = $secChanges['newKeyNames']  ?? [];
            $values = $secChanges['newKeyValues'] ?? [];
            if (is_array($names) && is_array($values)) {
                $merged = [];
                for ($i = 0, $n = min(count($names), count($values)); $i < $n; $i++) {
                    $k = trim((string) $names[$i]);
                    if ($k !== '') $merged[$k] = (string) $values[$i];
                }
                if ($merged) $secChanges['newKeys'] = $merged;
            }
            unset($secChanges['newKeyNames'], $secChanges['newKeyValues']);

            // Per-key validation metadata arrives as a JSON blob per key.
            $metaJsonMap = $secChanges['keyMetaJson'] ?? [];
            if (is_array($metaJsonMap) && $metaJsonMap) {
                $parsedMeta = [];
                foreach ($metaJsonMap as $k => $jsonStr) {
                    $jsonStr = trim((string) $jsonStr);
                    $decoded = $jsonStr === '' ? [] : json_decode($jsonStr, true);
                    $parsedMeta[$k] = is_array($decoded) ? $decoded : [];
                }
                $secChanges['keyMeta'] = $parsedMeta;
            }
            unset($secChanges['keyMetaJson']);

            if (isset($secChanges['deletes']) && is_array($secChanges['deletes'])) {
                $secChanges['deletes'] = array_values(array_filter(
                    array_map('strval', $secChanges['deletes']),
                    static fn($k) => $k !== ''
                ));
                // Delete beats a simultaneous edit of the same key.
                $delSet = array_flip($secChanges['deletes']);
                foreach (['keys', 'keyMeta'] as $bucket) {
                    if (!isset($secChanges[$bucket]) || !is_array($secChanges[$bucket])) continue;
                    foreach (array_keys($secChanges[$bucket]) as $k) {
                        if (isset($delSet[$k])) unset($secChanges[$bucket][$k]);
                    }
                }
            }
        }
        unset($secChanges);

        $newSections = [];
        foreach ((is_array($_POST['newSections'] ?? null) ? $_POST['newSections'] : []) as $secName => $payload) {
            $secName = trim((string) $secName);
            if ($secName === '') continue;
            $names  = $payload['newKeyNames']  ?? [];
            $values = $payload['newKeyValues'] ?? [];
            $kvp = [];
            if (is_array($names) && is_array($values)) {
                for ($i = 0, $n = min(count($names), count($values)); $i < $n; $i++) {
                    $k = trim((string) $names[$i]);
                    if ($k !== '') $kvp[$k] = (string) $values[$i];
                }
            }
            $newSections[$secName] = $kvp;
        }

        $result = IniFileService::save($path, ['sections' => $sections, 'newSections' => $newSections]);
        if (!$result['ok']) {
            foreach ($result['errors'] ?? [] as $e) $this->flash('danger', $e);
            Flight::redirect('/settings/iniedit?file=' . urlencode($basename));
            return;
        }

        $this->flash('success', t('Saved :file.', ['file' => $basename]));
        Flight::redirect('/settings/iniedit?file=' . urlencode($basename));
    }

    /**
     * POST /settings/initemplate — copy an example template into its real sibling.
     */
    public function initemplate(): void {
        if (!$this->requireLevel(LEVELS['ROOT'])) return;
        if (!$this->validateCSRF()) return;

        $result = IniFileService::createFromTemplate((string) ($_POST['file'] ?? ''));
        if (!$result['ok']) {
            $this->flash('danger', $result['error'] ?? t('Could not create from template.'));
            // Already exists? Send them to edit that one instead of nowhere.
            if (!empty($result['basename'])) {
                Flight::redirect('/settings/iniedit?file=' . urlencode($result['basename']));
                return;
            }
            Flight::redirect('/settings/ini');
            return;
        }

        $this->flash('success', t('Created :file.', ['file' => $result['basename']]));
        Flight::redirect('/settings/iniedit?file=' . urlencode($result['basename']));
    }
}
