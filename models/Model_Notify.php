<?php
/**
 * Notify FUSE Model — one message in a thread.
 *
 * notify links to its thread via the plain `thread_id` column (an aliased
 * relation, not a conventional ownNotifyList), so there are no associations to
 * enable here. This class exists so FUSE hooks can be added later (validation,
 * post-store side effects) without changing call sites.
 */

class Model_Notify extends \RedBeanPHP\SimpleModel {
    // FUSE discovery only — no hooks needed yet.
}
