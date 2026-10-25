<?php
namespace app\concepts\messaging;

use \Flight as Flight;
use app\Bean;
use app\BaseControls\Control;
use app\Mailer;
use app\Sms;
use app\Ui;

/**
 * The platform admin's Messages page (index, view, edit, test, the cap, texting, the webhook key, the mail domains and the suppression
 * list), and the page a person who owns an audience writes to it from (compose, message): the audiences provider's mayMessage() decides who.
 */
class Messaging extends Control {

    // nav: Messages in the admin menu (the app's views/app/nav.php); compose and message are linked from the audience's own page

    private const SUPPRESSED_SHOWN = 200;

    /** "Everyone", or the audience's name; its key when the provider no longer knows it. */
    private function whereLabel(?string $key): string {
        if ($key === null || $key === '') return 'Everyone';
        if (!Providers::has('audiences')) return $key;
        try { return Providers::need('audiences')::label($key); }
        catch (\InvalidArgumentException $e) { return $key; }
    }

    private function row($m, array $stats): object {
        $s = $stats[(int) $m->id];
        return (object) ['id' => (int) $m->id, 'subject' => (string) $m->subject, 'where' => $this->whereLabel((string) $m->scopeKey), 'kind' => (string) $m->kind, 'status' => (string) $m->status,
            'category' => (string) Bean::load('msgcategory', (int) $m->categoryRef)->label,
            'sent' => $s['sent'], 'delivered' => $s['delivered'], 'clicked' => $s['clicked'], 'acted' => $s['acted'], 'replies' => $s['replies']];
    }

    private function missing(): void {
        Flight::response()->status(404);
        $this->render('messaging/view', ['title' => 'Not found', 'm' => null]);
    }

    /** Every campaign, categories, the cap, the webhook key, the suppression list, and what the app has handed the plugin. */
    public function index($params = []) {
        $all = Bean::find('msgcampaign', 'ORDER BY id DESC');
        $stats = Campaigns::stats(array_keys($all));
        $rows = array_map(fn($m) => $this->row($m, $stats), array_values($all));
        $categories = array_map(fn($c) => (object) ['label' => (string) $c->label, 'description' => (string) $c->description, 'default' => (int) $c->defaultOn === 1 ? 'On' : 'Off',
            'optout' => (int) $c->canOptOut === 1 ? 'Members can switch it off' : 'Always on', 'channels' => (string) $c->channels], \Model_Msgcategory::all());
        $suppressed = Bean::find('msgsuppression', 'ORDER BY at DESC, id DESC LIMIT ' . self::SUPPRESSED_SHOWN);
        $keySet = trim((string) Flight::getSetting(Webhook::SETTING, 0)) !== '';
        $this->render('messaging/index', ['title' => 'Messages', 'rows' => $rows, 'categories' => $categories, 'suppressed' => array_values($suppressed),
            'suppressedTotal' => Bean::count('msgsuppression'), 'cap' => Campaigns::weeklyCap(), 'keySet' => $keySet, 'ownersText' => Campaigns::ownersMayText(),
            'textsOn' => Sms::isConnected(), 'textBase' => trim((string) Flight::getSetting(Reach::TEXT_BASE_SETTING, 0)),
            'replyDomain' => trim((string) Flight::getSetting(Campaigns::REPLY_DOMAIN_SETTING, 0)), 'siteDomain' => trim((string) Flight::getSetting(Campaigns::SITE_DOMAIN_SETTING, 0)),
            'providers' => [
                'audiences' => ['Audiences', 'who a message can be for (groups, with owners)'],
                'digest' => ['Digest', 'what the weekly digest says'],
                'phone' => ['Phone', 'a member\'s proved phone number, for texts'],
                'triggers' => ['Extra nudges', 'trigger codes of the app\'s own'],
            ], 'have' => Providers::registered(), 'digestUnavailable' => !Digest::available() && Bean::count('msgcampaign', "kind = 'digest'") > 0]);
    }

