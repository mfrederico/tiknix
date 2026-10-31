<?php
/** Draftreply — an admin throws away an agent's draft on a support ticket (lib/SupportDraft.php). */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Draftreply extends Control {

    // nav: none — the button on a support ticket's page
    public function dismiss($params = []): void {
        if (!$this->validateCSRF()) return;
        $ticket = (int) $this->getParam('ticket', 0);
        $this->flash(SupportDraft::dismiss($ticket) ? 'success' : 'error', SupportDraft::active($ticket) === null ? 'The draft is gone.' : 'The draft could not be removed.');
        Flight::redirect('/contact/view?id=' . $ticket);
    }
}
