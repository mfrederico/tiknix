<?php
/**
 * Lead Controller
 * Admin interface for leads captured by the landing-page form (Index::dolead).
 * Leads are just name + email; for richer contact messages see the Contact controller.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;

class Lead extends BaseControls\Control {

    /**
     * Admin: list captured leads (newest first, paginated).
     */
    public function admin() {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $request = Flight::request();
        $page    = max(1, (int)($request->query->page ?? 1));
        $perPage = 25;

        $total  = Bean::count('lead');
        $offset = ($page - 1) * $perPage;
        $leads  = Bean::findAll('lead',
            'ORDER BY created_at DESC LIMIT :limit OFFSET :offset',
            [':limit' => $perPage, ':offset' => $offset]);

        $this->render('lead/admin', [
            'title'   => 'Leads',
            'leads'   => $leads,
            'page'    => $page,
            'total'   => $total,
            'perPage' => $perPage,
        ]);
    }

    /**
     * Admin: delete a lead.
     */
    public function delete() {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        if (!$this->validateCSRF()) return;

        $id   = (int)($this->getParam('id') ?? 0);
        $lead = Bean::load('lead', $id);
        if (!$lead->id) {
            $this->flash('error', 'Lead not found');
        } else {
            Bean::trash($lead);
            $this->flash('success', 'Lead deleted');
        }
        Flight::redirect('/lead/admin');
    }

    /**
     * Admin: export all leads as CSV.
     */
    public function export() {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $leads = Bean::findAll('lead', 'ORDER BY created_at DESC');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="leads-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['First Name', 'Last Name', 'Email', 'Captured']);
        foreach ($leads as $lead) {
            fputcsv($out, [$lead->firstName, $lead->lastName, $lead->email, $lead->createdAt]);
        }
        fclose($out);
        exit;
    }
}