    /** One campaign: its message, counts and the clicks on each link. */
    public function view($params = []) {
        $m = Bean::load('msgcampaign', (int) $this->getParam('id'));
        if (!$m->id) { $this->missing(); return; }
        $stats = Campaigns::stats([(int) $m->id]);
        $this->render('messaging/view', ['title' => (string) $m->subject, 'm' => $m, 'row' => $this->row($m, $stats), 'stats' => $stats[(int) $m->id], 'links' => Campaigns::linkClicks($m),
            'unavailable' => (string) $m->kind === 'digest' && !Digest::available()]);
    }

    /** Is this campaign one the platform runs by itself (digest, nudge), whose schedule the admin sets? */
    private function isSystem($m): bool {
        return in_array((string) $m->kind, ['digest', 'trigger'], true);
    }

    /** An owner's one-off message that has gone out (or is going) is a record, not a draft. */
    private function isLocked($m): bool {
        return !$this->isSystem($m) && in_array((string) $m->status, ['sending', 'sent'], true);
    }

    private function formOf($m): array {
        return ['subject' => (string) $m->subject, 'body_email' => (string) $m->bodyEmail, 'body_text' => (string) $m->bodyText,
            'trigger_days' => $m->triggerDays === null ? '' : (int) $m->triggerDays, 'cadence' => (string) $m->cadence, 'cadence_days' => $m->cadenceDays === null ? '' : (int) $m->cadenceDays,
            'status' => (string) $m->status === 'paused' ? 'paused' : 'active'];
    }

    private function editPage($m, array $form, array $problems, ?array $preview): void {
        $this->render('messaging/edit', ['title' => 'Edit this message', 'm' => $m, 'system' => $this->isSystem($m), 'locked' => $this->isLocked($m), 'form' => $form, 'problems' => $problems, 'preview' => $preview,
            'triggerLabel' => Digest::triggerLabels()[(string) $m->triggerCode] ?? null,
            'vars' => ['subject' => Campaigns::variables($m, 'subject'), 'body_email' => Campaigns::variables($m, 'body_email'), 'body_text' => Campaigns::variables($m, 'body_text')]]);
    }

    /** GET: the form. POST: save it. */
    public function edit($params = []) {
        $m = Bean::load('msgcampaign', (int) $this->getParam('id'));
        if (!$m->id) { $this->missing(); return; }
        $system = $this->isSystem($m);
        $form = $this->formOf($m);
        $problems = [];
        if (Flight::request()->method === 'POST') {
            $this->validateCSRF();
            if ($this->isLocked($m)) { $this->flash('error', 'That message has already gone out, so it cannot be changed.'); Flight::redirect('/messaging/view?id=' . (int) $m->id); return; }
            foreach (array_keys($form) as $k) if (($v = $this->getParam($k)) !== null) $form[$k] = trim((string) $v);
            $problems = $this->problems($m, $form, $system);
            if (!$problems) {
                $m->subject = $form['subject'];
                $m->bodyEmail = $form['body_email'];
                $m->bodyText = $form['body_text'];
                if ($system) {
                    if ((string) $m->kind === 'trigger') $m->triggerDays = (int) $form['trigger_days'];
                    $m->cadence = $form['cadence'];
                    $m->cadenceDays = $form['cadence'] === 'every_n_days' ? (int) $form['cadence_days'] : null;
                    $m->status = $form['status'];
                }
                $m->updatedAt = date('Y-m-d H:i:s');
                Bean::store($m);
                $this->flash('success', 'Saved.');
                Flight::redirect('/messaging/view?id=' . (int) $m->id);
                return;
            }
        }
        $this->editPage($m, $form, $problems, null);
    }

