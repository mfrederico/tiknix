<?php
/**
 * Browserdrive — Playwright-style steps, played in a person's open page instead of a browser on the server.
 *   For people (admins):  GET /browserdrive          the runner: leave it open; it claims sessions and plays their steps
 *                         POST /browserdrive/allow   { paths, scripts }  the sandbox paths a session may open (one per line); whether script may run
 *                         /browserdrive/claim, /next, /done, /runnerjs, /shimjs   what the runner page calls
 *   For agents:          the MCP tool browserdrive_drive (open, step, result, close) at /mcp/message, with an admin's API key
 *                         GET  /browserdrive/client  the Node client (assets/client.js, saved as browserdrive-client.mjs): page.goto, click, fill… through that tool
 */

namespace app\concepts\browserdrive;

use \Flight as Flight;
use app\BaseControls\Control;

class Browserdrive extends Control {

    /** The runner page: a person leaves it open and it plays whatever sessions agents open. */
    public function index($params = []) {
        Session::ready();
        Session::expire();
        $recent = \app\Bean::find('drivesession', ' ORDER BY id DESC LIMIT 15 ');
        $this->render('browserdrive/index', ['title' => 'Browser drive', 'allowed' => Session::allowed(), 'scripts' => Session::scriptsOn(), 'recent' => $recent]);
    }
    public function allow($params = []) {
        if (!$this->validateCSRF()) return;
        try { $list = Session::setAllowed((string) $this->getParam('paths', '')); Session::setScripts((string) $this->getParam('scripts', '') === '1'); $this->flash('success', $list ? 'Sessions may open: ' . implode(', ', $list) : 'No path is allowed: no session can open until one is.'); }
        catch (\InvalidArgumentException $e) { $this->flash('error', $e->getMessage()); }
        Flight::redirect('/browserdrive');
    }

    // ---- what the runner page calls (admins, CSRF) ----
    public function claim($params = []) {
        if (!$this->validateCSRF()) return;
        try { $s = Session::claim((int) $this->member->id); }
        catch (\Throwable $e) { $this->logger->error('browserdrive: a runner could not claim: ' . $e->getMessage()); return $this->fail('this site could not look for sessions: ' . $e->getMessage(), 500); } // the page says so; it does not go on saying it is waiting
        Flight::jsonSuccess($s ? ['session' => (int) $s->id, 'path' => (string) $s->path, 'note' => (string) $s->note] : ['session' => null]);
    }
    public function next($params = []) {
        if (!$this->validateCSRF()) return;
        try { $s = \app\Bean::load('drivesession', (int) $this->getParam('session', 0)); if (!$s->id) return $this->fail('no such session', 404);
            Flight::jsonSuccess(['next' => Session::next($s, (int) $this->member->id, (int) $this->getParam('wait', 20))]); }
        catch (\RuntimeException $e) { $this->fail($e->getMessage(), 409); }
    }
    public function done($params = []) {
        if (!$this->validateCSRF()) return;
        $b = Flight::request()->data->getData();
        try { $s = \app\Bean::load('drivesession', (int) ($b['session'] ?? 0)); if (!$s->id) return $this->fail('no such session', 404);
            Session::done($s, (int) $this->member->id, (int) ($b['step'] ?? 0), (array) ($b['result'] ?? []));
            if (!empty($b['release'])) Session::close($s, (string) $b['release']);
            Flight::jsonSuccess(['ok' => true]); }
        catch (\InvalidArgumentException $e) { $this->fail($e->getMessage(), 400); }
        catch (\RuntimeException $e) { $this->fail($e->getMessage(), 409); }
    }
    public function runnerjs($params = []) { $this->asset('runner.js'); }
    public function shimjs($params = []) { $this->asset('shim.js'); }
    private function asset(string $f): void {
        $body = file_get_contents(dirname(__DIR__) . '/assets/' . $f); if ($body === false) throw new \RuntimeException("browserdrive: cannot read assets/{$f}");
        header('Content-Type: application/javascript; charset=utf-8'); header('Cache-Control: no-cache');
        if ($f === 'client.js') header('Content-Disposition: inline; filename="browserdrive-client.mjs"'); // an ES module: Node runs it as .mjs
        echo $body; exit;
    }

    // ---- for agents: the MCP tool browserdrive_drive (the platform checks their API key at /mcp/message); the Node client calls it ----
    public function client($params = []) { $this->asset('client.js'); }
}
