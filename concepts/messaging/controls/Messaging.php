<?php
namespace app\concepts\messaging;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Ui;

/**
 * The platform admin's Messages page (index, view, edit, test, the cap, texting, each site's words, the suppression
 * list), and the page a person who owns an audience writes to it from (compose, message): the audiences provider's
 * mayMessage() decides who. Everything shown and done here is the service's, through lib/Client.php; this app adds
 * who may write, the words of a message about a thing, and its reminder.
 */
class Messaging extends Control {

    // nav: Messages in the admin menu (the app's views/app/nav.php); compose and message are linked from the audience's own page

    private const PROVIDERS = [
        'audiences' => ['Audiences', 'who a message can be for (groups, with owners)'],
        'digest' => ['Digest', 'what the weekly digest says'],
        'phone' => ['Phone', 'a member\'s proved phone number, for texts'],
        'triggers' => ['Extra nudges', 'trigger codes of the app\'s own'],
    ];

    private function me(): int { return (int) $this->member->id; }

    /** "Everyone", or the audience's name; its key when the provider no longer knows it. */
    private function whereLabel(?string $key): string {
        if ($key === null || $key === '') return 'Everyone';
        if (!Providers::has('audiences')) return $key;
        try { return Providers::need('audiences')::label($key); }
        catch (\InvalidArgumentException $e) { return $key; }
    }

    private function row(array $c): object {
        $s = $c['stats'] ?? ['sent' => 0, 'delivered' => 0, 'clicked' => 0, 'acted' => 0, 'replies' => 0];
        return (object) ['id' => (int) $c['id'], 'subject' => (string) $c['subject'], 'where' => $this->whereLabel($c['scope']), 'kind' => (string) $c['kind'], 'status' => (string) $c['status'],
            'category' => (string) $c['category'], 'when' => $c['sent_at'] !== '' ? $c['sent_at'] : $c['scheduled_at'],
            'sent' => $s['sent'], 'delivered' => $s['delivered'], 'clicked' => $s['clicked'], 'acted' => $s['acted'], 'replies' => $s['replies']];
    }

    /** The service could not be reached or refused: the page says so, in the service's words. */
    private function unavailable(ServiceException $e, string $back = '/messaging'): void {
        $this->logger->error('ERROR Messaging: ' . $e->getMessage());
        Flight::response()->status($e->status === 403 ? 403 : 503);
        $this->render('messaging/unavailable', ['title' => 'Messaging is not answering', 'why' => $e->getMessage(), 'back' => $back]);
    }

    private function missing(): void {
        Flight::response()->status(404);
        $this->render('messaging/view', ['title' => 'Not found', 'c' => null]);
    }

    /** The campaign an ?id= names, or null (404 already answered). */
    private function campaign(): ?array {
        $id = (int) $this->getParam('id');
        try { return Campaigns::get($id)['campaign']; }
        catch (ServiceException $e) { if ($e->status === 404) { $this->missing(); return null; } throw $e; }
    }

    /** Every campaign, kinds of message, the suppression list, the settings, the sites, and what the app has handed in. */
    public function index($params = []) {
        try {
            $all = Campaigns::list();
            $categories = Client::get('categories')['categories'];
            $suppressed = Client::get('suppressions')['suppressions'];
            $settings = Client::get('settings')['settings'];
            $sites = Client::get('sites')['sites'];
        } catch (ServiceException $e) { $this->unavailable($e); return; }
        $this->render('messaging/index', ['title' => 'Messages', 'rows' => array_map(fn($c) => $this->row($c), $all),
            'categories' => array_map(fn($c) => (object) ['label' => $c['label'], 'description' => $c['description'], 'default' => $c['default_on'] ? 'On' : 'Off',
                'optout' => $c['can_opt_out'] ? 'Members can switch it off' : 'Always on', 'channels' => implode(', ', $c['channels'])], $categories),
            'suppressed' => array_map(fn($s) => (object) $s, $suppressed), 'settings' => $settings, 'sites' => $sites,
            'providers' => self::PROVIDERS, 'have' => Providers::registered(), 'serviceUrl' => Client::url()]);
    }