    /** POST: send the admin a test copy of the saved message (or only show it when no mail is connected). */
    public function test($params = []) {
        $m = Bean::load('msgcampaign', (int) $this->getParam('id'));
        if (!$m->id) { $this->missing(); return; }
        if (Flight::request()->method !== 'POST') { Flight::redirect('/messaging/edit?id=' . (int) $m->id); return; }
        $this->validateCSRF();
        if ($this->isLocked($m)) { $this->flash('error', 'That message has already gone out, so it cannot be changed.'); Flight::redirect('/messaging/view?id=' . (int) $m->id); return; }
        $me = Flight::getMember();
        try {
            $preview = Campaigns::sendTest($m, (int) $me->id, $this->isSystem($m) ? Digest::previewExtra($m, (int) $me->id) : []);
        } catch (\Throwable $e) {
            $this->logger->error('Messaging test send failed: ' . $e->getMessage(), ['campaign' => (int) $m->id]);
            $this->flash('error', 'The test could not be made: ' . $e->getMessage());
            Flight::redirect('/messaging/edit?id=' . (int) $m->id);
            return;
        }
        if ($preview['sent']) { $this->flash('success', 'A test was sent to ' . $me->email . '.'); Flight::redirect('/messaging/edit?id=' . (int) $m->id); return; }
        if ($preview['mail_ready']) $this->flash('error', 'The test could not be sent; see the log. Here is what it would have said.');
        $this->editPage($m, $this->formOf($m), [], $preview);
    }

    /** @return array<string,string> field => what is wrong */
    private function problems($m, array $f, bool $system): array {
        $p = [];
        if ($f['subject'] === '') $p['subject'] = 'Write a subject.';
        if ($f['body_email'] === '') $p['body_email'] = 'Write the message.';
        foreach (['subject', 'body_email', 'body_text'] as $field) {
            if (isset($p[$field])) continue;
            $bad = Campaigns::unknownVariables($m, $field, $f[$field]);
            if ($bad) $p[$field] = 'This message cannot fill ' . implode(', ', array_map(fn($v) => '{' . $v . '}', $bad)) . '. It can use: ' . implode(', ', array_map(fn($v) => '{' . $v . '}', Campaigns::variables($m, $field))) . '.';
        }
        if ($system) {
            if ((string) $m->kind === 'trigger' && (!ctype_digit($f['trigger_days']) || (int) $f['trigger_days'] < 1)) $p['trigger_days'] = 'A whole number of days, 1 or more.';
            if (!in_array($f['cadence'], \Model_Msgcampaign::CADENCES, true)) $p['cadence'] = 'Choose once or every N days.';
            elseif ($f['cadence'] === 'every_n_days' && (!ctype_digit($f['cadence_days']) || (int) $f['cadence_days'] < 1)) $p['cadence_days'] = 'A whole number of days, 1 or more.';
            if (!in_array($f['status'], ['active', 'paused'], true)) $p['status'] = 'Choose active or paused.';
        }
        return $p;
    }

