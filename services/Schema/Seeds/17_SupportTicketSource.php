<?php
/**
 * 17_SupportTicketSource.php — where a support ticket came from, and which project it is about.
 *
 *   contact.source        form (the public page) | app (a signed-in member's Support page) |
 *                         agent (a project's AI agent, with the member's agreement)
 *   contact.project_slug  the project the ticket is about, '' when none
 *
 * lib/Support.php limits agent tickets per member per hour by source, so the column has to
 * exist before the first one arrives. Existing rows came from the public form.
 *
 *   message.email_status  the email copy of an in-app note (NotifyService::emailCopy):
 *                         sent | failed | off — '' when none was attempted
 *   message.email_error   why it did not go out
 */
use \RedBeanPHP\R;

if ($_tableCheck('contact')) {
    $cols = R::inspect('contact');
    if (!array_key_exists('source', $cols)) {
        R::exec("ALTER TABLE contact ADD COLUMN source TEXT DEFAULT 'form'");
        echo "  contact.source added\n";
    }
    if (!array_key_exists('project_slug', $cols)) {
        R::exec("ALTER TABLE contact ADD COLUMN project_slug TEXT DEFAULT ''");
        echo "  contact.project_slug added\n";
    }
}

if ($_tableCheck('message')) {
    $cols = R::inspect('message');
    foreach (['email_status', 'email_error'] as $col) {
        if (!array_key_exists($col, $cols)) {
            R::exec("ALTER TABLE message ADD COLUMN {$col} TEXT DEFAULT ''");
            echo "  message.{$col} added\n";
        }
    }
}
