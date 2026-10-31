<?php
/** 58_SupportDrafts.php — agents' draft replies on support tickets (lib/SupportDraft.php), and the admins' button to discard one. */
if (!$_tableCheck('supportdraft')) {
    $b = $_dispensePadding('supportdraft');
    $b->contactRef = 1; $b->reply = str_repeat('x', 40); $b->findings = str_repeat('x', 40); $b->author = str_repeat('x', 40); $b->status = 'draft'; $b->lastMessageRef = 1; $b->createdAt = '2026-01-01 00:00:00'; $b->sentAt = '2026-01-01 00:00:00';
    \app\Bean::store($b); $_defer($b);
}
\app\Bean::exec('CREATE INDEX IF NOT EXISTS index_supportdraft_contact ON supportdraft (contact_ref, status)');
echo '  authcontrol: draftreply::* => ' . \app\PermissionCache::seedRule('draftreply', '*', 50, "Discard an agent's draft reply on a support ticket (admins)") . "\n";