    /** One campaign: its message, counts and the clicks on each link. */
    public function view($params = []) {
        try {
            $c = $this->campaign();
            if ($c === null) return;
            $links = Campaigns::get((int) $c['id'])['links'];
        } catch (ServiceException $e) { $this->unavailable($e); return; }
        $this->render('messaging/view', ['title' => $c['subject'], 'c' => $c, 'row' => $this->row($c), 'links' => $links,
            'unavailable' => $c['kind'] === 'digest' && !Providers::has('digest')]);
    }

    private function formOf(array $c): array {
        return ['subject' => $c['subject'], 'body_email' => $c['body_email'], 'body_text' => $c['body_text'],
            'trigger_days' => $c['trigger_days'] > 0 ? (int) $c['trigger_days'] : '', 'cadence' => $c['cadence'], 'cadence_days' => $c['cadence_days'] > 0 ? (int) $c['cadence_days'] : '',
            'status' => $c['status'] === 'paused' ? 'paused' : 'active'];
    }

    private function editPage(array $c, array $form, array $problems, ?array $preview): void {
        $this->render('messaging/edit', ['title' => 'Edit this message', 'c' => $c, 'system' => Campaigns::isSystem($c), 'locked' => $c['locked'], 'form' => $form, 'problems' => $problems, 'preview' => $preview,
            'triggerLabel' => Providers::triggerLabels()[$c['trigger_code']] ?? null]);
    }

    /** GET: the form. POST: save it (the service checks the words: a {variable} nothing can fill is refused by field). */
    public function edit($params = []) {
        try {
            $c = $this->campaign();
            if ($c === null) return;
            $system = Campaigns::isSystem($c);
            $form = $this->formOf($c);
            $problems = [];
            if (Flight::request()->method === 'POST') {
                $this->validateCSRF();
                if ($c['locked']) { $this->flash('error', 'That message has already gone out, so it cannot be changed.'); Flight::redirect('/messaging/view?id=' . (int) $c['id']); return; }
                foreach (array_keys($form) as $k) if (($v = $this->getParam($k)) !== null) $form[$k] = trim((string) $v);
                $doc = ['subject' => $form['subject'], 'body_email' => $form['body_email'], 'body_text' => $form['body_text']];
                if ($form['subject'] === '') $problems['subject'] = 'Write a subject.';
                if ($form['body_email'] === '') $problems['body_email'] = 'Write the message.';
                if ($system) {
                    if ($c['kind'] === 'trigger') { if (!ctype_digit($form['trigger_days']) || (int) $form['trigger_days'] < 1) $problems['trigger_days'] = 'A whole number of days, 1 or more.'; else $doc['trigger_days'] = (int) $form['trigger_days']; }
                    if (!in_array($form['cadence'], ['once', 'every_n_days'], true)) $problems['cadence'] = 'Choose once or every N days.';
                    elseif ($form['cadence'] === 'every_n_days' && (!ctype_digit($form['cadence_days']) || (int) $form['cadence_days'] < 1)) $problems['cadence_days'] = 'A whole number of days, 1 or more.';
                    else { $doc['cadence'] = $form['cadence']; $doc['cadence_days'] = $form['cadence'] === 'every_n_days' ? (int) $form['cadence_days'] : 0; }
                    if (!in_array($form['status'], ['active', 'paused'], true)) $problems['status'] = 'Choose active or paused.'; else $doc['status'] = $form['status'];
                }
                if (!$problems) {
                    try {
                        Campaigns::update((int) $c['id'], $doc);
                        $this->flash('success', 'Saved.');
                        Flight::redirect('/messaging/view?id=' . (int) $c['id']);
                        return;
                    } catch (ServiceException $e) {
                        if ($e->status !== 422) throw $e;
                        $problems[Client::field($e) ?: 'subject'] = Client::said($e);
                    }
                }
            }
            $this->editPage($c, $form, $problems, null);
        } catch (ServiceException $e) { $this->unavailable($e); }
    }

