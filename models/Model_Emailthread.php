<?php
/**
 * Emailthread FUSE Model — one conversation.
 *
 * notify + notifyattachment link to a thread via the plain `thread_id` column
 * (aliased relation set by NotifyService), so they are NOT reachable through a
 * conventional ownNotifyList. Cascade delete is therefore handled here in the
 * delete() hook: when a thread is trashed, its messages and attachments go too.
 *
 * Uses bean operations (find + trashAll) rather than R::exec so any child model
 * hooks still fire and the ORM stays authoritative.
 */

class Model_Emailthread extends \RedBeanPHP\SimpleModel {

    /** FUSE hook: fires when R::trash()/Bean::trash() is called on the thread. */
    public function delete() {
        $id = (int)$this->bean->id;
        if ($id <= 0) return;

        \app\Bean::trashAll(\app\Bean::find('notifyattachment', 'thread_id = ?', [$id]));
        \app\Bean::trashAll(\app\Bean::find('notify', 'thread_id = ?', [$id]));
    }
}
