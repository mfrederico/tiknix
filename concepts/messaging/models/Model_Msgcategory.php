<?php
/** Msgcategory FUSE model — a kind of message a member has a switch for. The plugin registers transactional and reminders (seeds/03); an app registers its own with register(). */
class Model_Msgcategory extends \RedBeanPHP\SimpleModel {

    public const CHANNELS = ['email', 'text'];

    public function update() {
        if (trim((string) $this->bean->code) === '') throw new \InvalidArgumentException('code: a message category needs a code');
        foreach ($this->channels() as $ch) {
            if (!in_array($ch, self::CHANNELS, true)) throw new \InvalidArgumentException("channels: '$ch' is not email or text");
        }
        if ((int) $this->bean->canOptOut === 0 && (int) $this->bean->defaultOn !== 1) throw new \InvalidArgumentException('defaultOn: a category that cannot be opted out of is on');
    }

    /** @return string[] */
    public function channels(): array {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->bean->channels))));
    }

    /** The category with this code; throws when there is none (a typo must not read as "no switch"). */
    public static function byCode(string $code) {
        $c = \app\Bean::findOne('msgcategory', 'code = ?', [$code]);
        if (!$c) throw new \InvalidArgumentException("message category '$code' does not exist (register it with Model_Msgcategory::register in a seed)");
        return $c;
    }

    /** All categories, in the order the member sees them. */
    public static function all(): array {
        return array_values(\app\Bean::findAll('msgcategory', ' ORDER BY id'));
    }

    /**
     * Add a category, or bring an existing one in line with these words. Idempotent: for a seed.
     * @param string $channels comma-separated: email, text
     */
    public static function register(string $code, string $label, string $description, bool $defaultOn, bool $canOptOut, string $channels = 'email,text') {
        $c = \app\Bean::findOne('msgcategory', 'code = ?', [$code]);
        if (!$c) { $c = \app\Bean::dispense('msgcategory'); $c->code = $code; }
        $c->label = $label; $c->description = $description; $c->defaultOn = $defaultOn ? 1 : 0; $c->canOptOut = $canOptOut ? 1 : 0; $c->channels = $channels;
        \app\Bean::store($c);
        return $c;
    }
}