    /** POST: send the admin a test copy of the saved message (or only show it when no mail is connected). */
    public function test($params = []) {
        try {
            $c = $this->campaign();
            if ($c === null) return;
            if (Flight::request()->method !== 'POST') { Flight::redirect('/messaging/edit?id=' . (int) $c['id']); return; }
            $this->validateCSRF();
            $me = Flight::getMember();
            try {
                Sync::contact((int) $me->id);
                $preview = Campaigns::test((int) $c['id'], (int) $me->id, (string) $me->email, Digest::previewExtra($c, (int) $me->id));
            } catch (\Throwable $e) {
                $this->logger->error('Messaging test send failed: ' . $e->getMessage(), ['campaign' => (int) $c['id']]);
                $this->flash('error', 'The test could not be made: ' . ($e instanceof ServiceException ? Client::said($e) : $e->getMessage()));
                Flight::redirect('/messaging/edit?id=' . (int) $c['id']);
                return;
            }
            if ($preview['sent']) { $this->flash('success', 'A test was sent to ' . $me->email . '.'); Flight::redirect('/messaging/edit?id=' . (int) $c['id']); return; }
            if ($preview['mail_ready']) $this->flash('error', 'The test could not be sent; the reason is in the service\'s log. Here is what it would have said.');
            $this->editPage($c, $this->formOf($c), [], $preview);
        } catch (ServiceException $e) { $this->unavailable($e); }
    }

    /** POST: one of the tenant's settings, through the service (which refuses a wrong value with the fix). */
    private function saveSettings(array $changes, string $saidWhenOk): void {
        $this->validateCSRF();
        try { Client::patch('settings', $changes); $this->flash('success', $saidWhenOk); }
        catch (ServiceException $e) { $this->flash('error', Client::said($e)); }
        Flight::redirect('/messaging');
    }

