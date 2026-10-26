<?php
namespace app\concepts\messaging;

/**
 * Campaigns — the service's campaigns, from this app: a message with a DERIVED audience (the service answers who,
 * from the mirrored audiences, each contact's switches and its ledger), sent through the connection this app shared.
 * These are thin: the engine is the service's. What the app adds is who may write (the audiences provider's
 * mayMessage), the words of a message about a thing (aboutDraft, reminder), and crediting an action to a click.
 */
class Campaigns {

    public const KINDS = ['message', 'gathering', 'digest', 'trigger'];
    public const SYSTEM = ['digest', 'trigger'];

    /** @return array<int,array> campaigns, newest first; $q narrows: status, kind, scope ('none' = to everyone), about */
    public static function list(array $q = []): array { return Client::get('campaigns', $q)['campaigns']; }

    /** @return array{campaign:array,links:array} */
    public static function get(int $id): array { $r = Client::get("campaigns/{$id}"); return ['campaign' => $r['campaign'], 'links' => $r['links']]; }

    public static function create(array $doc): array { return Client::post('campaigns', $doc)['campaign']; }

    /** A campaign by its id or its code (the name the app and its pipelines use); throws when there is none. */
    public static function byRef(string $ref): array {
        $ref = trim($ref);
        if (ctype_digit($ref)) return Client::get("campaigns/{$ref}")['campaign'];
        $list = Client::get('campaigns', ['code' => $ref])['campaigns'];
        if (!$list) throw new \InvalidArgumentException("no campaign has the code '{$ref}' (set it on /messaging → the message → Edit)");
        return $list[0];
    }

    public static function update(int $id, array $doc): array { return Client::patch("campaigns/{$id}", $doc)['campaign']; }

    /** A draft only. */
    public static function delete(int $id): void { Client::delete("campaigns/{$id}"); }

    /** Send it now. @return array{result:array,campaign:array} */
    public static function send(int $id): array { $r = Client::post("campaigns/{$id}/send"); return ['result' => $r['result'], 'campaign' => $r['campaign']]; }

    /** One copy to an address, nothing recorded. @return array{sent:bool,mail_ready:bool,subject:string,html:string,text:string} */
    public static function test(int $id, int $memberId, string $to, array $extra = []): array {
        $body = ['contact' => (string) $memberId, 'to' => $to];
        if (isset($extra['vars']['headline'])) $body['headline'] = $extra['vars']['headline'];
        if (isset($extra['html']['content_html'])) $body['content_html'] = $extra['html']['content_html'];
        if (isset($extra['text']['content_text'])) $body['content_text'] = $extra['text']['content_text'];
        return Client::post("campaigns/{$id}/test", $body)['test'];
    }

    /** @return array{subject:string,html:string,text:string} the copy one member would get; $extra = Digest::previewExtra for a system message */
    public static function preview(int $id, int $memberId, array $extra = []): array {
        $q = ['contact' => (string) $memberId];
        if (isset($extra['vars']['headline'])) $q['headline'] = $extra['vars']['headline'];
        if (isset($extra['html']['content_html'])) $q['content_html'] = $extra['html']['content_html'];
        if (isset($extra['text']['content_text'])) $q['content_text'] = $extra['text']['content_text'];
        return Client::get("campaigns/{$id}/preview", $q)['preview'];
    }

    /** Every recipient of a campaign and what happened to their copy, newest first (the service's rows; `contact` is the member id). */
    public static function recipients(int $id): array { return Client::get("campaigns/{$id}/recipients")['recipients']; }

    /** Attach a file (its bytes) to a message: it goes with every copy. The service refuses a kind or a size it does not take, by name. */
    public static function attach(int $id, string $name, string $type, string $bytes): array {
        return Client::post("campaigns/{$id}/attachments", ['name' => $name, 'type' => $type, 'data' => base64_encode($bytes)])['attachment'];
    }

    public static function detach(int $id, int $attachmentId): void { Client::delete("campaigns/{$id}/attachments?attachment={$attachmentId}"); }

