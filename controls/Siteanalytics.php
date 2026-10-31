<?php
/**
 * Siteanalytics — tiknix.com's own Google Analytics. Core's /connections is the hub for each PROJECT's connections, so the
 * platform's own site has no gallery to connect from: this page is that one card. Same connector, same store, and the same
 * plugin puts the tag on pages, only for a visitor who said yes.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\services\connectors\ConnectorRegistry;

class Siteanalytics extends Control {

    private function current(): ?object { return ConnectionStore::for('googleanalytics'); }

    public function index($params = []): void {
        $problems = [];
        $conn = $this->current();
        $id = $conn ? (string) (json_decode((string) $conn->metadataJson, true)['measurement_id'] ?? '') : '';
        if (Flight::request()->method === 'POST') {
            if (!$this->validateCSRF()) return;
            $new = trim((string) $this->getParam('measurement_id', ''));
            try {
                $payload = ConnectorRegistry::get('googleanalytics')->validateApiKey('', ['measurement_id' => $new]);
                $payload['auth_type'] = 'api_key';
                if ($conn && (string) $conn->externalEid !== (string) $payload['external_eid']) $this->remove((int) $conn->id);
                ConnectionStore::put('googleanalytics', 'production', $payload);
                $this->flash('success', 'Saved.');
                Flight::redirect('/siteanalytics');
                return;
            } catch (\Exception $e) {
                $problems['measurement_id'] = $e->getMessage();
                $id = $new;
            }
        }
        $this->render('siteanalytics/index', ['title' => 'Google Analytics', 'id' => $id, 'connected' => (bool) $conn, 'problems' => $problems,
            'asking' => Concepts::has('consent') && \app\concepts\consent\Settings::mode() === 'enforce', 'plugin' => Concepts::has('googleanalytics')]);
    }

    /** Stop counting: the connection goes. */
    public function off($params = []): void {
        if (!$this->validateCSRF()) return;
        $conn = $this->current();
        if ($conn) $this->remove((int) $conn->id);
        $this->flash('success', 'Google Analytics is off for this site.');
        Flight::redirect('/siteanalytics');
    }

    private function remove(int $id): void {
        $gone = ConnectionStore::withOwnDb(function () use ($id) { $c = Bean::load('connections', $id); if (!$c->id) return false; Bean::trash($c); return true; }, false);
        if (!$gone) throw new \RuntimeException("the Google Analytics connection #{$id} could not be removed");
    }
}
