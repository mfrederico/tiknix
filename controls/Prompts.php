<?php
/**
 * Prompts — your own record of every prompt you have written.
 *
 * The prompts are the build history: a plan's subtasks are the planner's answer, and a
 * terminal session's diffs are the agent's. Kept apart from the answers, they were the one
 * artefact nothing preserved. See app\PromptLog for what is captured and when.
 *
 * STRICTLY PERSONAL. Every query here is bound to $this->member->id and there is no
 * cross-member view, deliberately, including for admins: people type credentials into
 * prompts ("change the admin password to …" is a real line from this system's history), so
 * a prompt log that any admin could browse would be a credential store with a nice UI.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Prompts extends Control {

    public function __construct() {
        parent::__construct();
        if (!Flight::isLoggedIn()) {
            Flight::redirect('/auth/login?redirect=' . urlencode(Flight::request()->url));
            exit;
        }
    }

    /** GET /prompts — the log, newest first, filterable by where the prompt was written. */
    public function index($params = []) {
        $memberId = (int) $this->member->id;

        // Pull in anything typed at the Terminal since the last look. Harvesting on view
        // keeps the log current without a cron, and it is idempotent (each turn carries a
        // uuid), so a refresh imports nothing twice.
        $imported = 0;
        try {
            $h = PromptLog::harvestTerminal($memberId);
            $imported = (int) $h['added'];
            // Writes that FAILED are the case that matters: without this the page shows a
            // short list (or none at all) and reads as "you have not written many prompts".
            if (!empty($h['failed'])) {
                $this->logger->error('Terminal prompt harvest could not write', [
                    'failed' => $h['failed'], 'error' => $h['error'], 'member_id' => $memberId,
                ]);
                $this->viewData['harvestError'] = $h['failed'] . ' terminal prompt(s) could not be saved: ' . $h['error'];
            }
        } catch (\Throwable $e) {
            $this->logger->error('Terminal prompt harvest failed', ['error' => $e->getMessage(), 'member_id' => $memberId]);
            $this->viewData['harvestError'] = $e->getMessage();
        }

        $source = (string) $this->getParam('source', '');
        $q      = trim((string) $this->getParam('q', ''));

        $rows = PromptLog::forMember($memberId, $source, 500);
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = array_values(array_filter($rows, function ($r) use ($needle) {
                return mb_strpos(mb_strtolower((string) $r->body), $needle) !== false
                    || mb_strpos(mb_strtolower((string) $r->title), $needle) !== false;
            }));
        }

        $this->viewData['rows']     = $rows;
        $this->viewData['counts']   = PromptLog::countsForMember($memberId);
        $this->viewData['sources']  = PromptLog::sources();
        $this->viewData['source']   = $source;
        $this->viewData['q']        = $q;
        $this->viewData['imported'] = $imported;

        $this->render('prompts/index', ['title' => 'Prompt Log']);
    }
}
