<?php
/**
 * 63_ChangeRequestFilesAsk.php — a change request's attached files and its dialog with the Builder (lib/SiteChanges.php):
 *   files_json   the files the site kept for the builder (a spreadsheet, Markdown, a look): name, type, size, the path on
 *                the app and the note they belong to. '[]' when none. The bytes stay on the app; the team opens them
 *                through /changerequests/file, which fetches them from the app's container.
 *   prompts      every Ask message this request became (Build it, Open in Ask, each answer and reply after), as
 *                ",12,15," so one LIKE finds the request of a message. '' when none yet.
 * And the route an app asks whether its builder is ready (ROOT's "send it straight to build").
 */
$cols = \app\Bean::inspect('changerequest');
if (!$cols) throw new \RuntimeException('63_ChangeRequestFilesAsk: there is no changerequest table (61_ChangeRequests makes it).');
foreach (['files_json' => "TEXT DEFAULT '[]'", 'prompts' => "TEXT DEFAULT ''"] as $col => $def) {
    if (isset($cols[$col])) { echo "  changerequest: {$col} unchanged\n"; continue; }
    \app\Bean::exec('ALTER TABLE changerequest ADD COLUMN ' . $col . ' ' . $def);   // fixed names, never input
    echo "  changerequest: + {$col}\n";
}
// What became an Ask message before this column: its one prompt_ref.
$n = \app\Bean::exec("UPDATE changerequest SET prompts = ',' || prompt_ref || ',' WHERE (prompts IS NULL OR prompts = '') AND prompt_ref > 0");
if ($n) echo "  changerequest: {$n} request(s) carried their Ask message over\n";
\app\Bean::exec('CREATE INDEX IF NOT EXISTS changerequest_prompts ON changerequest (prompts)');
echo '  authcontrol: brokerinfo::canbuild => ' . \app\PermissionCache::seedRule('brokerinfo', 'canbuild', 101, "Whether a project's builder is ready, for its site's ROOT sending straight to build (broker key)") . "\n";
