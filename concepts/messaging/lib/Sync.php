<?php
namespace app\concepts\messaging;

use app\Bean;

/**
 * Sync — the app's side of the mirror: its members become the service's contacts, its kinds of message its
 * categories, its audiences (the audiences provider's all()) the service's audiences, and the platform's own
 * messages the app declared (digest, nudges) exist there once, paused. Runs hourly (Runner), and for one member
 * whenever a page needs them in the service now (the preferences page, a proved phone).
 *
 * The app is the source for people and groups; the service is the source for choices (a member's switches, an
 * audience's switch once they are in) and for everything that was sent.
 */
class Sync {

    public const BATCH = 500;

    /** Everything, in the order the service needs it. @return string[] what was done */
    public static function run(): array {
        $lines = [];
        $lines[] = 'categories: ' . self::categories() . ' kept in line';
        $c = self::contacts();
        $lines[] = "contacts: {$c['created']} new, {$c['updated']} brought up to date";
        $lines[] = 'audiences: ' . self::audiences() . ' mirrored';
        foreach (self::system() as $l) $lines[] = $l;
        return $lines;
    }

    /** The app's own kinds of message (Providers 'categories'), brought in line. @return int how many */
    public static function categories(): int {
        $list = [];
        foreach (Providers::categories() as $c) {
            [$code, $label, $description, $defaultOn, $canOptOut] = $c;
            $list[] = ['code' => $code, 'label' => $label, 'description' => $description, 'default_on' => (bool) $defaultOn, 'can_opt_out' => (bool) $canOptOut,
                       'channels' => isset($c[5]) ? array_map('trim', explode(',', (string) $c[5])) : ['email', 'text']];
        }
        if (!$list) return 0;
        Client::put('categories', ['categories' => $list]);
        return count($list);
    }

    /** What the service is told about one member. */
    public static function contactRow(object $m): array {
        $row = ['eid' => (string) $m->id, 'email' => (string) $m->email, 'first_name' => trim((string) $m->firstName),
                'status' => (string) $m->status === 'active' ? 'active' : 'inactive',
                'timezone' => trim((string) \Flight::getSetting('timezone', (int) $m->id)),
                'joined_at' => self::date($m->createdAt ?? ''), 'last_seen_at' => self::date($m->lastLogin ?? '')];
        $phone = Providers::has('phone') ? (string) Providers::need('phone')::verified((int) $m->id) : '';
        $row['phone'] = $phone;
        $row['phone_verified'] = $phone !== '';
        return $row;
    }

    /** The members who are people: not the platform's own rows (the public visitor, a system account). */
    private const PEOPLE = "COALESCE(status, '') <> 'system' AND level > 0 AND level < 101";

    /** A member's date as the service takes it; an old row's '0' or '0000-00-00 …' is no date, not a fault to stop the mirror for everyone. */
    private static function date($v): string {
        $d = trim((string) $v);
        return ($d === '' || $d === '0' || str_starts_with($d, '0000-')) ? '' : $d;
    }

