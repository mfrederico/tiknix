<?php
/**
 * 32_TerminalKey.php — instance.terminal_key: the key an app's builder terminal verifies the
 * browser's token with (runtime bin/terminal-bridge.php; TenantHost::terminal installs it in the
 * app as secure/terminal.key; the builder signs with it).
 *
 * A padded TEXT ghost, guarded on existence, so the column is born TEXT.
 */

use \RedBeanPHP\R;

$_cols = array_column(R::getAll('PRAGMA table_info(instance)'), 'name');

if (!in_array('terminal_key', $_cols, true)) {
    $termGhost = R::dispense('instance');
    $termGhost->slug = 'schema-ghost-terminalkey';    // trashed at once; never routed
    $termGhost->terminalKey = str_repeat('x', 2000);
    R::store($termGhost);
    $_defer($termGhost);
    unset($termGhost);
    echo "  instance.terminal_key: added\n";
}
