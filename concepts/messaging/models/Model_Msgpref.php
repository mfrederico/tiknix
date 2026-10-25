<?php
/** Msgpref FUSE model — a member's choice for one category on one channel (email, text, or the pseudo-channel digest). No row = the category's default. */
class Model_Msgpref extends \RedBeanPHP\SimpleModel {

    public const DIGEST = 'digest';

    public function update() {
        if ((int) $this->bean->memberId <= 0 || (int) $this->bean->categoryRef <= 0) throw new \InvalidArgumentException('member: a message choice needs a member and a category');
        if (!in_array((string) $this->bean->channel, array_merge(\Model_Msgcategory::CHANNELS, [self::DIGEST]), true)) throw new \InvalidArgumentException('channel: must be email, text or digest');
        if (!in_array((int) $this->bean->enabled, [0, 1], true)) throw new \InvalidArgumentException('enabled: must be 0 or 1');
    }

    /** The stored choice (true/false), or null when the member never chose. */
    public static function stored(int $memberId, string $categoryCode, string $channel): ?bool {
        $cat = \Model_Msgcategory::byCode($categoryCode);
        $row = \app\Bean::findOne('msgpref', 'member_id = ? AND category_ref = ? AND channel = ?', [$memberId, (int) $cat->id, $channel]);
        return $row ? (int) $row->enabled === 1 : null;
    }

    public static function set(int $memberId, string $categoryCode, string $channel, bool $on): void {
        $cat = \Model_Msgcategory::byCode($categoryCode);
        if ($channel !== self::DIGEST && !in_array($channel, $cat->box()->channels(), true)) throw new \InvalidArgumentException("channel: '$categoryCode' is not sent by $channel");
        if (!$on && (int) $cat->canOptOut === 0) throw new \InvalidArgumentException("category: '$categoryCode' cannot be switched off");
        $row = \app\Bean::findOne('msgpref', 'member_id = ? AND category_ref = ? AND channel = ?', [$memberId, (int) $cat->id, $channel]);
        if (!$row) {
            $row = \app\Bean::dispense('msgpref');
            $row->memberId = $memberId;
            $row->categoryRef = (int) $cat->id;
            $row->channel = $channel;
        }
        $row->enabled = $on ? 1 : 0;
        // a text is opt-in: when and that the member asked for it is on the record
        if ($channel === 'text') $row->consentedAt = $on ? date('Y-m-d H:i:s') : null;
        \app\Bean::store($row);
    }
}