    /** POST: the number of messages an owner may send to one audience in 7 days. */
    public function cap($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::redirect('/messaging'); return; }
        $this->validateCSRF();
        $v = trim((string) $this->getParam('cap'));
        if (!ctype_digit($v) || (int) $v < 1 || (int) $v > 50) {
            $this->flash('error', 'The cap is a whole number from 1 to 50.');
        } else {
            Flight::setSetting(Campaigns::CAP_SETTING, (string) (int) $v, 0);
            $this->flash('success', 'Owners may now send ' . (int) $v . ' per audience each week.');
        }
        Flight::redirect('/messaging');
    }

    /** POST: may owners send texts? Off until an admin says so; a text costs money per member. */
    public function texting($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::redirect('/messaging'); return; }
        $this->validateCSRF();
        $v = (string) $this->getParam('owners_may_text');
        $base = rtrim(trim((string) $this->getParam('text_link_base')), '/');
        if ($v !== '0' && $v !== '1') {
            $this->flash('error', 'Choose whether owners may text.');
        } elseif ($base !== '' && !preg_match('~^https?://[A-Za-z0-9.-]+(:\d+)?$~', $base)) {
            $this->flash('error', 'The short address must look like https://short.example, with no path.');
        } else {
            Flight::setSetting(Reach::TEXT_BASE_SETTING, $base, 0);
            Flight::setSetting(Campaigns::OWNERS_TEXT_SETTING, $v, 0);
            $this->flash('success', $v === '1' ? 'Owners can now text members who asked for texts.' : 'Owners can no longer send texts. Reminders still text those who asked.');
        }
        Flight::redirect('/messaging');
    }

    /** POST: the Mailgun webhook key. Write-only: an empty field changes nothing, and the page never shows the value. */
    public function webhookkey($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::redirect('/messaging'); return; }
        $this->validateCSRF();
        $v = trim((string) $this->getParam('key'));
        if (strlen($v) < 16) {
            $this->flash('error', 'The key must be at least 16 characters. It is the same value as ?key= in the Mailgun route\'s address.');
        } else {
            Flight::setSetting(Webhook::SETTING, $v, 0);
            $this->flash('success', 'The webhook key is saved.');
        }
        Flight::redirect('/messaging');
    }

    /** POST: the domain replies come back to, and (in an install with several sites) the site whose mail connection sends campaign mail. */
    public function domains($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::redirect('/messaging'); return; }
        $this->validateCSRF();
        $re = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/D';
        $reply = strtolower(trim((string) $this->getParam('reply_domain')));
        $site = strtolower(trim((string) $this->getParam('site_domain')));
        if (!preg_match($re, $reply)) {
            $this->flash('error', 'The reply domain must look like example.com.');
        } elseif ($site !== '' && !preg_match($re, $site)) {
            $this->flash('error', 'The sending site must look like example.com, or be left empty.');
        } else {
            Flight::setSetting(Campaigns::REPLY_DOMAIN_SETTING, $reply, 0);
            Flight::setSetting(Campaigns::SITE_DOMAIN_SETTING, $site, 0);
            $this->flash('success', 'The mail domains are saved.');
        }
        Flight::redirect('/messaging');
    }

    /** POST: take an address off the suppression list, so it can be mailed again. */
    public function unsuppress($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::redirect('/messaging'); return; }
        $this->validateCSRF();
        $s = Bean::load('msgsuppression', (int) $this->getParam('id'));
        if (!$s->id) { $this->flash('error', 'That address is not on the list.'); Flight::redirect('/messaging'); return; }
        $email = (string) $s->email;
        Bean::trash($s);
        $this->flash('success', $email . ' can be mailed again.');
        Flight::redirect('/messaging');
    }

    // ---- the owner's pages -----------------------------------------------------------------------

    /** The audience in ?scope= the signed-in member may message: [key, label, category code]. 404 for one that is none, 403 for one they may not message. */
    private function audience(): array {
        $key = trim((string) $this->getParam('scope'));
        if ($key === '') {
            if (!Flight::hasLevel(LEVELS['ADMIN'])) $this->forbid('Only an admin can message everyone.');
            return [null, 'Everyone', null];
        }
        if (!preg_match(\Model_Msgcampaign::KEY_RE, $key) || !Providers::has('audiences')) { Flight::notFound(); exit; }
        $audiences = Providers::need('audiences');
        try { $label = (string) $audiences::label($key); }
        catch (\InvalidArgumentException $e) { Flight::notFound(); exit; }
        if (!$audiences::mayMessage((int) $this->member->id, $key)) $this->forbid('Only the owner of ' . $label . ' can message it.', ['scope' => $key]);
        return [$key, $label, (string) $audiences::category()];
    }

    /** Plain text the owner typed -> the HTML body: paragraphs, line breaks, and web addresses (and {gathering.url}) as links. */
    private static function bodyHtml(string $text): string {
        $html = htmlspecialchars(str_replace("\r", '', trim($text)), ENT_QUOTES, 'UTF-8');
        $html = preg_replace_callback('~(?<![">=])(https?://[^\s<]+|\{[a-z]+\.url\})~', fn($m) => '<a href="' . $m[1] . '">' . $m[1] . '</a>', $html);
        $paras = array_map(fn($p) => '<p>' . nl2br($p, false) . '</p>', preg_split('/\n{2,}/', $html));
        return implode("\n", $paras);
    }

    /**
     * The owner's page: who a message goes to, the message form, and every message sent so far (?scope= is the audience).
     * POST sends it (send=now|later) or, with `test`, mails the owner alone.
     */
    public function compose($params = []) {
        [$key, $label, $categoryCode] = $this->audience();
        $audiences = $key !== null ? Providers::need('audiences') : null;
        $abouts = ($audiences !== null && Providers::can('audiences', 'abouts')) ? $audiences::abouts($key) : [];
        $options = [];
        $categories = [];
        if ($key === null) {
            foreach (\Model_Msgcategory::all() as $c) if (!in_array((string) $c->code, [Reach::TRANSACTIONAL, Reach::REMINDERS], true)) $categories[(string) $c->code] = (string) $c->label;
            if (!$categories) throw new \RuntimeException('Messaging: there is no message category an admin could send to everyone in. Register one (Model_Msgcategory::register) in a seed.');
        }
        $mayText = Campaigns::ownersMayText() && Providers::has('phone');
        $form = ['subject' => '', 'body' => '', 'body_sms' => '', 'about' => '', 'send' => 'now', 'scheduled_at' => '', 'category' => $key === null ? (string) array_key_first($categories) : $categoryCode];
        $problems = [];
        $notice = null;

        if (Flight::request()->method === 'POST') {
            $this->validateCSRF();
            foreach ($form as $f => $_) $form[$f] = trim((string) $this->getParam($f));
            if ($key !== null) $form['category'] = $categoryCode;   // fixed by the audience, never read from the request
            elseif (!isset($categories[$form['category']])) $problems['category'] = 'Choose one of the kinds of message.';
            if (!$mayText) $form['body_sms'] = '';   // no text field is offered, so none is taken
            $isTest = $this->getParam('test') !== null;
            if ($form['about'] !== '' && !isset($abouts[$form['about']])) {
                $problems['about'] = 'Pick one of the choices offered.';
            } elseif ($form['about'] !== '' && ($this->getParam('fill') !== null || ($form['subject'] === '' && $form['body'] === '')) && Providers::can('audiences', 'aboutDraft')) {
                $d = $audiences::aboutDraft($form['about']);
                $form['subject'] = (string) $d['subject'];
                $form['body'] = (string) $d['body'];
                $notice = 'We filled in the subject and the message. Read them, change what you like, then send.';
            } elseif (!$problems) {
                $problems = $this->check($key, $form, $isTest || $this->getParam('texts') !== null);
                if ($this->getParam('texts') !== null) {   // "Check the texts": say what they would cost, send nothing
                    if (!$problems) $notice = $form['body_sms'] === '' ? 'No text is written, so no text goes out.' : $this->textLine($key, $form);
                } else {
                    if (!$problems && $isTest) { $this->sendTest($key, $form); return; }
                    if (!$problems) { $this->sendMessage($key, $form); return; }
                }
            }
        }

        $draft = $this->draft($key, $form['category'], ['about' => '']);
        $audienceLine = $this->audienceLine($key, $label, $draft);
        $used = $key !== null ? Campaigns::weeklyCount($key) : null;
        $test = Ui::raw(($mayText ? '<button type="submit" name="texts" value="1" formnovalidate class="btn btn-outline-secondary me-2">Check the texts</button>' : '')
            . ($abouts && Providers::can('audiences', 'aboutDraft') ? '<button type="submit" name="fill" value="1" formnovalidate class="btn btn-outline-secondary me-2">Fill in from the choice above</button>' : '')
            . '<button type="submit" name="test" value="1" formnovalidate class="btn btn-outline-secondary">Send me a test</button>');
        $this->render('messaging/compose', ['title' => 'Messages to ' . $label, 'scope' => $key, 'label' => $label, 'audienceLine' => $audienceLine, 'used' => $used, 'cap' => Campaigns::weeklyCap(),
            'form' => $form, 'problems' => $problems, 'abouts' => $abouts, 'categories' => $categories, 'rows' => $this->messageRows($key), 'notice' => $notice, 'test' => $test, 'mayText' => $mayText,
            'backUrl' => $this->backUrl()]);
    }

    /** Where the page's back link goes: the ?back= address when it is one of this app's own pages, else the admin list for an admin. */
    private function backUrl(): ?string {
        $b = (string) $this->getParam('back');
        if (preg_match('~^/[A-Za-z0-9_-][A-Za-z0-9_/.?=&%:-]*$~', $b)) return $b;
        return Flight::hasLevel(LEVELS['ADMIN']) ? '/messaging' : null;
    }

    /** "Goes to 41 of 58 members (17 have switched it off)". */
    private function audienceLine(?string $key, string $label, $draft): string {
        $goes = count(Campaigns::audience($draft));
        if ($key === null) return "Goes to $goes members (everyone who can be emailed and has not switched these messages off).";
        $audiences = Providers::need('audiences');
        $members = array_values(array_unique(array_map('intval', $audiences::members($key))));
        $total = count($members);
        $off = 0;
        foreach ($members as $mid) if ($audiences::allowsFor($mid, $key) === false) $off++;
        $other = max(0, $total - $goes - $off);
        $line = "Goes to $goes of $total members ($off have switched $label off";
        if ($other > 0) $line .= ", $other more cannot be emailed: they unsubscribed or have no address";
        return $line . ').';
    }

    /** Past and scheduled messages of this audience (and the reminders of what it is about), newest first. */
    private function messageRows(?string $key): array {
        $mine = $key === null
            ? Bean::find('msgcampaign', "(scope_key IS NULL OR scope_key = '') AND kind IN ('message','gathering') ORDER BY id DESC")
            : Bean::find('msgcampaign', "scope_key = ? AND kind IN ('message','gathering') ORDER BY id DESC", [$key]);
        $stats = Campaigns::stats(array_keys($mine));
        $rows = [];
        foreach ($mine as $m) {
            $rows[] = (object) ['id' => (int) $m->id, 'subject' => (string) $m->subject, 'when' => $m->sentAt ?: $m->scheduledAt, 'status' => (string) $m->status,
                'sent' => $stats[(int) $m->id]['sent'], 'delivered' => $stats[(int) $m->id]['delivered'], 'clicked' => $stats[(int) $m->id]['clicked'],
                'acted' => $stats[(int) $m->id]['acted'], 'replies' => $stats[(int) $m->id]['replies']];
        }
        return $rows;
    }

    /** Problems with what the owner wrote, keyed by field (or a list of sentences): empty, a variable nothing can fill, a bad time, the weekly cap. */
    private function check(?string $key, array $form, bool $isTest): array {
        $problems = [];
        if ($form['subject'] === '') $problems['subject'] = 'Write a subject.';
        if ($form['body'] === '') $problems['body'] = 'Write the message.';
        $draft = $this->draft($key, $form['category'], $form);
        if ($form['body_sms'] !== '') {
            try {
                $parts = Campaigns::renderText($draft, (int) $this->member->id, str_repeat('0', 12))['parts'];
                if ($parts > 1) $problems['body_sms'] = 'That text is ' . $parts . ' parts once the STOP line is added. A text may be one part (160 characters, or 70 with accents or emoji): shorten it.';
            } catch (\RuntimeException $e) {
                $problems['body_sms'] = 'The text uses something that cannot be filled in: ' . preg_replace('/^campaign #\d*: /', '', $e->getMessage());
            }
        }
        if (!in_array($form['send'], ['now', 'later'], true)) $problems['send'] = 'Choose now or at a time.';
        if (!$isTest && $form['send'] === 'later' && !isset($problems['send'])) {
            $at = $this->scheduledAt($form['scheduled_at']);
            if ($at === null) $problems['scheduled_at'] = 'Pick the day and time to send it.';
            elseif (strtotime($at) <= time()) $problems['scheduled_at'] = 'That time has passed. Pick a time ahead.';
        }
        if (!$problems) {
            try { Campaigns::render($draft, (int) $this->member->id); }
            catch (\RuntimeException $e) {
                $problems['body'] = 'The message uses something that cannot be filled in: ' . preg_replace('/^campaign #\d*: /', '', $e->getMessage());
            }
        }
        if (!$isTest && !$problems && $key !== null && Campaigns::weeklyCount($key) >= Campaigns::weeklyCap()) {
            $problems[] = 'You have already sent ' . Campaigns::weeklyCount($key) . ' of ' . Campaigns::weeklyCap() . ' messages to ' . $this->whereLabel($key) . ' in the last 7 days. Try again when the oldest is a week old.';
        }
        return $problems;
    }

    /** "42 texts, 1 part each" — what the text would cost, before it is sent. */
    private function textLine(?string $key, array $form): string {
        $d = $this->draft($key, $form['category'], $form);
        $n = count(Campaigns::audience($d, 'text'));
        $parts = Campaigns::renderText($d, (int) $this->member->id, str_repeat('0', 12))['parts'];
        return $n . ($n === 1 ? ' text, ' : ' texts, ') . $parts . ($parts === 1 ? ' part each.' : ' parts each.') . ' Only members who asked for texts get one. Nothing is sent until you press "Send to members".';
    }

    private function scheduledAt(string $value): ?string {
        if ($value === '') return null;
        $name = trim((string) Flight::getSetting('timezone', (int) $this->member->id));
        $tz = new \DateTimeZone($name !== '' ? $name : date_default_timezone_get());
        $d = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, $tz) ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i', $value, $tz);
        if (!$d) return null;
        return $d->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /** An unsaved campaign made from the form; category and audience are fixed here, never read from the request body for a scoped page. */
    private function draft(?string $key, string $categoryCode, array $form): \RedBeanPHP\OODBBean {
        $m = Bean::dispense('msgcampaign');
        $m->senderId = (int) $this->member->id;
        $m->categoryRef = (int) \Model_Msgcategory::byCode($categoryCode)->id;
        $m->scopeKey = $key;
        $about = (string) ($form['about'] ?? '');
        $m->kind = $about !== '' ? 'gathering' : 'message';
        $m->aboutKey = $about !== '' ? $about : null;
        $m->subject = (string) ($form['subject'] ?? '');
        $m->bodyEmail = self::bodyHtml((string) ($form['body'] ?? ''));
        $m->bodyText = (string) ($form['body'] ?? '');
        $m->bodySms = ($form['body_sms'] ?? '') !== '' ? $form['body_sms'] : null;
        $m->cadence = 'once';
        return $m;
    }

    private function sendTest(?string $key, array $form): void {
        $r = Campaigns::render($this->draft($key, $form['category'], $form), (int) $this->member->id);
        $email = (string) $this->member->email;
        if (!Campaigns::mailer()) {
            $this->flash('error', 'Mail is not connected on this site, so no test could be sent.');
        } elseif (Mailer::create()->to($email, (string) $this->member->firstName)->subject('[Test] ' . $r['subject'])->send($r['html'], $r['text'])) {
            $this->flash('success', 'A test went to ' . $email . '.');
        } else {
            $this->flash('error', 'The test could not be sent. The reason is in the log.');
        }
        Flight::redirect($this->composeUrl($key));
    }

    private function composeUrl(?string $key): string {
        return '/messaging/compose' . ($key !== null ? '?scope=' . urlencode($key) : '');
    }

    private function sendMessage(?string $key, array $form): void {
        $m = $this->draft($key, $form['category'], $form);
        $later = $form['send'] === 'later';
        $m->status = 'scheduled';
        $m->scheduledAt = $later ? $this->scheduledAt($form['scheduled_at']) : date('Y-m-d H:i:s');
        Bean::store($m);
        $note = '';
        if ($m->aboutKey) $note = ' ' . $this->remindThose((string) $m->aboutKey, (string) $key, (int) $this->member->id);
        if ($later) {
            $this->flash('success', 'Your message is scheduled.' . $note);
        } else {
            $stats = Campaigns::send($m);
            $t = $stats['texts'];
            if ($t['recipients'] > 0 && $t['sent'] < $t['recipients']) $note .= ' Of ' . $t['recipients'] . ' texts, ' . $t['sent'] . ' went out' . (Sms::isConnected() ? '; the reason is in the log.' : ': no text number is connected.');
            elseif ($t['sent'] > 0) $note .= ' ' . $t['sent'] . ' texts went out.';
            if ($stats['not_sent'] > 0) $this->flash('error', 'Mail is not connected on this site: ' . $stats['not_sent'] . ' recipients were recorded but nothing was sent.' . $note);
            elseif ($stats['failed'] > 0) $this->flash('error', $stats['failed'] . ' of ' . $stats['recipients'] . ' emails could not be sent; the reason is in the log.' . $note);
            else $this->flash('success', 'Sent to ' . $stats['sent'] . ' of ' . $stats['recipients'] . ' members.' . $note);
        }
        Flight::redirect($this->composeUrl($key));
    }

    /** A message about a thing also schedules its reminder for the people the app says are in it, when the app offers one. Said in one sentence. */
    private function remindThose(string $aboutKey, string $scope, int $senderId): string {
        if (!Providers::can('audiences', 'reminder')) return '';
        $cat = \Model_Msgcategory::byCode(Reach::REMINDERS);
        if (Bean::count('msgcampaign', 'category_ref = ? AND about_key = ?', [(int) $cat->id, $aboutKey]) > 0) return 'Its reminder was already scheduled.';
        $rem = Providers::need('audiences')::reminder($aboutKey);
        if ($rem === null) return 'There is no reminder for it.';
        if ((int) $rem['at'] <= time()) return 'It is too soon for a reminder.';
        $r = Bean::dispense('msgcampaign');
        $r->senderId = $senderId;
        $r->categoryRef = (int) $cat->id;
        $r->scopeKey = $scope !== '' ? $scope : null;   // listed with the audience's messages; the reminder itself goes to those in aboutKey
        $r->aboutKey = $aboutKey;
        $r->kind = 'gathering';
        $r->subject = (string) $rem['subject'];
        $r->bodyEmail = self::bodyHtml((string) $rem['body']);
        $r->bodyText = (string) $rem['body'];
        $r->bodySms = (string) $rem['sms'];
        $r->cadence = 'once';
        $r->status = 'scheduled';
        $r->scheduledAt = date('Y-m-d H:i:s', (int) $rem['at']);
        Bean::store($r);
        return 'A reminder goes to those it is for, on ' . date('M j, g:i a', (int) $rem['at']) . '.';
    }

    /** One message of an audience the member may message, with its body and how many times each link was clicked (?id= is the campaign). */
    public function message($params = []) {
        $m = Bean::load('msgcampaign', (int) $this->getParam('id'));
        if (!$m->id || !in_array((string) $m->kind, ['message', 'gathering'], true)) { Flight::notFound(); return; }
        $key = (string) $m->scopeKey;
        if ($key === '') {
            if (!Flight::hasLevel(LEVELS['ADMIN'])) $this->forbid('Only an admin can see a message to everyone.');
        } elseif (!Providers::has('audiences') || !Providers::need('audiences')::mayMessage((int) $this->member->id, $key)) {
            $this->forbid('Only the owner of ' . $this->whereLabel($key) . ' can see its messages.', ['scope' => $key]);
        }
        $stats = Campaigns::stats([(int) $m->id])[(int) $m->id];
        $this->render('messaging/message', ['title' => (string) $m->subject, 'm' => $m, 'where' => $this->whereLabel($key), 'composeUrl' => $this->composeUrl($key !== '' ? $key : null), 'stats' => $stats, 'links' => Campaigns::linkClicks($m)]);
    }
}
