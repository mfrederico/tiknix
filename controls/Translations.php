<?php
/**
 * Translations admin — drop-in editor for the translatify i18n package.
 *
 * Admin-only. Reads/writes lang/*.json in this project via Translatify\Editor,
 * and harvests t() strings from source via Translatify\Scanner. The view embeds
 * the package's shipped editor view (vendor/.../views/editor.php).
 *
 * CSRF note: the package editor posts its token as a csrf_token field, so we
 * validate that field against the session token directly rather than through
 * this->validateCSRF() (which expects tiknix's _csrf_token field/header).
 */

namespace app;

use app\BaseControls\Control;
use app\SimpleCsrf;
use Translatify\Editor;
use Translatify\Scanner;
use \Flight as Flight;

class Translations extends Control {

    private const ADMIN_LEVEL = 50; // LEVELS['ADMIN']

    public function __construct() {
        parent::__construct();
        if (!Flight::isLoggedIn()) {
            Flight::redirect('/auth/login?redirect=' . urlencode(Flight::request()->url));
            exit;
        }
        if ($this->member->level > self::ADMIN_LEVEL) {
            Flight::redirect('/');
            exit;
        }
        if (!class_exists('\Translatify\Editor')) {
            Flight::halt(500, 'translatify package is not installed (composer require translatify/translatify).');
        }
    }

    private function langDir(): string {
        return dirname(__DIR__) . '/lang';
    }

    /** Validate the package editor's csrf_token field against the session token. */
    private function checkCsrf(): bool {
        return hash_equals(SimpleCsrf::getToken(), (string)($_POST['csrf_token'] ?? ''));
    }

    /** GET /translations — render the editor grid. */
    public function index($params = []): void {
        $data = (new Editor($this->langDir()))->buildRowData('en');

        $this->viewData['title']        = 'Translations';
        $this->viewData['rows']         = $data['rows'];
        $this->viewData['locales']      = $data['locales'];
        $this->viewData['baseLocale']   = 'en';
        $this->viewData['saveUrl']      = '/translations/save';
        $this->viewData['newLocaleUrl'] = '/translations/newlocale';
        $this->viewData['scanUrl']      = '/translations/scan';
        $this->viewData['csrfToken']    = SimpleCsrf::getToken();
        // Absolute path to the package's shipped editor partial (the view includes it).
        $this->viewData['editorView']   = dirname(__DIR__) . '/vendor/translatify/translatify/views/editor.php';

        $this->render('translations/index', $this->viewData);
    }

    /** POST /translations/save — inline set/remove (JSON in, JSON out). */
    public function save($params = []): void {
        if (!$this->checkCsrf()) { $this->jsonError('CSRF validation failed', 403); return; }

        $action = (string)$this->getParam('action', '');
        $editor = new Editor($this->langDir());

        try {
            if ($action === 'set') {
                $locale      = (string)$this->getParam('locale', '');
                $source      = (string)$this->getParam('source', '');
                $translation = (string)$this->getParam('translation', '');
                if ($source === '' || $locale === '') throw new \InvalidArgumentException('locale and source are required');
                if ($translation === '') $editor->removeKey($locale, $source);
                else                     $editor->setKey($locale, $source, $translation);
                $this->jsonSuccess(['locale' => $locale, 'source' => $source]);
                return;
            }
            if ($action === 'remove_all') {
                $source = (string)$this->getParam('source', '');
                if ($source === '') throw new \InvalidArgumentException('source required');
                foreach ($editor->listLocales() as $code) $editor->removeKey($code, $source);
                $this->jsonSuccess(['source' => $source]);
                return;
            }
            $this->jsonError("Unknown action '{$action}'", 400);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    /** POST /translations/newlocale — create a new locale file. */
    public function newlocale($params = []): void {
        if (!$this->checkCsrf()) { $this->flash('error', 'CSRF validation failed'); Flight::redirect('/translations'); return; }

        $code = trim((string)$this->getParam('locale', ''));
        try {
            (new Editor($this->langDir()))->createLocale($code);
            $this->flash('success', t("Locale ':code' created", ['code' => $code]));
        } catch (\Exception $e) {
            $this->flash('error', $e->getMessage());
        }
        Flight::redirect('/translations');
    }

    /** POST /translations/scan — harvest t() strings from source into en.json. */
    public function scan($params = []): void {
        if (!$this->checkCsrf()) { $this->flash('error', 'CSRF validation failed'); Flight::redirect('/translations'); return; }

        $root = dirname(__DIR__);
        $roots = ["$root/views", "$root/controls", "$root/services", "$root/lib"];
        try {
            $result = (new Scanner())->syncToLocale(new Editor($this->langDir()), $roots, 'en');
            $this->flash('success', t('Scanned source — :total unique strings, :added new key(s) added to en.json', [
                'total' => $result['total'],
                'added' => count($result['added']),
            ]));
        } catch (\Exception $e) {
            $this->flash('error', t('Scan failed: :error', ['error' => $e->getMessage()]));
        }
        Flight::redirect('/translations');
    }
}
