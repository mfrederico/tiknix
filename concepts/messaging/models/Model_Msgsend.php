<?php
/** Msgsend FUSE model — one recipient of one campaign. Written BEFORE the message goes out, so a crash cannot send twice. */
class Model_Msgsend extends \RedBeanPHP\SimpleModel {

    public function update() {
        $b = $this->bean;
        if (!preg_match('/^[a-f0-9]{12,32}$/', (string) $b->token)) throw new \InvalidArgumentException('token: must be 12 to 32 hex characters');
        if (!in_array((string) $b->channel, \Model_Msgcategory::CHANNELS, true)) throw new \InvalidArgumentException("channel: '{$b->channel}' is not email or text");
        if ($b->clicks === null) $b->clicks = 0;
    }

    /** The send a token names, or null. */
    public static function byToken(string $token) {
        if (!preg_match('/^[a-f0-9]{12,32}$/', $token)) return null;
        return \app\Bean::findOne('msgsend', 'token = ?', [$token]);
    }

    /** The URL link $n of this send's message pointed at, or null when there is no such link. */
    public static function linkUrl($send, int $n): ?string {
        $links = json_decode((string) $send->links, true);
        if (!is_array($links)) throw new \RuntimeException("msgsend #{$send->id}: links is not JSON");
        return isset($links[$n]) && is_string($links[$n]) ? $links[$n] : null;
    }
}
