<?php
/**
 * PlatformChanges — Notes on Tiknix itself. The runtime's Notes (public/js/notes.js → app\ChangeAsk) send an app's notes
 * to Tiknix over its broker key; on the platform there is no broker to send through, so this answers ChangeAsk's calls
 * here, in the same shapes Brokerinfo gives an app (set as ChangeAsk::$local in lib/controlplane.php).
 *
 * A note on tiknix.com becomes a change request of the control plane's own project (instance is_default): listed on
 * /changerequests with its pictures, selectors and files. Tiknix is not built by its own Builder — it is changed from
 * its terminal — so these are never sent to Build it or to Ask; scripts/change-requests.php prints the open ones for
 * the agent in that terminal, with everything a note carries.
 */

namespace app;

final class PlatformChanges {

    public const NOT_BUILT = 'Tiknix itself is changed from its terminal, not by its Builder: this request waits there (scripts/change-requests.php).';

    /** The control plane's own project. @throws \RuntimeException when there is none */
    public static function project(): object {
        $inst = Bean::findOne('instance', 'is_default = 1');
        if (!$inst || !$inst->id) throw new \RuntimeException('This platform has no project of its own (instance is_default = 1), so its notes have nowhere to go.');
        return $inst;
    }

    /** ChangeAsk::call() answered here: the broker's paths, the broker's answers. */
    public static function call(string $method, string $path, ?array $body): array {
        $inst = self::project();
        $route = (string) parse_url($path, PHP_URL_PATH);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $q);
        switch ($method . ' ' . $route) {
            case 'POST /brokerinfo/change':
                // ROOT's own notes on their own platform: no email to the team about them (they are the team)
                $id = SiteChanges::receive($inst, ['quiet' => true] + (array) $body);
                return ['ok' => true, 'ref' => $id, 'built' => false, 'why' => !empty($body['build']) ? self::NOT_BUILT : ''];
            case 'GET /brokerinfo/changes':
                return ['ok' => true, 'requests' => SiteChanges::forApp($inst, (string) ($q['site'] ?? ''), explode(',', (string) ($q['refs'] ?? '')), !empty($q['detail']))];
            case 'POST /brokerinfo/changereply':
                SiteChanges::addFromApp($inst, (string) ($body['site'] ?? ''), (int) ($body['ref'] ?? 0), (string) ($body['text'] ?? ''));
                return ['ok' => true];
            case 'GET /brokerinfo/canbuild':
                return ['ok' => true, 'ready' => false, 'why' => self::NOT_BUILT];
        }
        throw new \RuntimeException("PlatformChanges: no answer for {$method} {$route}");
    }

    /** Is this the platform's own project? */
    public static function isPlatform(object $inst): bool {
        return (int) ($inst->isDefault ?? 0) === 1;
    }
}
