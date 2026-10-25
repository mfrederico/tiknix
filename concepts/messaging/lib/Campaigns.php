<?php
namespace app\concepts\messaging;

use app\Bean;
use app\Mailer;
use app\Sms;
use app\Sites;

/**
 * Campaigns — a message with a DERIVED audience. Every recipient gets a msgsend row (the ledger), and everything that happens
 * afterwards is a msgevent on that row. There is no recipient list anywhere: audience() is a question answered fresh from the
 * audience provider, the member's switches (Reach::allows) and what was already sent.
 */
class Campaigns {

    /** An action counts as the campaign's doing when it follows a click this closely. */
    public const ACT_WINDOW = 172800;
    /** An owner's messages to one audience per rolling 7 days, unless the setting says otherwise. */
    public const DEFAULT_WEEKLY_CAP = 2;
    public const CAP_SETTING = 'community_sends_per_week';
    /** Replies to a campaign go to reply-{token}@ this domain (the Mailgun route forwards them to /webhook/mailgun). */
    public const REPLY_DOMAIN_SETTING = 'messaging_reply_domain';
    /** In an install with several sites, campaign mail is sent through the connection of the site that answers to this domain. */
    public const SITE_DOMAIN_SETTING = 'messaging_site_domain';

    /** @return int[] member ids who should get this campaign now on this channel (email, or text for those who asked for texts) */
    public static function audience($campaign, string $channel = 'email'): array {
        $code = self::categoryCode($campaign);
        $scope = (string) $campaign->scopeKey !== '' ? (string) $campaign->scopeKey : null;
        $about = (string) $campaign->aboutKey !== '' ? (string) $campaign->aboutKey : null;
        if ($code === Reach::REMINDERS && $about !== null) {
            // a reminder goes to the people who said they are going, whatever the audience's own switch is
            $scope = null;
            $ids = self::reachable(Providers::need('audiences')::members($about));
        } elseif ($scope !== null) {
            $ids = self::reachable(Providers::need('audiences')::members($scope));
        } else {
            $ids = self::reachable(null);
        }
        if ((string) $campaign->cadence === 'every_n_days') {
            $since = date('Y-m-d H:i:s', time() - (int) $campaign->cadenceDays * 86400);
            $rows = Bean::getCol('SELECT member_id FROM msgsend WHERE msgcampaign_id = ? AND channel = ? AND created_at > ?', [(int) $campaign->id, $channel, $since]);
        } else {
            $rows = Bean::getCol('SELECT member_id FROM msgsend WHERE msgcampaign_id = ? AND channel = ?', [(int) $campaign->id, $channel]);
        }
        $sent = [];
        foreach ($rows as $r) $sent[(int) $r] = true;
        $out = [];
        foreach ($ids as $id) {
            if (isset($sent[$id])) continue;
            if (Reach::allows($id, $code, $channel, $scope, true)) $out[] = $id;
        }
        return $out;
    }

