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
            // the project may have several sites (one per domain, each its own database): this page is one of them
            $sites = array_values(array_filter(Client::get('sites')['sites'], fn($s) => (string) $s['host'] === Client::site()));
        } catch (ServiceException $e) { $this->unavailable($e); return; }
        $this->render('messaging/index', ['title' => 'Messages', 'rows' => array_map(fn($c) => $this->row($c), $all),
            'categories' => array_map(fn($c) => (object) ['label' => $c['label'], 'description' => $c['description'], 'default' => $c['default_on'] ? 'On' : 'Off',
                'optout' => $c['can_opt_out'] ? 'Members can switch it off' : 'Always on', 'channels' => implode(', ', $c['channels'])], $categories),
            'suppressed' => array_map(fn($s) => (object) $s, $suppressed), 'settings' => $settings, 'sites' => $sites,
            'providers' => self::PROVIDERS, 'have' => Providers::registered(), 'serviceUrl' => Client::url()]);
    }

    /** One campaign: its message, counts, the clicks on each link, who got it and what they did, and the copy as you would get it. */
    public function view($params = []) {
        try {
            $c = $this->campaign();
            if ($c === null) return;
            $links = Campaigns::get((int) $c['id'])['links'];
            $recipients = $this->recipientRows(Campaigns::recipients((int) $c['id']));
        } catch (ServiceException $e) { $this->unavailable($e); return; }
        $custom = $c['kind'] === 'trigger' && $c['trigger_code'] === Providers::TRIGGER_PIPELINE;
        $audience = null;
        if (!$custom && !$c['locked'] && in_array($c['kind'], ['message', 'gathering', 'trigger'], true) && !$c['content_from_app']) {
            try { $audience = Campaigns::audience((int) $c['id']); }
            catch (\Throwable $e) { $this->logger->error('Messaging: the audience could not be counted: ' . $e->getMessage(), ['campaign' => (int) $c['id']]); }
        }
        $this->render('messaging/view', ['title' => $c['subject'], 'c' => $c, 'row' => $this->row($c), 'links' => $links, 'recipients' => $recipients,
            'preview' => $this->previewFor($c), 'unavailable' => $c['kind'] === 'digest' && !Providers::has('digest'),
            'custom' => $custom, 'on' => in_array($c['status'], ['active', 'scheduled', 'sending', 'sent'], true), 'audience' => $audience,
            'feeders' => $custom ? $this->feeders($c) : [], 'reactors' => $this->reactors($c)]);
    }

    /**
     * GET: the form for a new message — when it goes out first, then the words. POST: make it in the service, paused (a dated
     * one as a draft), and go to its page to test it and turn it on. Admins (the page's permission row).
     */
    public function new($params = []) {
        try {
            $categories = [];
            foreach (Client::get('categories')['categories'] as $cat) if (!in_array($cat['code'], [Reach::TRANSACTIONAL, Reach::REMINDERS], true)) $categories[$cat['code']] = $cat['label'];
            if (!$categories) throw new \RuntimeException('Messaging: there is no kind of message an admin could write. Register one (Providers categories).');
            $form = ['when' => 'date', 'scheduled_at' => '', 'cadence_days' => '', 'trigger_days' => '', 'code' => '', 'category' => array_key_first($categories), 'subject' => '', 'body_email' => '', 'body_text' => ''];
            $problems = [];
            if (Flight::request()->method === 'POST') {
                $this->validateCSRF();
                foreach (array_keys($form) as $k) if (($v = $this->getParam($k)) !== null) $form[$k] = trim((string) $v);
                if (!isset($categories[$form['category']])) $problems['category'] = 'Pick a kind of message.';
                [$doc, $whenProblems] = Campaigns::whenDoc($form + ['on' => '0'], null, fn(string $v) => $this->scheduledAt($v));
                $problems += $whenProblems;
                if (!$problems) {
                    $me = Flight::getMember();
                    $doc += ['category' => $form['category'], 'sender' => ['eid' => (string) $me->id, 'email' => (string) $me->email, 'name' => trim((string) $me->firstName)]];
                    try {
                        $c = Campaigns::create($doc);
                        $this->flash('success', 'Saved, paused. Send yourself a test, then turn it on.');
                        Flight::redirect('/messaging/view?id=' . (int) $c['id']);
                        return;
                    } catch (ServiceException $e) {
                        if ($e->status !== 422) throw $e;
                        $f = Client::field($e);
                        $problems[$f !== '' && array_key_exists($f, $form) ? $f : 'subject'] = Client::said($e);
                    }
                }
            }
            $this->render('messaging/new', ['title' => 'A new message', 'form' => $form, 'problems' => $problems, 'categories' => $categories]);
        } catch (ServiceException $e) { $this->unavailable($e); }
    }

    /** POST: turn a message on or off (?id=, on=1|0) — the last step on its page, after the test. */
    public function status($params = []) {
        try {
            $c = $this->campaign();
            if ($c === null) return;
            if (Flight::request()->method !== 'POST') { Flight::redirect('/messaging/view?id=' . (int) $c['id']); return; }
            $this->validateCSRF();
            if ($c['locked']) { $this->flash('error', 'That message has already gone out.'); Flight::redirect('/messaging/view?id=' . (int) $c['id']); return; }
            [$status, $why] = Campaigns::statusFor($c, (string) $this->getParam('on') === '1');
            if ($status === null) { $this->flash('error', $why); Flight::redirect('/messaging/view?id=' . (int) $c['id']); return; }
            try { Campaigns::update((int) $c['id'], ['status' => $status]); $this->flash('success', $status === 'draft' || $status === 'paused' ? 'Paused.' : 'On.'); }
            catch (ServiceException $e) { if ($e->status !== 422) throw $e; $this->flash('error', Client::said($e)); }
            Flight::redirect('/messaging/view?id=' . (int) $c['id']);
        } catch (ServiceException $e) { $this->unavailable($e); }
    }

    /**
     * The pipelines that start on a message event (trigger.event message.*): every message's, or narrowed to this one when
     * its definition names the message's code. @return array<int,array{slug:string,name:string,events:string,scope:string}>
     */
    private function reactors(array $c): array {
        if (!class_exists('\\app\\Pipeline\\Runner')) return [];
        $out = [];
        try { $all = \app\Pipeline\Runner::list(); } catch (\Throwable $e) { $this->logger->error('Messaging: the pipelines could not be listed: ' . $e->getMessage()); return []; }
        foreach ($all as $p) {
            $slug = (string) ($p['slug'] ?? '');
            $def = $slug !== '' ? \app\Pipeline\Runner::get($slug) : null;
            if (!is_array($def)) continue;
            $events = array_values(array_filter((array) ($def['trigger']['event'] ?? []), fn($e) => is_string($e) && str_starts_with($e, 'message.')));
            if (!$events) continue;
            $code = (string) ($c['code'] ?? '');
            $names = $code !== '' && str_contains(json_encode($def['steps'] ?? [], JSON_UNESCAPED_SLASHES), $code);
            $out[] = ['slug' => $slug, 'name' => (string) ($def['name'] ?? $slug), 'events' => implode(', ', array_map(fn($e) => substr($e, 8), $events)),
                      'scope' => $names ? 'this message (' . $code . ')' : 'every message'];
        }
        return $out;
    }

    /** The copy the signed-in member would get (a system message filled with what the app gives them now); null, with the reason logged, when it cannot be made. */
    private function previewFor(array $c): ?array {
        $me = (int) $this->member->id;
        try {
            Sync::contact($me);
            return Campaigns::preview((int) $c['id'], $me, Digest::previewExtra($c, $me));
        } catch (\Throwable $e) {
            $this->logger->error('Messaging preview could not be made: ' . $e->getMessage(), ['campaign' => (int) $c['id']]);
            return ['error' => $e instanceof ServiceException ? Client::said($e) : $e->getMessage()];
        }
    }

    /** The service's recipient rows with the member's name from this app, for the table. */
    private function recipientRows(array $rows): array {
        $names = [];
        $ids = array_values(array_unique(array_filter(array_map(fn($r) => (int) $r['contact'], $rows))));
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            // the columns every member table has; a display_name, when the app keeps one, wins
            $cols = array_keys(\app\Bean::inspect('member'));
            $pick = in_array('display_name', $cols, true) ? 'display_name, first_name, username' : 'first_name, username';
            foreach (\app\Bean::getAll("SELECT id, {$pick} FROM member WHERE id IN ($in)", $chunk) as $m) {
                $names[(int) $m['id']] = trim((string) ($m['display_name'] ?? '')) ?: (trim((string) $m['first_name']) ?: (string) $m['username']);
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $did = [];
            if ($r['opened']) $did[] = 'opened';
            if ($r['clicks'] > 0) $did[] = 'clicked' . ($r['clicks'] > 1 ? " ×{$r['clicks']}" : '');
            if ($r['replies'] > 0) $did[] = 'replied';
            if ($r['acted_at'] !== '') $did[] = 'acted';
            if ($r['unsubscribed_at'] !== '') $did[] = 'unsubscribed';
            if ($r['bounced_at'] !== '') $did[] = 'bounced';
            if ($r['complained_at'] !== '') $did[] = 'complained';
            $state = $r['bounced_at'] !== '' ? 'bounced' : ($r['delivered_at'] !== '' ? 'delivered' : ($r['sent_at'] !== '' ? 'sent' : 'not sent'));
            $out[] = (object) ['member' => (int) $r['contact'], 'name' => $names[(int) $r['contact']] ?? ($r['first_name'] !== '' ? $r['first_name'] : 'Member #' . $r['contact']), 'email' => $r['email'],
                'channel' => $r['channel'], 'state' => $state, 'did' => $did ? implode(', ', $did) : '—', 'when' => $r['sent_at'] !== '' ? $r['sent_at'] : $r['created_at']];
        }
        return $out;
    }

    /** The form's shape: the words, then WHEN it goes out as one choice with its one follow-up, then on/off. */
    private function formOf(array $c): array {
        $when = 'date';
        if ($c['kind'] === 'digest') $when = 'digest';
        elseif ($c['kind'] === 'trigger') $when = $c['trigger_code'] === Providers::TRIGGER_PIPELINE ? 'custom' : ($c['trigger_code'] === 'joined_days' ? 'joined' : ($c['trigger_code'] === 'inactive_days' ? 'inactive' : 'app'));
        elseif ($c['cadence'] === 'every_n_days') $when = 'repeat';
        $on = in_array($c['status'], ['active', 'scheduled', 'sending', 'sent'], true);
        return ['subject' => $c['subject'], 'body_email' => $c['body_email'], 'body_text' => $c['body_text'], 'when' => $when,
            'scheduled_at' => $c['scheduled_at'] !== '' ? substr(str_replace(' ', 'T', $c['scheduled_at']), 0, 16) : '',
            'cadence_days' => $c['cadence_days'] > 0 ? (int) $c['cadence_days'] : '', 'trigger_days' => $c['trigger_days'] > 0 ? (int) $c['trigger_days'] : '',
            'code' => $c['code'], 'on' => $on ? '1' : '0'];
    }

    /** The pipelines of this app whose messaging step names this campaign (by code or id). @return array<int,array{slug:string,name:string,trigger:string}> */
    private function feeders(array $c): array {
        if (!class_exists('\\app\\Pipeline\\Runner')) return [];
        $out = [];
        try { $all = \app\Pipeline\Runner::list(); } catch (\Throwable $e) { $this->logger->error('Messaging: the pipelines could not be listed: ' . $e->getMessage()); return []; }
        foreach ($all as $p) {
            $slug = (string) ($p['slug'] ?? '');
            $def = $slug !== '' ? \app\Pipeline\Runner::get($slug) : null;
            if (!is_array($def)) continue;
            foreach ((array) ($def['steps'] ?? []) as $st) {
                if (($st['type'] ?? '') !== 'messaging') continue;
                $ref = trim((string) ($st['config']['campaign'] ?? ''));
                if ($ref === '' || ($ref !== (string) $c['id'] && $ref !== (string) $c['code'])) continue;
                $t = (array) ($def['trigger'] ?? []);
                $how = isset($t['cron']) ? 'on a schedule (' . $t['cron'] . ')' : (isset($t['event']) ? 'when the app says ' . implode(', ', (array) $t['event']) : (isset($t['connector']) ? 'when ' . implode(', ', array_keys((array) $t['connector'])) . ' reports a change' : 'by hand'));
                $out[] = ['slug' => $slug, 'name' => (string) ($def['name'] ?? $slug), 'trigger' => $how];
                break;
            }
        }
        return $out;
    }

    private function editPage(array $c, array $form, array $problems, ?array $preview): void {
        $this->render('messaging/edit', ['title' => 'Edit this message', 'c' => $c, 'system' => Campaigns::isSystem($c), 'locked' => $c['locked'], 'form' => $form, 'problems' => $problems, 'preview' => $preview,
            'appTrigger' => $form['when'] === 'app' ? (Providers::triggerLabels()[$c['trigger_code']] ?? $c['trigger_code']) : null,
            'feeders' => $form['when'] === 'custom' ? $this->feeders($c) : []]);
    }

    /**
     * GET: the form. POST: save it — the words, when it goes out (one choice and its follow-up), on or off. The service checks
     * the words: a {variable} nothing can fill is refused by field.
     */
    public function edit($params = []) {
        try {
            $c = $this->campaign();
            if ($c === null) return;
            $form = $this->formOf($c);
            $problems = [];
            if (Flight::request()->method === 'POST') {
                $this->validateCSRF();
                if ($c['locked']) { $this->flash('error', 'That message has already gone out, so it cannot be changed.'); Flight::redirect('/messaging/view?id=' . (int) $c['id']); return; }
                $fixed = in_array($form['when'], ['digest', 'app'], true) ? $form['when'] : null;   // the digest and an app-evaluated nudge keep their kind
                foreach (array_keys($form) as $k) if (($v = $this->getParam($k)) !== null) $form[$k] = trim((string) $v);
                if ($fixed !== null) $form['when'] = $fixed;
                [$doc, $problems] = Campaigns::whenDoc($form, $c, fn(string $v) => $this->scheduledAt($v));
                if (!$problems) {
                    try {
                        Campaigns::update((int) $c['id'], $doc);
                        $this->flash('success', 'Saved.');
                        Flight::redirect('/messaging/view?id=' . (int) $c['id']);
                        return;
                    } catch (ServiceException $e) {
                        if ($e->status !== 422) throw $e;
                        $f = Client::field($e);
                        $problems[$f !== '' && array_key_exists($f, $form) ? $f : 'subject'] = Client::said($e);
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
            if ($preview['sent']) { $this->flash('success', 'A test was sent to ' . $me->email . '.'); Flight::redirect('/messaging/view?id=' . (int) $c['id']); return; }
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

    // ---- the calendar: when messages go out, what came of the ones that did --------------------------

    /** An admin sees every message; an owner (?scope=) their audience's — the same rule as compose. [key, label] */
    private function calendarScope(): array {
        $key = trim((string) $this->getParam('scope'));
        if ($key === '') {
            if (!Flight::hasLevel(LEVELS['ADMIN'])) $this->forbid('Only an admin can see every message.');
            return [null, 'Everyone'];
        }
        [$k, $label] = $this->audience();
        return [$k, $label];
    }

    /** GET /messaging/calendar[?scope=] — the page; its days come from calendardata. */
    public function calendar($params = []) {
        [$key, $label] = $this->calendarScope();
        $this->render('messaging/calendar', ['title' => 'Message calendar', 'scope' => $key, 'label' => $label,
            'backUrl' => $key !== null ? '/messaging/compose?scope=' . urlencode($key) : '/messaging']);
    }

    /** GET /messaging/calendardata?from=&to=[&scope=] — the service's calendar, as JSON for the page. */
    public function calendardata($params = []) {
        [$key] = $this->calendarScope();
        $from = (string) $this->getParam('from', date('Y-m-01'));
        $to = (string) $this->getParam('to', date('Y-m-t'));
        try { Flight::json(['ok' => true] + Campaigns::calendar($from, $to, $key)); }
        catch (ServiceException $e) { Flight::json(['ok' => false, 'error' => Client::said($e)], $e->status === 422 ? 422 : 503); }
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
            $recipients = $this->recipientRows(Campaigns::recipients((int) $c['id']));
        } catch (ServiceException $e) { $this->unavailable($e); return; }
        $this->render('messaging/message', ['title' => $c['subject'], 'c' => $c, 'where' => $this->whereLabel($key !== '' ? $key : null), 'composeUrl' => $this->composeUrl($key !== '' ? $key : null), 'links' => $links,
            'recipients' => $recipients, 'preview' => $this->previewFor($c)]);
    }
}