    /** Every member who is a person, in batches. @return array{created:int,updated:int} */
    public static function contacts(): array {
        $out = ['created' => 0, 'updated' => 0];
        $ids = array_map('intval', Bean::getCol('SELECT id FROM member WHERE ' . self::PEOPLE . ' ORDER BY id'));
        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            $rows = [];
            foreach ($chunk as $id) $rows[] = self::contactRow(Bean::load('member', $id));
            $r = Client::put('contacts', ['contacts' => $rows]);
            $out['created'] += (int) ($r['created'] ?? 0);
            $out['updated'] += (int) ($r['updated'] ?? 0);
        }
        return $out;
    }

    /** One member, now (a page that needs them in the service before the hour). */
    public static function contact(int $memberId): void {
        $m = Bean::load('member', $memberId);
        if (!$m->id) throw new \InvalidArgumentException("Sync::contact: member #$memberId does not exist");
        if ((string) $m->status === 'system' || (int) $m->level <= 0 || (int) $m->level >= 101) throw new \InvalidArgumentException("Sync::contact: member #$memberId is not a person (the platform's own row)");
        Client::put('contacts', ['contacts' => [self::contactRow($m)]]);
    }

    /** Every audience the app has, members included; the app's switch seeds a new member's. @return int how many */
    public static function audiences(): int {
        if (!Providers::has('audiences')) return 0;
        $a = Providers::need('audiences');
        // the app's groups may hold the platform's own rows (every account joins a welcome community, the public visitor included): only people are mirrored
        $people = array_fill_keys(array_map('intval', Bean::getCol('SELECT id FROM member WHERE ' . self::PEOPLE)), true);
        $specs = [];
        foreach ($a::all() as $s) {
            $key = (string) $s['key'];
            $members = array_values(array_filter(array_unique(array_map('intval', $a::members($key))), fn($id) => isset($people[$id])));
            $spec = ['key' => $key, 'label' => (string) $s['label'], 'owner_eid' => (string) ($s['owner_id'] ?? ''), 'members' => array_map('strval', $members),
                     'vars' => Providers::can('audiences', 'vars') ? $a::vars($key) : [], 'link' => Providers::can('audiences', 'link') ? (string) ($a::link($key) ?? '') : ''];
            if (Providers::can('audiences', 'allowsFor')) {
                $allowed = [];
                foreach ($members as $mid) { $v = $a::allowsFor($mid, $key); if ($v !== null) $allowed[(string) $mid] = $v; }
                if ($allowed) $spec['allowed'] = $allowed;
            }
            $specs[] = $spec;
        }
        foreach (array_chunk($specs, 50) as $chunk) Client::put('audiences', ['audiences' => $chunk]);
        return count($specs);
    }

    /** The platform's own messages the app declared exist once, paused, for the admin to read. @return string[] */
    public static function system(): array {
        $specs = Providers::system();
        if (!$specs) return [];
        $have = Client::get('campaigns', ['kind' => 'digest'])['campaigns'];
        $triggers = Client::get('campaigns', ['kind' => 'trigger'])['campaigns'];
        $codes = [];
        foreach ($triggers as $t) $codes[(string) $t['trigger_code']] = (int) $t['id'];
        $lines = [];
        foreach ($specs as $s) {
            if ($s['kind'] === 'digest') {
                if ($have) { $lines[] = "digest: kept #{$have[0]['id']}"; continue; }
            } elseif (isset($codes[$s['trigger_code']])) {
                $lines[] = "nudge {$s['trigger_code']}: kept #{$codes[$s['trigger_code']]}"; continue;
            }
            $doc = array_intersect_key($s, array_flip(['kind', 'category', 'subject', 'body_email', 'body_text', 'body_sms', 'cadence', 'cadence_days', 'trigger_code', 'trigger_days', 'code', 'site']));
            $doc['status'] = 'paused';
            if ($s['kind'] === 'trigger' && !in_array($s['trigger_code'], Providers::BUILT_IN_TRIGGERS, true) && $s['trigger_code'] !== Providers::TRIGGER_PIPELINE) $doc['content_from_app'] = true;
            $doc['sender'] = self::systemSender();
            $r = Client::post('campaigns', $doc);
            $lines[] = ($s['kind'] === 'digest' ? 'digest' : "nudge {$s['trigger_code']}") . ": made #{$r['campaign']['id']}, paused — read it on /messaging and switch it on";
            if ($s['kind'] === 'digest') $have = [$r['campaign']];
        }
        return $lines;
    }

    /** Who the platform's own messages are from: the first admin. */
    private static function systemSender(): array {
        $m = Bean::findOne('member', 'level <= 50 AND level > 0 ORDER BY level, id');
        if (!$m) throw new \RuntimeException('Sync: no admin member to be the sender of the platform\'s own messages');
        return ['eid' => (string) $m->id, 'email' => (string) $m->email, 'name' => trim((string) $m->firstName) ?: (string) $m->username];
    }
}
