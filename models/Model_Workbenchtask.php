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

    /**
     * Is this row a PLAN PARENT rather than a task?
     *
     * A plan parent runs the plan lifecycle in `plan_status`
     * (draft/approved/building/stalled/done); a solo task and a plan SUBTASK run the
     * ordinary one in `status`. Two columns, and every reader has to know which kind of
     * row it is holding — asked five different ways across the codebase, including
     * `!$isSubtask && planStatus !== ''` in the reaper and `!empty($task->planStatus)`
     * in the board view. One definition, so they cannot drift apart.
     */
    public function isPlan(): bool {
        return empty($this->bean->parentTaskId)
            && (string) $this->bean->planStatus !== '';
    }

    /**
     * The status to SHOW for this row — its own lifecycle, not a fallback.
     *
     * `planStatus ?: status` reads as a default and is not one: which column applies is
     * determined by what kind of row this is, and isPlan() knows. The distinction is not
     * cosmetic. A stalled plan carries status `failed` at the same time, so a page
     * rendering the wrong column showed "Failed" beside a Build button offering to
     * resume, while the board said "Stalled" — three names for one state.
     */
    public function displayStatus(): string {
        return $this->isPlan()
            ? (string) $this->bean->planStatus
            : (string) $this->bean->status;
    }
}
