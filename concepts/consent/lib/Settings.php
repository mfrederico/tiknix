<?php
/**
 * Settings — this site's consent settings (per site: every host of an app keeps its own database).
 *   mode     watch    nothing is blocked; every outside service a page loads is reported, so the list builds itself
 *            enforce  visitors are asked; the browser loads only what is needed and what they said yes to
 *   version  goes up when the site starts using a new kind of service that needs a yes: everyone is asked again
 */

namespace app\concepts\consent;

class Settings {

    public const MODES = ['watch', 'enforce'];
    private const KEY = 'consent_';

    public static function mode(): string {
        $m = (string) (\Flight::getSetting(self::KEY . 'mode', 0) ?? '');
        if ($m === '') return 'watch';
        if (!in_array($m, self::MODES, true)) throw new \UnexpectedValueException("consent: the setting consent_mode is '{$m}' — it is watch or enforce");
        return $m;
    }

    public static function setMode(string $mode): void {
        if (!in_array($mode, self::MODES, true)) throw new \InvalidArgumentException("consent: no mode '{$mode}' (watch, enforce)");
        \Flight::setSetting(self::KEY . 'mode', $mode, 0);
    }

    public static function version(): int { return max(1, (int) (\Flight::getSetting(self::KEY . 'version', 0) ?? 1)); }

    public static function bumpVersion(): int {
        $v = self::version() + 1;
        \Flight::setSetting(self::KEY . 'version', (string) $v, 0);
        return $v;
    }
}