    /** Of these member ids (null = everyone), the ones who can be written to: active, with an address. */
    private static function reachable(?array $ids): array {
        $sql = "SELECT id FROM member WHERE status = 'active' AND email IS NOT NULL AND email <> ''";
        if ($ids === null) return array_map('intval', Bean::getCol($sql . ' ORDER BY id'));
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (Bean::getCol($sql . " AND id IN ($in) ORDER BY id", $chunk) as $id) $out[] = (int) $id;
        }
        return $out;
    }

    private static function categoryCode($campaign): string {
        $cat = Bean::load('msgcategory', (int) $campaign->categoryRef);
        if (!$cat->id) throw new \RuntimeException("campaign #{$campaign->id}: category_ref {$campaign->categoryRef} is not a message category");
        return (string) $cat->code;
    }

    private static function scopeOf($campaign): ?string {
        return (string) $campaign->scopeKey !== '' ? (string) $campaign->scopeKey : null;
    }

    /**
     * The subject, HTML and text one member gets. With a $token the links are rewritten to /msgtrack/l/{token}/{n} and an open
     * pixel is added; without one (a preview) they are left alone.
     * $extra fills tokens only one member's message has (the digest): 'vars' plain values (escaped in the HTML), 'html' ready HTML
     * for the HTML part, 'text' plain text for the text part.
     * @return array{subject:string,html:string,text:string,links:string[],unsubscribe_url:string}
     */
    public static function render($campaign, int $memberId, ?string $token = null, array $extra = []): array {
        $unsub = Reach::unsubscribeUrl($memberId, self::categoryCode($campaign), self::scopeOf($campaign));
        $bag = self::bag($campaign, $memberId, $unsub);
        $bag = array_merge($bag, $extra['vars'] ?? []);
        $esc = array_merge(self::escaped($bag), $extra['html'] ?? []);
        $textBag = array_merge($bag, $extra['text'] ?? []);
        $subject = self::fill($campaign, (string) $campaign->subject, $bag);
        $html = self::fill($campaign, (string) $campaign->bodyEmail, $esc);
        $text = trim((string) $campaign->bodyText) !== '' ? self::fill($campaign, (string) $campaign->bodyText, $textBag)
            : trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>#i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $text .= "\n\nUnsubscribe: " . $unsub;
        $html .= '<p style="font-size:12px;color:#6c757d"><a href="' . htmlspecialchars($unsub) . '">Unsubscribe</a></p>';

        $links = [];
        if ($token !== null) {
            $html = preg_replace_callback('/(<a\b[^>]*?\bhref=")(https?:\/\/[^"]+)(")/i', function ($m) use (&$links, $token, $unsub) {
                $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
                if ($url === $unsub) return $m[0];
                $links[] = $url;
                return $m[1] . htmlspecialchars(self::base() . '/msgtrack/l/' . $token . '/' . (count($links) - 1)) . $m[3];
            }, $html);
            $html .= '<img src="' . htmlspecialchars(self::base() . '/msgtrack/o/' . $token . '.gif') . '" width="1" height="1" alt="" style="display:none">';
        }
        return ['subject' => $subject, 'html' => $html, 'text' => $text, 'links' => $links, 'unsubscribe_url' => $unsub];
    }

    /** The audience provider's values for a key (the audience, or the thing the message is about); [] when the provider offers none. */
    private static function keyVars(?string $key): array {
        if ($key === null || !Providers::can('audiences', 'vars')) return [];
        return Providers::need('audiences')::vars($key);
    }

    /** ['community' => ['name' => 'x']] -> ['community.name'] */
    private static function flatten(array $a, string $prefix = ''): array {
        $out = [];
        foreach ($a as $k => $v) {
            if (is_array($v)) array_push($out, ...self::flatten($v, $prefix . $k . '.'));
            else $out[] = $prefix . $k;
        }
        return $out;
    }

    /**
     * The {variables} a field of this campaign may use: the member and unsubscribe address, whatever the audience provider offers for
     * the campaign's audience and subject, and for the platform's own messages the headline and the member's news (content_html in
     * the email, content_text in the text).
     * @param string $field 'subject' | 'body_email' | 'body_text'
     * @return string[]
     */
    public static function variables($campaign, string $field): array {
        if (!in_array($field, ['subject', 'body_email', 'body_text'], true)) throw new \InvalidArgumentException("Campaigns::variables: '$field' is not a message field");
        $v = ['first_name', 'unsubscribe_url'];
        array_push($v, ...self::flatten(self::keyVars(self::scopeOf($campaign))));
        array_push($v, ...self::flatten(self::keyVars((string) $campaign->aboutKey !== '' ? (string) $campaign->aboutKey : null)));
        if (in_array((string) $campaign->kind, ['digest', 'trigger'], true)) {
            $v[] = 'headline';
            if ($field === 'body_email') $v[] = 'content_html';
            if ($field === 'body_text') $v[] = 'content_text';
        }
        return array_values(array_unique($v));
    }

    /** The {names} in $text that this campaign's $field cannot fill. {name|default} counts by its name. @return string[] */
    public static function unknownVariables($campaign, string $field, string $text): array {
        preg_match_all('/\{([a-zA-Z0-9_.\-]+)(?:\|[^}]*)?\}/', $text, $m);
        return array_values(array_unique(array_diff($m[1], self::variables($campaign, $field))));
    }

    /**
     * A test copy for one member (an admin): rendered exactly as the runner renders it ($extra is what Digest::previewExtra gives), with no
     * tracking, no ledger row and no reply token. Sent through the campaign mail connection; with none, only rendered.
     * @return array{sent:bool,mail_ready:bool,subject:string,html:string,text:string}
     */
    public static function sendTest($campaign, int $memberId, array $extra = []): array {
        $r = self::render($campaign, $memberId, null, $extra);
        $ready = self::mailer();
        $sent = false;
        if ($ready) {
            $member = Bean::load('member', $memberId);
            $m = Mailer::create()->to((string) $member->email, trim((string) $member->firstName))->subject('[Test] ' . $r['subject']);
            $sent = $m->send($r['html'], $r['text']);   // Mailer logged why when false
        }
        return ['sent' => $sent, 'mail_ready' => $ready, 'subject' => $r['subject'], 'html' => $r['html'], 'text' => $r['text']];
    }

    /** The values a message's {tokens} are filled from: the member's first name, the unsubscribe address, and what the audience provider has for the audience and subject. */
    private static function bag($campaign, int $memberId, string $unsub): array {
        $member = Bean::load('member', $memberId);
        if (!$member->id) throw new \InvalidArgumentException("Campaigns::render: member #{$memberId} does not exist");
        $bag = ['first_name' => trim((string) $member->firstName), 'unsubscribe_url' => $unsub];
        return array_replace_recursive($bag, self::keyVars(self::scopeOf($campaign)), self::keyVars((string) $campaign->aboutKey !== '' ? (string) $campaign->aboutKey : null));
    }

    public static function base(): string {
        $base = rtrim((string) \Flight::get('app.baseurl'), '/');
        if ($base === '') throw new \RuntimeException('Missing setting [app] baseurl in conf/config.ini: campaign links need it.');
        return $base;
    }

    private static function replyDomain(): string {
        $d = trim((string) \Flight::getSetting(self::REPLY_DOMAIN_SETTING, 0));
        if ($d === '') throw new \RuntimeException('The setting ' . self::REPLY_DOMAIN_SETTING . ' is empty: set the domain replies come back to (the one the Mailgun route forwards) on /messaging.');
        return $d;
    }

    private static function escaped($v) {
        return is_array($v) ? array_map([self::class, 'escaped'], $v) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /** {path|default} through the pipeline interpolator; a token it cannot fill is the author's mistake and stops the send. */
    private static function fill($campaign, string $template, array $bag): string {
        \app\Pipeline\Vars::takeUnresolved();
        $out = (string) \app\Pipeline\Vars::resolve($template, $bag);
        $bad = \app\Pipeline\Vars::takeUnresolved();
        if ($bad) throw new \RuntimeException("campaign #{$campaign->id}: nothing to fill " . implode(', ', $bad) . ' — fix the message, or write {name|a default}');
        return $out;
    }

    /**
     * Send to everyone audience() names who has not had it. The ledger row is stored BEFORE the email, so a crash between the
     * two loses one email rather than sending it twice.
     * @return array{recipients:int,sent:int,failed:int,not_sent:int,texts:array{recipients:int,sent:int,failed:int,not_sent:int}}
     */
    public static function send($campaign): array {
        if (!in_array((string) $campaign->status, ['draft', 'scheduled', 'sending', 'active'], true)) {
            throw new \RuntimeException("campaign #{$campaign->id} is {$campaign->status}: it is not sent again");
        }
        $mailer = self::mailer();
        $campaign->status = 'sending';
        Bean::store($campaign);
        $ids = self::audience($campaign);
        $stats = ['recipients' => count($ids), 'sent' => 0, 'failed' => 0, 'not_sent' => 0];
        foreach ($ids as $memberId) $stats[self::deliver($campaign, $memberId, $mailer)]++;
        $stats['texts'] = self::sendTexts($campaign);
        $unsent = Bean::count('msgsend', "msgcampaign_id = ? AND channel = 'email' AND sent_at IS NULL", [(int) $campaign->id]);
        if ($unsent === 0) {
            $campaign->status = (string) $campaign->cadence === 'every_n_days' ? 'active' : 'sent';
            $campaign->sentAt = date('Y-m-d H:i:s');
        } else {
            \Flight::get('log')?->error("ERROR Campaigns: campaign #{$campaign->id} left 'sending': $unsent recipients recorded but not sent " . json_encode($stats));
        }
        Bean::store($campaign);
        return $stats;
    }

    /**
     * One member's copy: the ledger row first, then the email. Returns which of $stats' counters it belongs in.
     * @return 'sent'|'failed'|'not_sent'
     */
    public static function deliver($campaign, int $memberId, bool $mailerReady, array $extra = []): string {
        $token = bin2hex(random_bytes(16));
        $r = self::render($campaign, $memberId, $token, $extra);
        $replyTo = 'reply-' . $token . '@' . self::replyDomain();
        $row = Bean::dispense('msgsend');
        $row->msgcampaignId = (int) $campaign->id;
        $row->memberId = $memberId;
        $row->channel = 'email';
        $row->token = $token;
        $row->links = json_encode($r['links'], JSON_UNESCAPED_SLASHES);
        $row->clicks = 0;
        $row->createdAt = date('Y-m-d H:i:s');
        Bean::store($row);   // the ledger first
        if (!$mailerReady) return 'not_sent';
        $email = (string) Bean::getCell('SELECT email FROM member WHERE id = ?', [$memberId]);
        $m = Mailer::create()->to($email, trim((string) Bean::getCell('SELECT first_name FROM member WHERE id = ?', [$memberId])))
            ->subject($r['subject'])
            ->replyTo($replyTo)
            ->header('List-Unsubscribe', '<' . $r['unsubscribe_url'] . '>')
            ->header('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        if (!$m->send($r['html'], $r['text'])) return 'failed';   // Mailer logged why
        $row->providerEid = $m->lastMessageId();
        $row->sentAt = date('Y-m-d H:i:s');
        Bean::store($row);
        return 'sent';
    }

    // ---- texts ---------------------------------------------------------------------------------

    /** The platform admin's switch: may community owners send texts (reminders are not owners' texts and do not need it)? Off until set. */
    public const OWNERS_TEXT_SETTING = 'owners_may_text';
    public const STOP_LINE = "\nReply STOP or: ";

    public static function ownersMayText(): bool {
        return (string) \Flight::getSetting(self::OWNERS_TEXT_SETTING, 0) === '1';
    }

    /**
     * Does this campaign go out as a text at all? Reminders do (to those who asked for texts); an owner's message does when the
     * platform allows owner texts and the owner wrote a text. The digest, the nudges and every other kind never do.
     */
    public static function sendsText($campaign): bool {
        if (!in_array((string) $campaign->kind, ['message', 'gathering'], true)) return false;
        $code = self::categoryCode($campaign);
        if ($code === Reach::REMINDERS) return true;
        return $code !== Reach::TRANSACTIONAL && self::ownersMayText() && trim((string) $campaign->bodySms) !== '';
    }

    /** Where a text's {link} points: the thing the message is about, else its audience. */
    private static function textTarget($campaign): ?string {
        if (!Providers::can('audiences', 'link')) return null;
        foreach ([(string) $campaign->aboutKey, (string) $campaign->scopeKey] as $key) {
            if ($key === '') continue;
            $url = Providers::need('audiences')::link($key);
            if ($url !== null) return $url;
        }
        return null;
    }

    /**
     * The text one member gets and the links in it. The owner's own words (body_sms; {link} is the tracked short link to the thing
     * or the audience), or the subject and that link when there are none; always ending with the STOP line and the link that stops them.
     * @return array{text:string,links:string[],parts:int}
     */
    public static function renderText($campaign, int $memberId, string $token): array {
        $unsub = Reach::unsubscribeUrl($memberId, self::categoryCode($campaign), self::scopeOf($campaign), 'text');
        $bag = self::bag($campaign, $memberId, $unsub);
        $target = self::textTarget($campaign);
        $short = Reach::textBase() . '/msgtrack/l/' . $token . '/0';
        $links = [];
        $own = trim((string) $campaign->bodySms);
        if ($own !== '') {
            if (str_contains($own, '{link}')) {
                if ($target === null) throw new \RuntimeException("campaign #{$campaign->id}: {link} in a text needs an audience or a subject with a link (the audiences provider's link())");
                $links[] = $target;
                $bag['link'] = $short;
            }
            $body = trim(self::fill($campaign, $own, $bag));
        } else {
            if ($target === null) throw new \RuntimeException("campaign #{$campaign->id}: a text with no words of its own needs an audience or a subject with a link (the audiences provider's link())");
            $links[] = $target;
            $body = trim(self::fill($campaign, (string) $campaign->subject, $bag)) . ' ' . $short;
        }
        $text = $body . self::STOP_LINE . $unsub;
        return ['text' => $text, 'links' => $links, 'parts' => Sms::parts($text)];
    }

    /** A text token is short (it is paid for by the character); Model_Msgsend::byToken accepts 12 to 32 hex. */
    private static function textToken(): string {
        return bin2hex(random_bytes(6));
    }

    /**
     * Text this campaign to the members who asked for texts and have not had it. A text over one part is not sent: its ledger row
     * is kept (so it is not tried again every hour), an ERROR names the campaign, and it counts as failed.
     * @return array{recipients:int,sent:int,failed:int,not_sent:int}
     */
    public static function sendTexts($campaign): array {
        $stats = ['recipients' => 0, 'sent' => 0, 'failed' => 0, 'not_sent' => 0];
        if (!self::sendsText($campaign)) return $stats;
        $ids = self::audience($campaign, 'text');
        $stats['recipients'] = count($ids);
        $ready = Sms::isConnected();
        if ($ids && !$ready) \Flight::get('log')?->error("ERROR Campaigns: campaign #{$campaign->id} has " . count($ids) . ' members who asked for texts, but no text number is connected (Connections → Quo): nothing is texted.');
        foreach ($ids as $memberId) $stats[self::deliverText($campaign, $memberId, $ready)]++;
        return $stats;
    }

    /** One member's text: the ledger row first, then the text. @return 'sent'|'failed'|'not_sent' */
    public static function deliverText($campaign, int $memberId, bool $smsReady): string {
        $phone = Reach::phone($memberId);
        if ($phone === null) throw new \LogicException("Campaigns::deliverText: member #{$memberId} has no verified phone; audience() should not have named them");
        $token = self::textToken();
        $r = self::renderText($campaign, $memberId, $token);
        $row = Bean::dispense('msgsend');
        $row->msgcampaignId = (int) $campaign->id;
        $row->memberId = $memberId;
        $row->channel = 'text';
        $row->token = $token;
        $row->links = json_encode($r['links'], JSON_UNESCAPED_SLASHES);
        $row->clicks = 0;
        $row->createdAt = date('Y-m-d H:i:s');
        Bean::store($row);   // the ledger first
        if ($r['parts'] !== 1) {
            \Flight::get('log')?->error("ERROR Campaigns: campaign #{$campaign->id} text for member #{$memberId} is {$r['parts']} parts (one allowed): not sent. Shorten the message.");
            return 'failed';
        }
        if (!$smsReady) return 'not_sent';
        try {
            $sent = Sms::send($phone, $r['text']);
        } catch (\Throwable $e) {
            \Flight::get('log')?->error("ERROR Campaigns: campaign #{$campaign->id} text to member #{$memberId} failed: " . $e->getMessage());
            return 'failed';
        }
        $row->providerEid = $sent['id'];
        $row->sentAt = date('Y-m-d H:i:s');
        Bean::store($row);
        return 'sent';
    }

    /** The mailer is usable; false (and an ERROR naming the fix) when this install has no mail connection. In an install with several sites, sends as the site named by the setting messaging_site_domain. */
    public static function mailer(): bool {
        if (Sites::multi()) {
            $domain = trim((string) \Flight::getSetting(self::SITE_DOMAIN_SETTING, 0));
            if ($domain === '') throw new \RuntimeException('This install has several sites, so the setting ' . self::SITE_DOMAIN_SETTING . ' must name the one whose Mailgun connection sends campaign mail. Set it on /messaging.');
            $site = Sites::byHost($domain);
            if (!$site) throw new \RuntimeException("Campaigns: no site answers to $domain (setting " . self::SITE_DOMAIN_SETTING . ') — campaign mail goes out from that site\'s own Mailgun connection.');
            Sites::switchTo((int) $site->id);
        }
        if (!Mailer::isConfigured()) {
            \Flight::get('log')?->error('ERROR Campaigns: no Mailgun connection for the campaign site — recipients are recorded, nothing is sent. Connect Mailgun under Connections.');
            return false;
        }
        return true;
    }

    /** The hourly run: scheduled campaigns whose time has come, then the active digest, nudges and repeating messages. @return string[] what was done */
    public static function runDue(): array {
        $done = [];
        $now = date('Y-m-d H:i:s');
        foreach (Bean::find('msgcampaign', "status = 'scheduled' AND scheduled_at <= ? ORDER BY scheduled_at", [$now]) as $c) {
            $done[] = "campaign #{$c->id} sent: " . json_encode(self::send($c));
        }
        foreach (Bean::find('msgcampaign', "status = 'active' ORDER BY id") as $c) {
            $kind = (string) $c->kind;
            if ($kind === 'digest') { $done[] = "campaign #{$c->id} evaluated: " . json_encode(Digest::run($c)); continue; }
            if ($kind === 'trigger') { $done[] = "campaign #{$c->id} evaluated: " . json_encode(Digest::runTrigger($c)); continue; }
            $done[] = "campaign #{$c->id} repeated: " . json_encode(self::send($c));
        }
        return $done;
    }

    /**
     * An action a member took in the app, credited to the campaign whose link they clicked in the last 48 hours: a msgevent
     * of $kind on that send, and acted_at on it. Nothing to credit is normal (null).
     */
    public static function acted(int $memberId, string $kind, array $meta = []) {
        $since = date('Y-m-d H:i:s', time() - self::ACT_WINDOW);
        $id = Bean::getCell("SELECT s.id FROM msgsend s JOIN msgevent e ON e.msgsend_id = s.id AND e.kind = 'click'
            WHERE s.member_id = ? AND e.at >= ? ORDER BY e.at DESC, e.id DESC LIMIT 1", [$memberId, $since]);
        if (!$id) return null;
        $send = Bean::load('msgsend', (int) $id);
        $event = \Model_Msgevent::record($send, $kind, $meta);
        if (!$send->actedAt) { $send->actedAt = date('Y-m-d H:i:s'); Bean::store($send); }
        return $event;
    }

    /** How many messages an owner may send to one audience in 7 days (the platform admin's setting; absent means the default). */
    public static function weeklyCap(): int {
        $v = \Flight::getSetting(self::CAP_SETTING, 0);
        if ($v === null || $v === '') return self::DEFAULT_WEEKLY_CAP;
        if (!ctype_digit((string) $v) || (int) $v < 1) throw new \RuntimeException('The setting ' . self::CAP_SETTING . ' is "' . $v . '": it must be a whole number of 1 or more. Fix it on /messaging.');
        return (int) $v;
    }

    /** Owner messages sent or scheduled for this audience in the last 7 days (the ones the cap counts; reminders are not an owner's sends). */
    public static function weeklyCount(string $scopeKey): int {
        $reminders = \Model_Msgcategory::byCode(Reach::REMINDERS);
        return (int) Bean::getCell("SELECT COUNT(*) FROM msgcampaign WHERE scope_key = ? AND category_ref <> ? AND kind IN ('message','gathering') AND status <> 'draft' AND created_at >= ?",
            [$scopeKey, (int) $reminders->id, date('Y-m-d H:i:s', time() - 7 * 86400)]);
    }

    /**
     * What happened to each campaign: campaign id => sent, delivered, clicked, acted, replies, recipients (ledger rows).
     * @param int[] $ids
     * @return array<int,array<string,int>>
     */
    public static function stats(array $ids): array {
        $out = [];
        foreach ($ids as $id) $out[(int) $id] = ['recipients' => 0, 'sent' => 0, 'delivered' => 0, 'clicked' => 0, 'acted' => 0, 'replies' => 0];
        if (!$out) return $out;
        $in = implode(',', array_fill(0, count($out), '?'));
        $keys = array_keys($out);
        foreach (Bean::getAll("SELECT msgcampaign_id AS id, COUNT(*) AS recipients, COUNT(sent_at) AS sent, COUNT(delivered_at) AS delivered, COUNT(first_click_at) AS clicked, COUNT(acted_at) AS acted
            FROM msgsend WHERE msgcampaign_id IN ($in) GROUP BY msgcampaign_id", $keys) as $r) {
            $out[(int) $r['id']] = ['recipients' => (int) $r['recipients'], 'sent' => (int) $r['sent'], 'delivered' => (int) $r['delivered'],
                'clicked' => (int) $r['clicked'], 'acted' => (int) $r['acted'], 'replies' => 0];
        }
        foreach (Bean::getAll("SELECT s.msgcampaign_id AS id, COUNT(*) AS n FROM msgevent e JOIN msgsend s ON s.id = e.msgsend_id
            WHERE e.kind = 'reply' AND s.msgcampaign_id IN ($in) GROUP BY s.msgcampaign_id", $keys) as $r) {
            $out[(int) $r['id']]['replies'] = (int) $r['n'];
        }
        return $out;
    }

    /** Clicks per link of one campaign: [['url' => …, 'clicks' => n], …] in the order the links appear in the email. */
    public static function linkClicks($campaign): array {
        $first = Bean::getCell('SELECT links FROM msgsend WHERE msgcampaign_id = ? ORDER BY id LIMIT 1', [(int) $campaign->id]);
        if ($first === null || $first === false) return [];
        $links = json_decode((string) $first, true);
        if (!is_array($links)) throw new \RuntimeException("campaign #{$campaign->id}: a msgsend's links is not JSON");
        $counts = array_fill(0, count($links), 0);
        foreach (Bean::getCol("SELECT e.meta FROM msgevent e JOIN msgsend s ON s.id = e.msgsend_id WHERE e.kind = 'click' AND s.msgcampaign_id = ?", [(int) $campaign->id]) as $meta) {
            $m = json_decode((string) $meta, true);
            if (is_array($m) && isset($m['link'], $counts[(int) $m['link']])) $counts[(int) $m['link']]++;
        }
        $out = [];
        foreach ($links as $i => $url) $out[] = ['url' => (string) $url, 'clicks' => $counts[$i]];
        return $out;
    }
}
