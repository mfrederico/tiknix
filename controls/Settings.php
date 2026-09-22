<?php
/**
 * Settings — let an instance owner change how their own instance behaves,
 * without handing them a text editor pointed at conf/config.ini.
 *
 * TWO tiers on purpose:
 *
 *   /settings         ADMIN. conf/config.ini in the SAME section editor ROOT uses,
 *                     narrowed to IniFileService::ADMIN_SECTIONS with every secret key
 *                     absent (not masked: absent). saveini() enforces that scope on the
 *                     way in and says what it refused. It replaced a hand-curated form of
 *                     six toggles, which showed no [features] section at all and was a
 *                     second editor for the same file.
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
     * GET /settings — config.ini for an ADMIN: the same section editor ROOT gets, narrowed
     * to IniFileService::ADMIN_SECTIONS with every secret key absent. One editor, two
     * scopes; saveini() enforces the same scope on the way back in.
     */
    public function index(): void {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        $path = IniFileService::resolvePath('config.ini');
        if (!$path) {
            $this->flash('danger', t('conf/config.ini was not found.'));
            Flight::redirect('/dashboard');
            return;
        }
        $isRoot = Flight::hasLevel(LEVELS['ROOT']);
        $parsed = IniFileService::parse($path);
        $this->render('settings/ini-edit', [
            'title'    => t('Settings'),
            'basename' => 'config.ini',
            'parsed'   => $isRoot ? $parsed : IniFileService::adminScope($parsed),
            'writable' => is_writable($path),
            'scope'    => $isRoot ? 'root' : 'admin',
        ]);
    }

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
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        if (!$this->validateCSRF()) return;
        $isRoot   = Flight::hasLevel(LEVELS['ROOT']);
        $basename = (string) ($_POST['file'] ?? '');
        $back     = $isRoot ? '/settings/ini' : '/settings';
        // An ADMIN edits config.ini and nothing else; every other file is ROOT's.
        if (!$isRoot && $basename !== 'config.ini') {
            $this->flash('danger', t('Only conf/config.ini is editable at this level.'));
            Flight::redirect('/settings');
            return;
        }
        $path = IniFileService::resolvePath($basename);
        if (!$path) {
            $this->flash('danger', t('INI file not found.'));
            Flight::redirect($back);
            return;
        }
        if (IniFileService::isExample($basename)) {
            $this->flash('danger', t('Templates cannot be edited.'));
            Flight::redirect($back);
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
        // ADMIN scope on the way in, whatever the form said: sections outside the allowlist
        // and secret keys are refused, and the refusal is shown — a page that quietly
        // dropped part of a save would teach people their settings are flaky.
        if (!$isRoot) {
            $refused = [];
            foreach ($sections as $secName => $secChanges) {
                if (!in_array((string) $secName, IniFileService::ADMIN_SECTIONS, true)) { $refused[] = "[{$secName}]"; unset($sections[$secName]); continue; }
                $secMeta = $parsed['sections'][$secName]['meta'] ?? [];
                foreach (($secChanges['keys'] ?? []) as $k => $v) {
                    $keyMeta = $parsed['sections'][$secName]['keys'][$k]['meta'] ?? [];
                    if (IniFileService::shouldObfuscate((string) $k, $secMeta, $keyMeta)) { $refused[] = "[{$secName}] {$k}"; unset($sections[$secName]['keys'][$k]); }
                }
                if (!empty($secChanges['add']) || !empty($secChanges['delete'])) { $refused[] = "[{$secName}] add/delete keys"; unset($sections[$secName]['add'], $sections[$secName]['delete']); }
            }
            if ($refused) {
                $this->logger->warning('Settings: ADMIN save refused out-of-scope keys', ['keys' => $refused, 'member_id' => $this->member->id]);
                $this->flash('warning', t('Not saved (root only): :keys', ['keys' => implode(', ', $refused)]));
            }
        }
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

        // newSections is ROOT's (adding a whole section); ADMIN's form never offers it.
        if (!$isRoot) $newSections = [];
        $result = IniFileService::save($path, ['sections' => $sections, 'newSections' => $newSections]);
        $editor = $isRoot ? '/settings/iniedit?file=' . urlencode($basename) : '/settings';
        if (!$result['ok']) {
            foreach ($result['errors'] ?? [] as $e) $this->flash('danger', $e);
            Flight::redirect($editor);
            return;
        }

        $this->flash('success', t('Saved :file.', ['file' => $basename]));
        Flight::redirect($editor);
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
