<?php
namespace app\concepts\messaging;

use \Flight as Flight;
use app\BaseControls\Control;

/**
 * Where the links and the pixel in mail sent BEFORE the service point (/msgtrack/l/{token}/{n}, /msgtrack/o/{token}.gif):
 * the ledger those tokens belong to was imported, so the service answers them. One hop, nothing recorded here.
 */
class Msgtrack extends Control {

    // nav: none — reached from the links in old campaign emails

    /** The token and the rest of the path, read from the URL itself. */
    private function segments(): array {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return array_slice(explode('/', trim($path, '/')), 2);
    }

    public function l($params = []) {
        [$token, $n] = array_pad($this->segments(), 2, '');
        if (!preg_match('/^[a-f0-9]{12,32}$/', (string) $token) || !ctype_digit((string) $n)) { Flight::notFound(); return; }
        Flight::redirect(Client::url() . '/t/l/' . $token . '/' . (int) $n, 302);
    }

    public function o($params = []) {
        $name = (string) ($this->segments()[0] ?? '');
        $token = str_ends_with($name, '.gif') ? substr($name, 0, -4) : $name;
        if (!preg_match('/^[a-f0-9]{12,32}$/', $token)) { Flight::notFound(); return; }
        Flight::redirect(Client::url() . '/t/o/' . $token, 302);
    }
}
