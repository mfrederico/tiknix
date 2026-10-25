<?php
/**
 * 56_MessagingTiknix.php — Tiknix's own kinds of message and its nudges, on the messaging plugin
 * (concepts/messaging; its seeds make the tables and the two kinds every app has, transactional
 * and reminders). Idempotent: a category is updated in place; a campaign that exists is left as
 * the admin edited it on /messaging.
 *
 * The nudges start PAUSED: they are sent to real members the hour they go active, so the admin
 * reads and edits them on /messaging first, then switches them on. Nothing here runs until
 * the plugin is enabled (concepts.lock).
 */
use app\Bean;

if (!class_exists('\app\Concepts') || !\app\Concepts::has('messaging')) { echo "  messaging plugin not enabled: nothing to seed\n"; return; }
\app\Concepts::boot(\app\Paths::root());
if (!$_tableCheck('msgcategory')) throw new \RuntimeException('56_MessagingTiknix: the messaging plugin has not made its tables yet (run its seeds: --concept-seeds=messaging)');

// 1. Tiknix's kinds of message. The member's words, not ours: what each one is for.
\Model_Msgcategory::register('technical', 'Technical notices', 'Changes to the platform that affect your projects: new runtime releases, a plugin you use, something you must act on', true, true, 'email');
\Model_Msgcategory::register('marketing', 'News from Tiknix', 'What is new on Tiknix and what people build with it; a nudge when we have not seen you for a while', true, true, 'email,text');
echo "  msgcategory technical, marketing ready\n";

// 2. The nudges, as campaign rows the admin edits on /messaging. Paused until an admin reads them.
$sender = (int) Bean::getCell('SELECT id FROM member WHERE level <= 50 ORDER BY level, id LIMIT 1');
if ($sender <= 0) throw new \RuntimeException('56_MessagingTiknix: no admin member to be the sender of the nudges');
$cat = fn(string $code) => (int) (Bean::findOne('msgcategory', 'code = ?', [$code])?->id ?? 0);
$rows = [
    ['trigger_code' => 'no_project_days', 'trigger_days' => 7, 'cadence' => 'once', 'cadence_days' => null, 'category' => 'reminders',
     'subject' => 'Your first app is one sentence away',
     'body_email' => "<p>Hi {first_name|there}, you signed up for Tiknix a week ago and have not started a project yet.</p>{content_html|}",
     'body_text' => "Hi {first_name|there}, you signed up for Tiknix a week ago and have not started a project yet.\n\n{content_text|}"],
    ['trigger_code' => 'inactive_days', 'trigger_days' => 30, 'cadence' => 'every_n_days', 'cadence_days' => 60, 'category' => 'marketing',
     'subject' => 'We have not seen you in a while, {first_name|friend}',
     'body_email' => "<p>Hi {first_name|there}, it has been a month since you last signed in to Tiknix. Your projects are where you left them.</p>{content_html|}",
     'body_text' => "Hi {first_name|there}, it has been a month since you last signed in to Tiknix. Your projects are where you left them.\n\n{content_text|}"],
];
foreach ($rows as $r) {
    if (Bean::findOne('msgcampaign', 'kind = ? AND trigger_code = ?', ['trigger', $r['trigger_code']])) { echo "  kept nudge {$r['trigger_code']}\n"; continue; }
    $m = Bean::dispense('msgcampaign');
    $m->senderId = $sender;
    $m->categoryRef = $cat($r['category']);
    if ($m->categoryRef <= 0) throw new \RuntimeException("56_MessagingTiknix: no msgcategory '{$r['category']}' for the {$r['trigger_code']} nudge");
    $m->kind = 'trigger';
    $m->subject = $r['subject'];
    $m->bodyEmail = $r['body_email'];
    $m->bodyText = $r['body_text'];
    $m->triggerCode = $r['trigger_code'];
    $m->triggerDays = $r['trigger_days'];
    $m->cadence = $r['cadence'];
    $m->cadenceDays = $r['cadence_days'];
    $m->status = 'paused';
    $m->createdAt = date('Y-m-d H:i:s');
    Bean::store($m);
    echo "  added nudge {$r['trigger_code']} (#{$m->id}, paused)\n";
}

// 3. Replies to Tiknix's mail come back through its Mailgun domain (a route reply-(.*)@ there → /webhook/mailgun, which the
// admin adds in Mailgun, with the key entered on /messaging). The plugin sends nothing while this is empty.
if (trim((string) \Flight::getSetting(\app\concepts\messaging\Campaigns::REPLY_DOMAIN_SETTING, 0)) === '') {
    \Flight::setSetting(\app\concepts\messaging\Campaigns::REPLY_DOMAIN_SETTING, 'notify.tiknix.com', 0);
    echo "  setting messaging_reply_domain = notify.tiknix.com\n";
}
