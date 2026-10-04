<?php
/**
 * /agentsetup was the MCP services page before 2026-10-04 (it set up MCP servers, not agents —
 * a project's agents are on its own AI agents page). Old links and bookmarks land on the page.
 */
namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Agentsetup extends Control {
    public function index($params = []) {
        $q = (string) ($_SERVER['QUERY_STRING'] ?? '');
        Flight::redirect('/mcpsetup' . ($q !== '' ? '?' . $q : ''), 301);
    }
}
