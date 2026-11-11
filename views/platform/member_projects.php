<?php
/**
 * The platform's card on the member edit screen: the projects this member owns and those shared with them, each
 * linked to its report. Rendered by the runtime's admin/edit_member through the 'top' name
 * PlatformMemberAdmin::editView() returns. Vars: $editMember (the edit screen's own).
 */
$__projects = \app\PlatformMemberAdmin::projects((int) $editMember->id);
?>

<?= \app\Ui::table([
    'title' => 'Projects', 'count' => 'project', 'rows' => $__projects,
    'columns' => [
        ['label' => 'Project', 'value' => fn($p) => $p['inst']->displayName ?: $p['inst']->slug,
         'sub' => fn($p) => (string) ($p['inst']->ctDomain ?: $p['inst']->slug),
         'url' => fn($p) => '/projectreport/show/' . (int) $p['inst']->id],
        ['label' => 'Theirs', 'value' => fn($p) => $p['role'] === 'owner' ? 'Owner' : 'Shared with them', 'as' => 'badge',
         'tones' => ['Owner' => 'primary', 'Shared with them' => 'secondary']],
        ['label' => 'Counts', 'value' => fn($p) => $p['counts'] ? 'Yes' : 'No', 'as' => 'badge',
         'tones' => ['Yes' => 'success', 'No' => 'secondary']],
        ['label' => 'Status', 'value' => fn($p) => (string) ($p['inst']->status ?: '—'), 'quiet' => true],
        ['label' => 'Last modified', 'value' => fn($p) => $p['last'], 'as' => 'datetime'],
        ['label' => 'Made', 'value' => fn($p) => (string) $p['inst']->createdAt, 'as' => 'date', 'quiet' => true],
    ],
    'empty' => ['title' => 'No projects', 'text' => 'They have not made a project, and none is shared with them.'],
]) ?>