    /**
     * What one member did with a message (by code or id): the recipient row — sent_at, delivered_at, opened, clicks, replies,
     * unsubscribed_at, bounced_at, complained_at — or null when they never got it. A pipeline's "if they did not open it".
     */
    public static function recipient(string $ref, int $memberId): ?array {
        $c = self::byRef($ref);
        $rows = Client::get("campaigns/{$c['id']}/recipients", ['contact' => (string) $memberId])['recipients'];
        return $rows ? $rows[0] + ['campaign' => (int) $c['id']] : null;
    }

    /** @return array{count:int,contacts:string[],texts:int} who would get it now */
    public static function audience(int $id): array { $r = Client::get("campaigns/{$id}/audience"); return ['count' => $r['count'], 'contacts' => $r['contacts'], 'texts' => $r['texts']]; }

    /** The text it would send one member and its parts; null when it sends no text. */
    public static function text(int $id, int $memberId): ?array { return Client::get("campaigns/{$id}/text", ['contact' => (string) $memberId])['text']; }

    public static function isSystem(array $c): bool { return in_array((string) $c['kind'], self::SYSTEM, true); }

    /**
     * When messages went out and when they will, between two dates (Y-m-d, at most 93 days): the service's answer —
     * past: per campaign per day (sent, delivered, opened, engaged, unsubscribed, bounced); future: per campaign per day
     * with an estimate; hourly: the app-fed nudges, with how many are eligible. $scope narrows it to one audience.
     */
    public static function calendar(string $from, string $to, ?string $scope = null): array {
        $q = ['from' => $from, 'to' => $to];
        if ($scope !== null) $q['scope'] = $scope;
        return Client::get('calendar', $q)['calendar'];
    }

    /**
     * An action a member took in the app, credited to the campaign whose link they clicked in the last 48 hours.
     * Nothing to credit is normal (null). A service that cannot be reached is an ERROR in the log, never a broken page:
     * the action itself (the post, the RSVP) already happened.
     */
    public static function acted(int $memberId, string $kind, array $meta = []): ?int {
        try {
            $r = Client::post('events', ['contact' => (string) $memberId, 'kind' => $kind, 'meta' => $meta]);
            return $r['credited'] ? (int) $r['send'] : null;
        } catch (ServiceException $e) {
            if ($e->status === 404) return null;   // a member the service has not met has no click to credit
            \Flight::get('log')?->error('ERROR Campaigns::acted: ' . $e->getMessage(), ['member' => $memberId, 'kind' => $kind]);
            return null;
        }
    }

    /** Plain text an owner typed -> the HTML body (the service does the same for `body`; here for a reminder's words). */
    public static function bodyHtml(string $text): string {
        $html = htmlspecialchars(str_replace("\r", '', trim($text)), ENT_QUOTES, 'UTF-8');
        $html = preg_replace_callback('~(?<![">=])(https?://[^\s<]+|\{[a-z]+\.(?:url|link)\})~', fn($m) => '<a href="' . $m[1] . '">' . $m[1] . '</a>', $html);
        $paras = array_map(fn($p) => '<p>' . nl2br($p, false) . '</p>', preg_split('/\n{2,}/', $html));
        return implode("\n", $paras);
    }

    /** The choices of WHEN a message goes out, as the pages name them, with the one follow-up field each needs. */
    public const WHEN = ['date' => 'scheduled_at', 'repeat' => 'cadence_days', 'inactive' => 'trigger_days', 'joined' => 'trigger_days', 'custom' => 'code'];

    /** A datetime-local value ("2026-11-01T09:00") as the service's "Y-m-d H:i:s"; null when empty or not a time. */
    public static function scheduledAt(string $v): ?string {
        $v = trim($v);
        if ($v === '') return null;
        $t = strtotime(str_replace('T', ' ', $v));
        return $t === false ? null : date('Y-m-d H:i:s', $t);
    }

