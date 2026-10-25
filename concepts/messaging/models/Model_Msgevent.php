<?php
/** Msgevent FUSE model — something that happened on a msgsend. */
class Model_Msgevent extends \RedBeanPHP\SimpleModel {

    public const KINDS = ['click', 'open', 'reply', 'rsvp', 'visit', 'post'];

    public function update() {
        if (!in_array((string) $this->bean->kind, self::KINDS, true)) throw new \InvalidArgumentException('kind: must be click, open, reply, rsvp, visit or post');
        if (!$this->bean->at) $this->bean->at = date('Y-m-d H:i:s');
    }

    /** Record an event on a send; $meta is stored as JSON. */
    public static function record($send, string $kind, array $meta = []) {
        $e = \app\Bean::dispense('msgevent');
        $e->msgsendId = (int) $send->id;
        $e->kind = $kind;
        $e->meta = json_encode((object) $meta, JSON_UNESCAPED_SLASHES);
        \app\Bean::store($e);
        return $e;
    }
}
