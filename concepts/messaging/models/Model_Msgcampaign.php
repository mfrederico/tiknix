<?php
/** Msgcampaign FUSE model — a message with a derived audience; every recipient is a msgsend row (lib/Campaigns). */
class Model_Msgcampaign extends \RedBeanPHP\SimpleModel {

    public const KINDS = ['message', 'gathering', 'digest', 'trigger'];
    public const CADENCES = ['once', 'every_n_days'];
    public const STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'active', 'paused'];
    /** An audience or subject key the host's provider understands: kind:id, e.g. community:5. */
    public const KEY_RE = '/^[a-z][a-z0-9_]*:[A-Za-z0-9_-]+$/D';

    public function update() {
        $b = $this->bean;
        if (!in_array((string) $b->kind, self::KINDS, true)) throw new \InvalidArgumentException('kind: must be message, gathering, digest or trigger');
        if (!in_array((string) $b->cadence, self::CADENCES, true)) throw new \InvalidArgumentException('cadence: must be once or every_n_days');
        if (!in_array((string) $b->status, self::STATUSES, true)) throw new \InvalidArgumentException('status: not a campaign status');
        if ((string) $b->cadence === 'every_n_days' && (int) $b->cadenceDays < 1) throw new \InvalidArgumentException('cadenceDays: a repeating campaign needs a number of days');
        if (trim((string) $b->subject) === '') throw new \InvalidArgumentException('subject: a campaign needs a subject');
        foreach (['scopeKey', 'aboutKey'] as $k) {
            if ($b->$k !== null && $b->$k !== '' && !preg_match(self::KEY_RE, (string) $b->$k)) throw new \InvalidArgumentException("$k: must look like kind:id");
        }
        if ((string) $b->kind === 'gathering' && ($b->aboutKey === null || $b->aboutKey === '')) throw new \InvalidArgumentException('aboutKey: a campaign about something names it');
        if (!$b->createdAt) $b->createdAt = date('Y-m-d H:i:s');
    }
}