    /** POST: the number of messages an owner may send to one audience in 7 days. */
    public function cap($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::redirect('/messaging'); return; }
        $v = trim((string) $this->getParam('cap'));
        $this->saveSettings(['weekly_cap' => $v], 'Owners may now send ' . (int) $v . ' per audience each week.');
    }

    /** POST: may owners send texts, and the short address a text's links start with. */
    public function texting($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::redirect('/messaging'); return; }
        $v = (string) $this->getParam('owners_may_text');
        $this->saveSettings(['owners_may_text' => $v === '1', 'text_link_base' => trim((string) $this->getParam('text_link_base'))],
            $v === '1' ? 'Owners can now text members who asked for texts.' : 'Owners can no longer send texts. Reminders still text those who asked.');
    }

    /** POST: a site's from-name and the domain replies come back to. */
    public function site($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::redirect('/messaging'); return; }
        $this->validateCSRF();
        $host = strtolower(trim((string) $this->getParam('host')));
        try {
            Client::patch('sites/' . rawurlencode($host), ['from_name' => trim((string) $this->getParam('from_name')), 'reply_domain' => trim((string) $this->getParam('reply_domain'))]);
            $this->flash('success', "Saved for {$host}.");
        } catch (ServiceException $e) { $this->flash('error', Client::said($e)); }
        Flight::redirect('/messaging');
    }

    /** POST: take an address off the suppression list, so it can be mailed again. */
    public function unsuppress($params = []) {
        if (Flight::request()->method !== 'POST') { Flight::redirect('/messaging'); return; }
        $this->validateCSRF();
        try { Client::delete('suppressions/' . (int) $this->getParam('id')); $this->flash('success', 'The address can be mailed again.'); }
        catch (ServiceException $e) { $this->flash('error', Client::said($e)); }
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
        if (!preg_match('/^[a-z][a-z0-9_]*:[A-Za-z0-9_-]+$/D', $key) || !Providers::has('audiences')) { Flight::notFound(); exit; }
        $audiences = Providers::need('audiences');
        try { $label = (string) $audiences::label($key); }
        catch (\InvalidArgumentException $e) { Flight::notFound(); exit; }
        if (!$audiences::mayMessage($this->me(), $key)) $this->forbid('Only the owner of ' . $label . ' can message it.', ['scope' => $key]);
        return [$key, $label, (string) $audiences::category()];
    }

    /** The document the service makes a campaign from, as the owner wrote it; category and audience are fixed here, never read from the request body for a scoped page. */
    private function doc(?string $key, string $categoryCode, array $form, string $status): array {
        $me = Flight::getMember();
        $doc = ['category' => $categoryCode, 'kind' => $form['about'] !== '' ? 'gathering' : 'message', 'subject' => $form['subject'], 'body' => $form['body'],
                'body_sms' => $form['body_sms'], 'cadence' => 'once', 'status' => $status,
                'sender' => ['eid' => (string) $me->id, 'email' => (string) $me->email, 'name' => trim((string) $me->firstName)]];
        if ($key !== null) $doc['scope'] = $key;
        if ($form['about'] !== '') $doc['about'] = $form['about'];
        return $doc;
    }

    /**
     * The owner's page: who a message goes to, the message form, and every message sent so far (?scope= is the audience).
     * POST sends it (send=now|later) or, with `test`, mails the owner alone. Every check is the service's: a draft is made,
     * asked about, and deleted again.
     */
    public function compose($params = []) {
        [$key, $label, $categoryCode] = $this->audience();
        $audiences = $key !== null ? Providers::need('audiences') : null;
        $abouts = ($audiences !== null && Providers::can('audiences', 'abouts')) ? $audiences::abouts($key) : [];
        try {
            $settings = Client::get('settings')['settings'];
            $categories = [];
            if ($key === null) {
                foreach (Client::get('categories')['categories'] as $c) if (!in_array($c['code'], [Reach::TRANSACTIONAL, Reach::REMINDERS], true)) $categories[$c['code']] = $c['label'];
                if (!$categories) throw new \RuntimeException('Messaging: there is no message category an admin could send to everyone in. Register one (Providers categories).');
            }
            $mayText = (bool) $settings['owners_may_text'] && Providers::has('phone');
            $form = ['subject' => '', 'body' => '', 'body_sms' => '', 'about' => '', 'send' => 'now', 'scheduled_at' => '', 'category' => $key === null ? (string) array_key_first($categories) : $categoryCode];
            $problems = [];
            $notice = null;

            if (Flight::request()->method === 'POST') {
                $this->validateCSRF();
                foreach ($form as $f => $_) $form[$f] = trim((string) $this->getParam($f));
                if ($key !== null) $form['category'] = $categoryCode;
                elseif (!isset($categories[$form['category']])) $problems['category'] = 'Choose one of the kinds of message.';
                if (!$mayText) $form['body_sms'] = '';
                $isTest = $this->getParam('test') !== null;
                if ($form['about'] !== '' && !isset($abouts[$form['about']])) {
                    $problems['about'] = 'Pick one of the choices offered.';
                } elseif ($form['about'] !== '' && ($this->getParam('fill') !== null || ($form['subject'] === '' && $form['body'] === '')) && Providers::can('audiences', 'aboutDraft')) {
                    $d = $audiences::aboutDraft($form['about']);
                    $form['subject'] = (string) $d['subject'];
                    $form['body'] = (string) $d['body'];
                    $notice = 'We filled in the subject and the message. Read them, change what you like, then send.';
                } elseif (!$problems) {
                    if ($form['subject'] === '') $problems['subject'] = 'Write a subject.';
                    if ($form['body'] === '') $problems['body'] = 'Write the message.';
                    if (!in_array($form['send'], ['now', 'later'], true)) $problems['send'] = 'Choose now or at a time.';
                    $at = null;
                    if (!$isTest && $form['send'] === 'later' && !isset($problems['send'])) {
                        $at = $this->scheduledAt($form['scheduled_at']);
                        if ($at === null) $problems['scheduled_at'] = 'Pick the day and time to send it.';
                        elseif (strtotime($at) <= time()) $problems['scheduled_at'] = 'That time has passed. Pick a time ahead.';
                    }
                    if (!$problems) {
                        if ($this->getParam('texts') !== null) $notice = $this->textLine($key, $form);   // null: a refusal, now in $this->problems
                        elseif ($isTest) { if ($this->sendTest($key, $form)) return; }
                        elseif ($this->sendMessage($key, $form, $at)) return;
                    }
                }
                if ($this->problems !== null) { $problems = array_merge($problems, $this->problems); $this->problems = null; }
            }
            $used = $key !== null ? $this->weeklyCount($key) : null;
            $test = Ui::raw(($mayText ? '<button type="submit" name="texts" value="1" formnovalidate class="btn btn-outline-secondary me-2">Check the texts</button>' : '')
                . ($abouts && Providers::can('audiences', 'aboutDraft') ? '<button type="submit" name="fill" value="1" formnovalidate class="btn btn-outline-secondary me-2">Fill in from the choice above</button>' : '')
                . '<button type="submit" name="test" value="1" formnovalidate class="btn btn-outline-secondary">Send me a test</button>');
            $this->render('messaging/compose', ['title' => 'Messages to ' . $label, 'scope' => $key, 'label' => $label, 'audienceLine' => $this->audienceLine($key, $label), 'used' => $used,
                'cap' => (int) $settings['weekly_cap'], 'form' => $form, 'problems' => $problems, 'abouts' => $abouts, 'categories' => $categories, 'rows' => $this->messageRows($key),
                'notice' => $notice, 'test' => $test, 'mayText' => $mayText, 'backUrl' => $this->backUrl()]);
        } catch (ServiceException $e) { $this->unavailable($e, $this->backUrl() ?? '/'); }
    }

    /** Problems a helper found (a 422 from the service), for the page to show at the field. */
    private ?array $problems = null;

    /** A 422 as a form problem: the field the service named, or the whole form. */
    private function refused(ServiceException $e): void {
        if ($e->status !== 422) throw $e;
        $field = Client::field($e);
        $this->problems = [$field === 'body_email' ? 'body' : ($field === 'cap' || $field === '' ? 0 : $field) => Client::said($e)];
    }

    /** Where the page's back link goes: the ?back= address when it is one of this app's own pages, else the admin list for an admin. */
    private function backUrl(): ?string {
        $b = (string) $this->getParam('back');
        if (preg_match('~^/[A-Za-z0-9_-][A-Za-z0-9_/.?=&%:-]*$~', $b)) return $b;
        return Flight::hasLevel(LEVELS['ADMIN']) ? '/messaging' : null;
    }

    /** "Goes to the 58 members of Paranormal who have these messages on." */
    private function audienceLine(?string $key, string $label): string {
        if ($key === null) return 'Goes to everyone who can be emailed and has not switched these messages off.';
        $n = count(array_unique(array_map('intval', Providers::need('audiences')::members($key))));
        return "Goes to the $n members of $label who have these messages on (each can switch them off).";
    }

    /** Past and scheduled messages of this audience (and the reminders of what it is about), newest first. */
    private function messageRows(?string $key): array {
        $rows = [];
        foreach (Campaigns::list(['scope' => $key ?? 'none']) as $c) {
            if (!in_array($c['kind'], ['message', 'gathering'], true)) continue;
            $rows[] = $this->row($c);
        }
        return $rows;
    }

    /** Owner messages sent or scheduled for this audience in the last 7 days (the ones the cap counts; reminders and drafts are not). */
    private function weeklyCount(string $key): int {
        $n = 0;
        $since = time() - 7 * 86400;
        foreach (Campaigns::list(['scope' => $key]) as $c) {
            if (!in_array($c['kind'], ['message', 'gathering'], true) || $c['status'] === 'draft' || $c['category'] === Reach::REMINDERS) continue;
            if (strtotime((string) $c['created_at']) >= $since) $n++;
        }
        return $n;
    }

    /** "42 texts, 1 part each" — what the text would cost, before it is sent: a draft is made, asked, and deleted. */
    private function textLine(?string $key, array $form): ?string {
        if ($form['body_sms'] === '') return 'No text is written, so no text goes out.';
        try { $draft = Campaigns::create($this->doc($key, $form['category'], $form, 'draft')); }
        catch (ServiceException $e) { $this->refused($e); return null; }
        try {
            $t = Campaigns::text((int) $draft['id'], $this->me());
            $n = Campaigns::audience((int) $draft['id'])['texts'];
        } catch (ServiceException $e) { Campaigns::delete((int) $draft['id']); $this->refused($e); if ($this->problems !== null) { $this->problems = ['body_sms' => array_values($this->problems)[0]]; } return null; }
        Campaigns::delete((int) $draft['id']);
        if ($t === null) return 'This message sends no text.';
        if ($t['parts'] > 1) { $this->problems = ['body_sms' => 'That text is ' . $t['parts'] . ' parts once the STOP line is added. A text may be one part (160 characters, or 70 with accents or emoji): shorten it.']; return null; }
        return $n . ($n === 1 ? ' text, ' : ' texts, ') . '1 part each. Only members who asked for texts get one. Nothing is sent until you press "Send to members".';
    }

    private function scheduledAt(string $value): ?string {
        if ($value === '') return null;
        $name = trim((string) Flight::getSetting('timezone', $this->me()));
        $tz = new \DateTimeZone($name !== '' ? $name : date_default_timezone_get());
        $d = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, $tz) ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i', $value, $tz);
        if (!$d) return null;
        return $d->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /** True when answered (a redirect); false when the service refused the words, which are now in $this->problems. */
    private function sendTest(?string $key, array $form): bool {
        $me = Flight::getMember();
        try {
            Sync::contact((int) $me->id);
            $draft = Campaigns::create($this->doc($key, $form['category'], $form, 'draft'));
        } catch (ServiceException $e) { $this->refused($e); return false; }
        try { $r = Campaigns::test((int) $draft['id'], (int) $me->id, (string) $me->email); }
        finally { Campaigns::delete((int) $draft['id']); }
        if (!$r['mail_ready']) $this->flash('error', 'Mail is not connected for this site in the messaging service, so no test could be sent.');
        elseif ($r['sent']) $this->flash('success', 'A test went to ' . $me->email . '.');
        else $this->flash('error', 'The test could not be sent. The reason is in the service\'s log.');
        Flight::redirect($this->composeUrl($key));
        return true;
    }

    private function composeUrl(?string $key): string {
        return '/messaging/compose' . ($key !== null ? '?scope=' . urlencode($key) : '');
    }

    /** True when answered (a redirect); false when the service refused (the cap, the words), now in $this->problems. */
    private function sendMessage(?string $key, array $form, ?string $later): bool {
        $doc = $this->doc($key, $form['category'], $form, 'scheduled');
        $doc['scheduled_at'] = $later ?? date('Y-m-d H:i:s');
        try { $c = Campaigns::create($doc); }
        catch (ServiceException $e) { $this->refused($e); return false; }
        $note = '';
        if ($form['about'] !== '') $note = ' ' . $this->remindThose($form['about'], $key);
        if ($later !== null) {
            $this->flash('success', 'Your message is scheduled.' . $note);
        } else {
            $stats = Campaigns::send((int) $c['id'])['result'];
            $t = $stats['texts'];
            if ($t['recipients'] > 0 && $t['sent'] < $t['recipients']) $note .= ' Of ' . $t['recipients'] . ' texts, ' . $t['sent'] . ' went out; the reason is in the service\'s log.';
            elseif ($t['sent'] > 0) $note .= ' ' . $t['sent'] . ' texts went out.';
            if ($stats['not_sent'] > 0) $this->flash('error', 'Mail is not connected for this site in the messaging service: ' . $stats['not_sent'] . ' recipients were recorded but nothing was sent.' . $note);
            elseif ($stats['failed'] > 0) $this->flash('error', $stats['failed'] . ' of ' . $stats['recipients'] . ' emails could not be sent; the reason is in the service\'s log.' . $note);
            else $this->flash('success', 'Sent to ' . $stats['sent'] . ' of ' . $stats['recipients'] . ' members.' . $note);
        }
        Flight::redirect($this->composeUrl($key));
        return true;
    }

    /** A message about a thing also schedules its reminder for the people the app says are in it, when the app offers one. Said in one sentence. */
    private function remindThose(string $aboutKey, ?string $scope): string {
        if (!Providers::can('audiences', 'reminder')) return '';
        foreach (Campaigns::list(['about' => $aboutKey, 'kind' => 'gathering']) as $c) if ($c['category'] === Reach::REMINDERS) return 'Its reminder was already scheduled.';
        $rem = Providers::need('audiences')::reminder($aboutKey);
        if ($rem === null) return 'There is no reminder for it.';
        if ((int) $rem['at'] <= time()) return 'It is too soon for a reminder.';
        $me = Flight::getMember();
        $doc = ['category' => Reach::REMINDERS, 'kind' => 'gathering', 'about' => $aboutKey, 'subject' => (string) $rem['subject'], 'body' => (string) $rem['body'], 'body_sms' => (string) $rem['sms'],
                'cadence' => 'once', 'status' => 'scheduled', 'scheduled_at' => date('Y-m-d H:i:s', (int) $rem['at']),
                'sender' => ['eid' => (string) $me->id, 'email' => (string) $me->email, 'name' => trim((string) $me->firstName)]];
        if ($scope !== null) $doc['scope'] = $scope;   // listed with the audience's messages; the reminder itself goes to those in aboutKey
        try { Campaigns::create($doc); }
        catch (ServiceException $e) { $this->logger->error('ERROR Messaging: the reminder could not be scheduled: ' . $e->getMessage()); return 'Its reminder could not be scheduled: ' . Client::said($e); }
        return 'A reminder goes to those it is for, on ' . date('M j, g:i a', (int) $rem['at']) . '.';
    }

    /** One message of an audience the member may message, with its body and how many times each link was clicked (?id= is the campaign). */
    public function message($params = []) {
        try {
            $c = $this->campaign();
            if ($c === null) return;
            if (!in_array($c['kind'], ['message', 'gathering'], true)) { Flight::notFound(); return; }
            $key = (string) ($c['scope'] ?? '');
            if ($key === '') {
                if (!Flight::hasLevel(LEVELS['ADMIN'])) $this->forbid('Only an admin can see a message to everyone.');
            } elseif (!Providers::has('audiences') || !Providers::need('audiences')::mayMessage($this->me(), $key)) {
                $this->forbid('Only the owner of ' . $this->whereLabel($key) . ' can see its messages.', ['scope' => $key]);
            }
            $links = Campaigns::get((int) $c['id'])['links'];
        } catch (ServiceException $e) { $this->unavailable($e); return; }
        $this->render('messaging/message', ['title' => $c['subject'], 'c' => $c, 'where' => $this->whereLabel($key !== '' ? $key : null), 'composeUrl' => $this->composeUrl($key !== '' ? $key : null), 'links' => $links]);
    }
}
