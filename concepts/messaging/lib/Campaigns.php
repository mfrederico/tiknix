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

    /** @return array{subject:string,html:string,text:string} */
    public static function preview(int $id, int $memberId): array { return Client::get("campaigns/{$id}/preview", ['contact' => (string) $memberId])['preview']; }

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
}
