<?php
/**
 * Workbenchtask FUSE Model
 *
 * Enables RedBeanPHP associations for the workbenchtask bean:
 * - ownTasklogList: Execution logs for this task
 * - ownTasksnapshotList: Progress snapshots
 * - ownTaskcommentList: Comments on this task
 * - ownWorkbenchtaskList: Subtasks (via parent_task_id)
 *
 * Relations:
 * - member: The member who created this task (owner)
 * - team: The team this task belongs to (null = personal)
 * - assignedTo: The member assigned to this task
 * - parentTask: Parent task for subtasks
 * - lastRunnerMember: Member who triggered the last run
 *
 * Use xownTasklogList for cascade delete
 * Use xownTasksnapshotList for cascade delete
 * Use xownTaskcommentList for cascade delete
 *
 * Task Types: feature, bugfix, refactor, security, docs, test
 * Statuses: pending, queued, running, completed, failed, paused
 * Priorities: 1=critical, 2=high, 3=medium, 4=low
 */

class Model_Workbenchtask extends \RedBeanPHP\SimpleModel {
    // Associations are automatic - this class enables FUSE discovery
}
