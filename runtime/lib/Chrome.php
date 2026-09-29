<?php
/**
 * Chrome — the extension points of the runtime's page shell (views/layouts/header.php).
 *
 * The shell is the app's: brand, the app's menu, Communications, Admin, the account menu.
 * Whatever runs the app may add to it without owning a copy of the layout (an override the
 * runtime could never upgrade again). The control plane fills these in lib/controlplane.php
 * with its projects, project bar, teams and billing; an app fills none, and then the shell is
 * exactly the app's.
 *
 * Each slot is a list of view names. The header INCLUDES them in its own scope, so a partial
 * reads and writes the shell's variables ($__loggedIn, $__mid, $__level, $__isAdmin,
 * $__sections, $__active …) — that is the contract, and the slot list below says what each
 * slot may touch.
 *
 *   prepare   before the sidebar is built: may add to or filter $__sections (the menu)
 *   nav       inside the sidebar, after the menu and before Admin
 *   bar       after the top bar: a band across the page (sets $__chromeBar = true when it
 *             drew one, so .ui-main gets its offset class)
 *   account   inside the account dropdown, after Settings / API Keys
 */

namespace app;

class Chrome {

    public const SLOTS = ['prepare', 'nav', 'bar', 'account'];

    /** @var array<string, string[]> slot => view names */
    public static array $parts = [];

    /** Register a view for a slot. An unknown slot is a programming error, not a no-op. */
    public static function add(string $slot, string $view): void {
        if (!in_array($slot, self::SLOTS, true)) {
            throw new \InvalidArgumentException("Chrome slot '{$slot}' does not exist — the slots are " . implode(', ', self::SLOTS));
        }
        self::$parts[$slot][] = $view;
    }

    /**
     * The template files for a slot, resolved through the view layers (app first). A view a
     * slot names that exists nowhere is an error — the shell must not quietly lose a part.
     *
     * @return string[]
     */
    public static function files(string $slot): array {
        $out = [];
        foreach (self::$parts[$slot] ?? [] as $view) {
            $file = \Flight::view()->getTemplate($view);
            if (!is_file($file)) throw new \RuntimeException("Chrome slot '{$slot}' names view '{$view}', which does not exist ({$file})");
            $out[] = $file;
        }
        return $out;
    }
}
