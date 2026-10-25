<?php
/**
 * 01_MessagingSchema.php — the plugin's tables: msgcategory (the switches a member has), msgpref (their choices), msgsuppression (addresses
 * that must not be mailed), msgcampaign (a message with a derived audience), msgsend (one row per recipient: the ledger) and msgevent
 * (what happened on it), with the indexes that make "once" true. Idempotent.
 *
 * An install that already has the app's earlier tables (campaign, campaignsend, campaignevent, suppression) must have them RENAMED first,
 * by the app's own seed (166_MessagingMove in the app that grew this plugin): creating empty tables here would strand the rows. That is
 * refused, loudly, below.
 */
use \app\Bean;

foreach (['campaign' => 'msgcampaign', 'campaignsend' => 'msgsend', 'campaignevent' => 'msgevent', 'suppression' => 'msgsuppression'] as $old => $new) {
    if ($_tableCheck($old) && !$_tableCheck($new)) {
        throw new \RuntimeException("01_MessagingSchema: this install still has the table '$old', which the app's seed 166_MessagingMove renames to '$new'. "
            . "Run `php scripts/clitool.php --build` (the app's seeds) before this plugin's seeds; creating an empty '$new' here would strand its rows.");
    }
}

$anyone = Bean::getCell('SELECT id FROM member ORDER BY id LIMIT 1');
if (!$_tableCheck('msgcategory')) {
    $b = $_dispensePadding('msgcategory');
    $b->code = str_repeat('x', 40);
    $b->label = str_repeat('x', 60);
    $b->description = str_repeat('x', 200);
    $b->defaultOn = 1;
    $b->canOptOut = 1;
    $b->channels = str_repeat('x', 40);
    Bean::store($b);
    $_defer($b);
    echo "  created    msgcategory\n";
}
if (!$_tableCheck('msgpref')) {
    if (!$anyone) throw new \RuntimeException('01_MessagingSchema: msgpref.member_id is a foreign key and the member table is empty');
    $b = $_dispensePadding('msgpref');
    $b->memberId = (int) $anyone;
    $b->categoryRef = 1;
    $b->channel = str_repeat('x', 20);
    $b->enabled = 1;
    $b->consentedAt = date('Y-m-d H:i:s');
    Bean::store($b);
    $_defer($b);
    echo "  created    msgpref\n";
}
if (!$_tableCheck('msgsuppression')) {
    $b = $_dispensePadding('msgsuppression');
    $b->email = str_repeat('x', 80);
    $b->reason = str_repeat('x', 30);
    $b->at = date('Y-m-d H:i:s');
    Bean::store($b);
    $_defer($b);
    echo "  created    msgsuppression\n";
}
if (!$_tableCheck('msgcampaign') || !$_tableCheck('msgsend') || !$_tableCheck('msgevent')) {
    if (!$anyone) throw new \RuntimeException('01_MessagingSchema: msgcampaign.sender_id is a foreign key and the member table is empty');
    $c = $_dispensePadding('msgcampaign');
    $c->senderId = (int) $anyone;
    $c->categoryRef = 1;
    $c->scopeKey = str_repeat('x', 60);
    $c->aboutKey = str_repeat('x', 60);
    $c->kind = str_repeat('x', 20);
    $c->subject = str_repeat('x', 150);
    $c->bodyEmail = str_repeat('x', 2000);
    $c->bodyText = str_repeat('x', 2000);
    $c->bodySms = str_repeat('x', 400);
    $c->triggerCode = str_repeat('x', 40);
    $c->triggerDays = 1;
    $c->cadence = str_repeat('x', 20);
    $c->cadenceDays = 1;
    $c->status = str_repeat('x', 20);
    $c->scheduledAt = date('Y-m-d H:i:s');
    $c->sentAt = date('Y-m-d H:i:s');
    $c->createdAt = date('Y-m-d H:i:s');
    $c->updatedAt = date('Y-m-d H:i:s');
    Bean::store($c);
    $s = $_dispensePadding('msgsend');
    $s->msgcampaignId = (int) $c->id;
    $s->memberId = (int) $anyone;
    $s->channel = str_repeat('x', 20);
    $s->token = str_repeat('a', 32);
    $s->providerEid = str_repeat('x', 120);
    $s->links = str_repeat('x', 2000);
    $s->sentAt = date('Y-m-d H:i:s');
    $s->deliveredAt = date('Y-m-d H:i:s');
    $s->bouncedAt = date('Y-m-d H:i:s');
    $s->complainedAt = date('Y-m-d H:i:s');
    $s->firstClickAt = date('Y-m-d H:i:s');
    $s->clicks = 1;
    $s->actedAt = date('Y-m-d H:i:s');
    $s->createdAt = date('Y-m-d H:i:s');
    Bean::store($s);
    $e = $_dispensePadding('msgevent');
    $e->msgsendId = (int) $s->id;
    $e->kind = str_repeat('x', 20);
    $e->at = date('Y-m-d H:i:s');
    $e->meta = str_repeat('x', 500);
    Bean::store($e);
    $_defer($e);
    $_defer($s);
    $_defer($c);
    echo "  created    msgcampaign, msgsend, msgevent\n";
}

// tables an earlier version of the app made lack the columns the plugin added
foreach ([
    'msgpref'     => ['consented_at' => 'DATETIME'],
    'msgcampaign' => ['scope_key' => 'TEXT', 'about_key' => 'TEXT', 'body_sms' => 'TEXT', 'updated_at' => 'DATETIME'],
] as $table => $columns) {
    $have = Bean::inspect($table);
    foreach ($columns as $col => $type) {
        if (array_key_exists($col, $have)) continue;
        Bean::exec("ALTER TABLE $table ADD COLUMN $col $type NULL");
        echo "  $table.$col added\n";
    }
}

Bean::exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_msgcategory_code ON msgcategory (code)');
Bean::exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_msgpref_choice ON msgpref (member_id, category_ref, channel)');
Bean::exec('CREATE INDEX IF NOT EXISTS idx_msgsuppression_email ON msgsuppression (email)');
Bean::exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_msgsend_token ON msgsend (token)');
Bean::exec('CREATE INDEX IF NOT EXISTS idx_msgsend_campaign_member ON msgsend (msgcampaign_id, member_id)');
Bean::exec('CREATE INDEX IF NOT EXISTS idx_msgsend_provider ON msgsend (provider_eid)');
Bean::exec('CREATE INDEX IF NOT EXISTS idx_msgevent_send ON msgevent (msgsend_id, kind, at)');
Bean::exec('CREATE INDEX IF NOT EXISTS idx_msgcampaign_status ON msgcampaign (status, scheduled_at)');
Bean::exec('CREATE INDEX IF NOT EXISTS idx_msgcampaign_scope ON msgcampaign (scope_key)');
echo "  messaging schema ready\n";