    /**
     * What a form says about WHEN a message goes out, as the service's document — and what is wrong with it, by field.
     * One choice ($form['when']: date | repeat | inactive | joined | custom | app | digest) and its follow-up; $form['on']
     * ('1'|'0') makes it live or paused (a dated one: scheduled or a draft). $c is the message as it is, or null for a new
     * one. The words (subject, body_email, body_text) are checked here too.
     * @return array{0:array,1:array<string,string>} [doc, problems]
     */
    public static function whenDoc(array $form, ?array $c, ?callable $toTime = null): array {
        $toTime = $toTime ?? [self::class, 'scheduledAt'];   // the page passes one that knows the member's timezone
        $problems = [];
        $doc = ['subject' => trim((string) ($form['subject'] ?? '')), 'body_email' => trim((string) ($form['body_email'] ?? '')), 'body_text' => trim((string) ($form['body_text'] ?? '')), 'body_sms' => trim((string) ($form['body_sms'] ?? ''))];
        if ($doc['subject'] === '') $problems['subject'] = 'Write a subject.';
        if ($doc['body_email'] === '') $problems['body_email'] = 'Write the message.';
        $on = (string) ($form['on'] ?? '0') === '1';
        $days = fn(string $k): bool => ctype_digit((string) ($form[$k] ?? '')) && (int) $form[$k] >= 1;
        $keepCadence = ['cadence' => $c !== null && $c['cadence'] === 'every_n_days' && $c['cadence_days'] > 0 ? 'every_n_days' : 'once', 'cadence_days' => $c !== null ? (int) $c['cadence_days'] : 0];
        switch ((string) ($form['when'] ?? '')) {
            case 'date':
                $at = $toTime((string) ($form['scheduled_at'] ?? ''));
                if ($on && $at === null) $problems['scheduled_at'] = 'Pick the day and time it goes out.';
                elseif ($on && strtotime($at) <= time()) $problems['scheduled_at'] = 'That time has passed. Pick a time ahead.';
                else { $doc += ['kind' => $c !== null && $c['kind'] === 'gathering' ? 'gathering' : 'message', 'cadence' => 'once', 'cadence_days' => 0, 'status' => $on ? 'scheduled' : 'draft']; if ($at !== null) $doc['scheduled_at'] = $at; }
                break;
            case 'repeat':
                if (!$days('cadence_days')) $problems['cadence_days'] = 'A whole number of days, 1 or more.';
                else $doc += ['kind' => 'message', 'cadence' => 'every_n_days', 'cadence_days' => (int) $form['cadence_days'], 'status' => $on ? 'active' : 'paused'];
                break;
            case 'inactive': case 'joined':
                if (!$days('trigger_days')) $problems['trigger_days'] = 'A whole number of days, 1 or more.';
                else $doc += ['kind' => 'trigger', 'trigger_code' => $form['when'] === 'joined' ? 'joined_days' : 'inactive_days', 'trigger_days' => (int) $form['trigger_days'], 'status' => $on ? 'active' : 'paused'] + $keepCadence;
                break;
            case 'custom':
                if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/D', (string) ($form['code'] ?? ''))) $problems['code'] = 'A short name a pipeline can use: lowercase letters, digits and _, like no_friends.';
                else $doc += ['kind' => 'trigger', 'trigger_code' => Providers::TRIGGER_PIPELINE, 'code' => (string) $form['code'], 'status' => $on ? 'active' : 'paused'] + $keepCadence;
                break;
            case 'app':
                if ($c === null) $problems['when'] = 'A nudge the app evaluates is declared in code, not made here.';
                elseif (!$days('trigger_days')) $problems['trigger_days'] = 'A whole number of days, 1 or more.';
                else $doc += ['trigger_days' => (int) $form['trigger_days'], 'status' => $on ? 'active' : 'paused'];
                break;
            case 'digest':
                if ($c === null) $problems['when'] = 'The digest is declared in code, not made here.';
                else $doc += ['status' => $on ? 'active' : 'paused'];
                break;
            default:
                $problems['when'] = 'Choose when it goes out.';
        }
        return [$doc, $problems];
    }

    /** The status that turns a message on or off, given what it is; null with a reason when it cannot be turned on as it stands. */
    public static function statusFor(array $c, bool $on): array {
        if (!$on) return [$c['kind'] === 'message' && $c['cadence'] !== 'every_n_days' ? 'draft' : 'paused', null];
        if ($c['kind'] === 'message' && $c['cadence'] !== 'every_n_days') {
            if ($c['scheduled_at'] === '' || strtotime($c['scheduled_at']) <= time()) return [null, 'Set the day and time it goes out on Edit first.'];
            return ['scheduled', null];
        }
        return ['active', null];
    }
}
