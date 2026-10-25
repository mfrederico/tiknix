<?php
namespace app\concepts\messaging;

use \Flight as Flight;
use app\Bean;
use app\BaseControls\Control;

/** Where the links and the pixel in a campaign message point: they record what happened on the recipient's msgsend, then send the person on. */
class Msgtrack extends Control {

    // nav: none — reached from the links in campaign emails

    /** The token and the rest of the path, read from the URL itself: /msgtrack/<method>/<token>[/<n> | .gif]. */
    private function segments(): array {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return array_slice(explode('/', trim($path, '/')), 2);
    }

    /** GET /msgtrack/l/{token}/{n}: a click, then a 302 to the link's own address. */
    public function l($params = []) {
        [$token, $n] = array_pad($this->segments(), 2, '');
        $send = \Model_Msgsend::byToken((string) $token);
        $url = ($send && ctype_digit((string) $n)) ? \Model_Msgsend::linkUrl($send, (int) $n) : null;
        if ($url === null) { Flight::notFound(); return; }
        $now = date('Y-m-d H:i:s');
        \Model_Msgevent::record($send, 'click', ['link' => (int) $n]);
        $send->clicks = (int) $send->clicks + 1;
        if (!$send->firstClickAt) $send->firstClickAt = $now;
        Bean::store($send);
        Flight::redirect($url, 302);
    }

    /** GET /msgtrack/o/{token}.gif: an open (approximate: mail apps fetch the picture ahead of the reader), then a 1x1 gif. */
    public function o($params = []) {
        $name = (string) ($this->segments()[0] ?? '');
        $send = str_ends_with($name, '.gif') ? \Model_Msgsend::byToken(substr($name, 0, -4)) : null;
        if (!$send) { Flight::notFound(); return; }
        \Model_Msgevent::record($send, 'open');
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, max-age=0');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }
}
