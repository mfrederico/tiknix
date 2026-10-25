<?php
/** Msgsuppression FUSE model — an email address that must not be mailed (anything but transactional). Filled by the mail webhook and by unsubscribe-all. */
class Model_Msgsuppression extends \RedBeanPHP\SimpleModel {

    public const REASONS = ['bounced', 'complained', 'unsubscribed_all'];

    public function update() {
        $this->bean->email = strtolower(trim((string) $this->bean->email));
        if (!filter_var($this->bean->email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('email: not an email address');
        if (!in_array((string) $this->bean->reason, self::REASONS, true)) throw new \InvalidArgumentException('reason: must be bounced, complained or unsubscribed_all');
        if (!$this->bean->at) $this->bean->at = date('Y-m-d H:i:s');
    }

    public static function has(string $email): bool {
        return \app\Bean::count('msgsuppression', 'email = ?', [strtolower(trim($email))]) > 0;
    }

    /** Record it once; a second reason for the same address keeps the first row. */
    public static function add(string $email, string $reason): void {
        if (self::has($email)) return;
        $s = \app\Bean::dispense('msgsuppression');
        $s->email = $email;
        $s->reason = $reason;
        \app\Bean::store($s);
    }
}
