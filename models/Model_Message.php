<?php
/**
 * Message FUSE Model — one message in a thread.
 *
 * Was `notify`, an email-shaped name for something that is now mostly not email.
 * transport = 'email' | 'inapp' says how it travelled; direction = 'in' | 'out' is
 * meaningful for email only.
 */

class Model_Message extends \RedBeanPHP\SimpleModel {

    /**
     * Did this reader send it? The question the whole feed layout turns on.
     *
     * direction only answers it for EMAIL, where "out" means this system sent it.
     * postInApp() writes direction='out' for every in-app message — from the
     * application's side it always is — so in a room everybody's messages rendered as
     * though they were yours. A sender account is the real answer; direction is the
     * fallback for email, which has none.
     *
     * This rule was written inline in the view AND in the poll's JSON payload. Two copies
     * of a rule that must agree is one copy too many.
     */
    public function isMine(int $viewerId): bool {
        $sender = (int) ($this->bean->senderMemberId ?? 0);
        if ($sender > 0) return $sender === $viewerId;
        return (string) $this->bean->direction === 'out';
    }

    public function isInApp(): bool  { return (string) $this->bean->transport === 'inapp'; }
    public function isSystem(): bool { return (string) $this->bean->notifyType === 'system'; }

    /** The account that sent it, or null when it genuinely came from an address. */
    public function sender(): ?\RedBeanPHP\OODBBean {
        $id = (int) ($this->bean->senderMemberId ?? 0);
        if ($id <= 0) return null;
        $m = \app\Bean::load('member', $id);
        return $m->id ? $m : null;
    }

    /** Who to show as the author. */
    public function fromLabel(): string {
        $sender = $this->sender();
        if ($sender) return $sender->displayName('Someone');
        return (string) ($this->bean->fromName ?: $this->bean->fromEmail ?: 'Unknown');
    }

    /** FUSE hook: a deleted message takes its mentions with it. */
    public function delete() {
        $id = (int) $this->bean->id;
        if ($id <= 0) return;
        \app\Bean::trashAll(\app\Bean::find('mention', 'message_ref = ?', [$id]));
    }
}
