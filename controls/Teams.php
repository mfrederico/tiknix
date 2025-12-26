<?php
/**
 * Teams Controller
 *
 * Manages team creation, membership, and collaboration.
 * Teams enable shared access to workbench tasks.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\TaskAccessControl;
use \Exception as Exception;
use app\BaseControls\Control;

class Teams extends Control {

    private TaskAccessControl $access;

    public function __construct() {
        parent::__construct();

        // Require login for all team operations (except join with token)
        $this->access = new TaskAccessControl();
    }

    /**
     * List user's teams
     */
    public function index($params = []) {
        if (!$this->requireLogin()) return;

        $this->viewData['title'] = 'My Teams';

        // Get teams with membership info
        $teams = $this->access->getMemberTeams($this->member->id);

        // Enrich with member counts
        foreach ($teams as &$team) {
            $team['member_count'] = Bean::count('teammember', 'team_id = ?', [$team['id']]);
            $team['task_count'] = Bean::count('workbenchtask', 'team_id = ?', [$team['id']]);
        }

        $this->viewData['teams'] = $teams;
        $this->render('teams/index', $this->viewData);
    }

    /**
     * Create team form
     */
    public function create($params = []) {
        if (!$this->requireLogin()) return;

        $this->viewData['title'] = 'Create Team';
        $this->render('teams/create', $this->viewData);
    }

    /**
     * Store new team
     */
    public function store($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();

        if ($request->method !== 'POST') {
            Flight::redirect('/teams');
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Invalid CSRF token');
            Flight::redirect('/teams/create');
            return;
        }

        $name = trim($this->getParam('name', ''));
        $description = trim($this->getParam('description', ''));

        // Validate name
        if (empty($name)) {
            $this->flash('error', 'Team name is required');
            Flight::redirect('/teams/create');
            return;
        }

        if (strlen($name) < 2 || strlen($name) > 100) {
            $this->flash('error', 'Team name must be between 2 and 100 characters');
            Flight::redirect('/teams/create');
            return;
        }

        // Generate slug
        $slug = $this->generateSlug($name);

        // Check for duplicate slug
        $existing = Bean::findOne('team', 'slug = ?', [$slug]);
        if ($existing) {
            $slug .= '-' . time();
        }

        try {
            $this->beginTransaction();

            // Create team
            $team = Bean::dispense('team');
            $team->name = $name;
            $team->slug = $slug;
            $team->description = $description;
            $team->ownerId = $this->member->id;
            $team->isActive = 1;
            $team->createdAt = date('Y-m-d H:i:s');
            Bean::store($team);

            // Add creator as owner member
            $membership = Bean::dispense('teammember');
            $membership->teamId = $team->id;
            $membership->memberId = $this->member->id;
            $membership->role = 'owner';
            $membership->canRunTasks = 1;
            $membership->canEditTasks = 1;
            $membership->canDeleteTasks = 1;
            $membership->joinedAt = date('Y-m-d H:i:s');
            Bean::store($membership);

            $this->commit();

            $this->logger->info('Team created', [
                'team_id' => $team->id,
                'team_name' => $name,
                'owner_id' => $this->member->id
            ]);

            $this->flash('success', 'Team created successfully');
            Flight::redirect('/teams/view?id=' . $team->id);

        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('Failed to create team', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to create team');
            Flight::redirect('/teams/create');
        }
    }

    /**
     * View team dashboard
     */
    public function view($params = []) {
        if (!$this->requireLogin()) return;

        $teamId = (int)$this->getParam('id');
        if (!$teamId) {
            Flight::redirect('/teams');
            return;
        }

        $team = Bean::load('team', $teamId);
        if (!$team->id || !$team->isActive) {
            $this->flash('error', 'Team not found');
            Flight::redirect('/teams');
            return;
        }

        // Check membership
        if (!$this->access->isTeamMember($teamId, $this->member->id)) {
            $this->flash('error', 'You are not a member of this team');
            Flight::redirect('/teams');
            return;
        }

        // Get team members
        $members = Bean::getAll(
            "SELECT m.id, m.email, m.username, m.display_name, m.avatar_url,
                    tm.role, tm.can_run_tasks, tm.can_edit_tasks, tm.can_delete_tasks, tm.joined_at
             FROM teammember tm
             JOIN member m ON tm.member_id = m.id
             WHERE tm.team_id = ?
             ORDER BY tm.role ASC, tm.joined_at ASC",
            [$teamId]
        );

        // Get recent tasks
        $tasks = Bean::find('workbenchtask', 'team_id = ? ORDER BY created_at DESC LIMIT 10', [$teamId]);

        // Get pending invitations
        $invitations = [];
        if ($this->access->isTeamAdmin($teamId, $this->member->id)) {
            $invitations = Bean::find('teaminvitation', 'team_id = ? AND accepted_at IS NULL ORDER BY created_at DESC', [$teamId]);
        }

        $this->viewData['title'] = $team->name;
        $this->viewData['team'] = $team;
        $this->viewData['members'] = $members;
        $this->viewData['tasks'] = $tasks;
        $this->viewData['invitations'] = $invitations;
        $this->viewData['userRole'] = $this->access->getTeamRole($teamId, $this->member->id);
        $this->viewData['isAdmin'] = $this->access->isTeamAdmin($teamId, $this->member->id);
        $this->viewData['isOwner'] = $this->access->isTeamOwner($teamId, $this->member->id);

        $this->render('teams/view', $this->viewData);
    }

    /**
     * Team settings page
     */
    public function settings($params = []) {
        if (!$this->requireLogin()) return;

        $teamId = (int)$this->getParam('id');
        if (!$teamId) {
            Flight::redirect('/teams');
            return;
        }

        $team = Bean::load('team', $teamId);
        if (!$team->id) {
            $this->flash('error', 'Team not found');
            Flight::redirect('/teams');
            return;
        }

        // Only owner can access settings
        if (!$this->access->isTeamOwner($teamId, $this->member->id)) {
            $this->flash('error', 'Only the team owner can access settings');
            Flight::redirect('/teams/view?id=' . $teamId);
            return;
        }

        $this->viewData['title'] = $team->name . ' - Settings';
        $this->viewData['team'] = $team;

        $this->render('teams/settings', $this->viewData);
    }

    /**
     * Update team settings
     */
    public function update($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/teams');
            return;
        }

        $teamId = (int)$this->getParam('id');
        if (!$teamId) {
            Flight::redirect('/teams');
            return;
        }

        $team = Bean::load('team', $teamId);
        if (!$team->id) {
            $this->flash('error', 'Team not found');
            Flight::redirect('/teams');
            return;
        }

        if (!$this->access->isTeamOwner($teamId, $this->member->id)) {
            $this->flash('error', 'Only the team owner can update settings');
            Flight::redirect('/teams/view?id=' . $teamId);
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Invalid CSRF token');
            Flight::redirect('/teams/settings?id=' . $teamId);
            return;
        }

        $name = trim($this->getParam('name', ''));
        $description = trim($this->getParam('description', ''));

        if (empty($name)) {
            $this->flash('error', 'Team name is required');
            Flight::redirect('/teams/settings?id=' . $teamId);
            return;
        }

        $team->name = $name;
        $team->description = $description;
        $team->updatedAt = date('Y-m-d H:i:s');
        Bean::store($team);

        $this->logger->info('Team updated', ['team_id' => $teamId, 'name' => $name]);

        $this->flash('success', 'Team settings updated');
        Flight::redirect('/teams/settings?id=' . $teamId);
    }

    /**
     * Manage team members
     */
    public function members($params = []) {
        if (!$this->requireLogin()) return;

        $teamId = (int)$this->getParam('id');
        if (!$teamId) {
            Flight::redirect('/teams');
            return;
        }

        $team = Bean::load('team', $teamId);
        if (!$team->id) {
            $this->flash('error', 'Team not found');
            Flight::redirect('/teams');
            return;
        }

        if (!$this->access->isTeamAdmin($teamId, $this->member->id)) {
            $this->flash('error', 'Only admins can manage members');
            Flight::redirect('/teams/view?id=' . $teamId);
            return;
        }

        // Get team members with details
        $members = Bean::getAll(
            "SELECT m.id, m.email, m.username, m.display_name, m.avatar_url,
                    tm.id as membership_id, tm.role, tm.can_run_tasks, tm.can_edit_tasks, tm.can_delete_tasks, tm.joined_at
             FROM teammember tm
             JOIN member m ON tm.member_id = m.id
             WHERE tm.team_id = ?
             ORDER BY tm.role ASC, tm.joined_at ASC",
            [$teamId]
        );

        // Get pending invitations
        $invitations = Bean::find('teaminvitation', 'team_id = ? AND accepted_at IS NULL ORDER BY created_at DESC', [$teamId]);

        $this->viewData['title'] = $team->name . ' - Members';
        $this->viewData['team'] = $team;
        $this->viewData['members'] = $members;
        $this->viewData['invitations'] = $invitations;
        $this->viewData['isOwner'] = $this->access->isTeamOwner($teamId, $this->member->id);

        $this->render('teams/members', $this->viewData);
    }

    /**
     * Send team invitation
     */
    public function invite($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/teams');
            return;
        }

        $teamId = (int)$this->getParam('id');
        if (!$teamId) {
            Flight::redirect('/teams');
            return;
        }

        $team = Bean::load('team', $teamId);
        if (!$team->id) {
            Flight::jsonError('Team not found', 404);
            return;
        }

        if (!$this->access->canInviteToTeam($teamId, $this->member->id)) {
            Flight::jsonError('You cannot invite members to this team', 403);
            return;
        }

        $email = trim($this->getParam('email', ''));
        $role = $this->getParam('role', 'member');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flight::jsonError('Valid email address is required', 400);
            return;
        }

        // Validate role
        if (!in_array($role, ['admin', 'member', 'viewer'])) {
            $role = 'member';
        }

        // Check if already a member
        $existingMember = Bean::findOne('member', 'email = ?', [$email]);
        if ($existingMember) {
            $existingMembership = Bean::findOne('teammember', 'team_id = ? AND member_id = ?', [$teamId, $existingMember->id]);
            if ($existingMembership) {
                Flight::jsonError('This user is already a team member', 400);
                return;
            }
        }

        // Check for existing pending invitation
        $existingInvitation = Bean::findOne('teaminvitation', 'team_id = ? AND email = ? AND accepted_at IS NULL', [$teamId, $email]);
        if ($existingInvitation) {
            Flight::jsonError('An invitation has already been sent to this email', 400);
            return;
        }

        // Create invitation
        $invitation = Bean::dispense('teaminvitation');
        $invitation->teamId = $teamId;
        $invitation->email = $email;
        $invitation->token = bin2hex(random_bytes(32));
        $invitation->role = $role;
        $invitation->invitedBy = $this->member->id;
        $invitation->expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        $invitation->createdAt = date('Y-m-d H:i:s');
        Bean::store($invitation);

        $this->logger->info('Team invitation sent', [
            'team_id' => $teamId,
            'email' => $email,
            'role' => $role,
            'invited_by' => $this->member->id
        ]);

        // Generate join link
        $joinUrl = Flight::get('baseurl') . '/teams/join?token=' . $invitation->token;

        Flight::json([
            'success' => true,
            'message' => 'Invitation sent',
            'join_url' => $joinUrl
        ]);
    }

    /**
     * Accept team invitation (public with token)
     */
    public function join($params = []) {
        $token = $this->getParam('token', '');

        if (empty($token)) {
            $this->flash('error', 'Invalid invitation link');
            Flight::redirect('/');
            return;
        }

        // Find invitation
        $invitation = Bean::findOne('teaminvitation', 'token = ? AND accepted_at IS NULL', [$token]);

        if (!$invitation) {
            $this->flash('error', 'Invitation not found or already used');
            Flight::redirect('/');
            return;
        }

        // Check expiration
        if ($invitation->expiresAt && strtotime($invitation->expiresAt) < time()) {
            $this->flash('error', 'This invitation has expired');
            Flight::redirect('/');
            return;
        }

        // Load team
        $team = Bean::load('team', $invitation->teamId);
        if (!$team->id || !$team->isActive) {
            $this->flash('error', 'Team no longer exists');
            Flight::redirect('/');
            return;
        }

        // If not logged in, redirect to login with return URL
        if (!Flight::isLoggedIn()) {
            $returnUrl = '/teams/join?token=' . urlencode($token);
            Flight::redirect('/auth/login?redirect=' . urlencode($returnUrl));
            return;
        }

        // Check if email matches (optional but recommended)
        if ($invitation->email !== $this->member->email) {
            // Allow joining anyway but warn
            $this->logger->warning('User accepted invitation meant for different email', [
                'invitation_email' => $invitation->email,
                'user_email' => $this->member->email
            ]);
        }

        // Check if already a member
        $existingMembership = Bean::findOne('teammember', 'team_id = ? AND member_id = ?', [$team->id, $this->member->id]);
        if ($existingMembership) {
            $this->flash('info', 'You are already a member of this team');
            Flight::redirect('/teams/view?id=' . $team->id);
            return;
        }

        try {
            $this->beginTransaction();

            // Create membership
            $membership = Bean::dispense('teammember');
            $membership->teamId = $team->id;
            $membership->memberId = $this->member->id;
            $membership->role = $invitation->role;
            $membership->canRunTasks = in_array($invitation->role, ['admin', 'member']) ? 1 : 0;
            $membership->canEditTasks = in_array($invitation->role, ['admin', 'member']) ? 1 : 0;
            $membership->canDeleteTasks = $invitation->role === 'admin' ? 1 : 0;
            $membership->joinedAt = date('Y-m-d H:i:s');
            Bean::store($membership);

            // Mark invitation as accepted
            $invitation->acceptedAt = date('Y-m-d H:i:s');
            Bean::store($invitation);

            $this->commit();

            $this->logger->info('User joined team', [
                'team_id' => $team->id,
                'member_id' => $this->member->id,
                'role' => $invitation->role
            ]);

            $this->flash('success', 'Welcome to ' . $team->name . '!');
            Flight::redirect('/teams/view?id=' . $team->id);

        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('Failed to join team', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to join team');
            Flight::redirect('/teams');
        }
    }

    /**
     * Leave team
     */
    public function leave($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/teams');
            return;
        }

        $teamId = (int)$this->getParam('id');
        if (!$teamId) {
            Flight::redirect('/teams');
            return;
        }

        $team = Bean::load('team', $teamId);
        if (!$team->id) {
            $this->flash('error', 'Team not found');
            Flight::redirect('/teams');
            return;
        }

        // Owner cannot leave (must transfer or delete)
        if ($this->access->isTeamOwner($teamId, $this->member->id)) {
            $this->flash('error', 'Team owner cannot leave. Transfer ownership or delete the team.');
            Flight::redirect('/teams/view?id=' . $teamId);
            return;
        }

        // Find and remove membership
        $membership = Bean::findOne('teammember', 'team_id = ? AND member_id = ?', [$teamId, $this->member->id]);
        if ($membership) {
            Bean::trash($membership);
            $this->logger->info('User left team', ['team_id' => $teamId, 'member_id' => $this->member->id]);
        }

        $this->flash('success', 'You have left the team');
        Flight::redirect('/teams');
    }

    /**
     * Remove team member (admin only)
     */
    public function removemember($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/teams');
            return;
        }

        $teamId = (int)$this->getParam('team_id');
        $memberId = (int)$this->getParam('member_id');

        if (!$teamId || !$memberId) {
            Flight::jsonError('Invalid parameters', 400);
            return;
        }

        $team = Bean::load('team', $teamId);
        if (!$team->id) {
            Flight::jsonError('Team not found', 404);
            return;
        }

        if (!$this->access->canManageMembers($teamId, $this->member->id)) {
            Flight::jsonError('You cannot manage members in this team', 403);
            return;
        }

        // Cannot remove the owner
        if ((int)$team->ownerId === $memberId) {
            Flight::jsonError('Cannot remove the team owner', 400);
            return;
        }

        // Cannot remove yourself this way
        if ($memberId === $this->member->id) {
            Flight::jsonError('Use the leave function to leave the team', 400);
            return;
        }

        $membership = Bean::findOne('teammember', 'team_id = ? AND member_id = ?', [$teamId, $memberId]);
        if (!$membership) {
            Flight::jsonError('Member not found in team', 404);
            return;
        }

        Bean::trash($membership);

        $this->logger->info('Member removed from team', [
            'team_id' => $teamId,
            'removed_member_id' => $memberId,
            'removed_by' => $this->member->id
        ]);

        Flight::json(['success' => true, 'message' => 'Member removed']);
    }

    /**
     * Update member role (admin only)
     */
    public function updaterole($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/teams');
            return;
        }

        $teamId = (int)$this->getParam('team_id');
        $memberId = (int)$this->getParam('member_id');
        $role = $this->getParam('role', 'member');

        if (!$teamId || !$memberId) {
            Flight::jsonError('Invalid parameters', 400);
            return;
        }

        $team = Bean::load('team', $teamId);
        if (!$team->id) {
            Flight::jsonError('Team not found', 404);
            return;
        }

        // Only owner can change roles
        if (!$this->access->isTeamOwner($teamId, $this->member->id)) {
            Flight::jsonError('Only the team owner can change roles', 403);
            return;
        }

        // Cannot change owner's role
        if ((int)$team->ownerId === $memberId) {
            Flight::jsonError('Cannot change the owner\'s role', 400);
            return;
        }

        // Validate role
        if (!in_array($role, ['admin', 'member', 'viewer'])) {
            Flight::jsonError('Invalid role', 400);
            return;
        }

        $membership = Bean::findOne('teammember', 'team_id = ? AND member_id = ?', [$teamId, $memberId]);
        if (!$membership) {
            Flight::jsonError('Member not found in team', 404);
            return;
        }

        $membership->role = $role;
        $membership->canRunTasks = in_array($role, ['admin', 'member']) ? 1 : 0;
        $membership->canEditTasks = in_array($role, ['admin', 'member']) ? 1 : 0;
        $membership->canDeleteTasks = $role === 'admin' ? 1 : 0;
        Bean::store($membership);

        $this->logger->info('Member role updated', [
            'team_id' => $teamId,
            'member_id' => $memberId,
            'new_role' => $role
        ]);

        Flight::json(['success' => true, 'message' => 'Role updated']);
    }

    /**
     * Delete team (owner only)
     */
    public function delete($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/teams');
            return;
        }

        $teamId = (int)$this->getParam('id');
        if (!$teamId) {
            Flight::redirect('/teams');
            return;
        }

        $team = Bean::load('team', $teamId);
        if (!$team->id) {
            $this->flash('error', 'Team not found');
            Flight::redirect('/teams');
            return;
        }

        if (!$this->access->canDeleteTeam($teamId, $this->member->id)) {
            $this->flash('error', 'Only the team owner can delete the team');
            Flight::redirect('/teams/view?id=' . $teamId);
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Invalid CSRF token');
            Flight::redirect('/teams/settings?id=' . $teamId);
            return;
        }

        try {
            $this->beginTransaction();

            // Delete all team memberships
            $memberships = $team->ownTeammemberList;
            Bean::trashAll($memberships);

            // Delete all invitations
            $invitations = $team->ownTeaminvitationList;
            Bean::trashAll($invitations);

            // Orphan team tasks (set team_id to null, keep with original creator)
            $tasks = Bean::find('workbenchtask', 'team_id = ?', [$teamId]);
            foreach ($tasks as $task) {
                $task->teamId = null;
                Bean::store($task);
            }

            // Delete team
            Bean::trash($team);

            $this->commit();

            $this->logger->info('Team deleted', ['team_id' => $teamId, 'deleted_by' => $this->member->id]);

            $this->flash('success', 'Team deleted');
            Flight::redirect('/teams');

        } catch (Exception $e) {
            $this->rollback();
            $this->logger->error('Failed to delete team', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to delete team');
            Flight::redirect('/teams/settings?id=' . $teamId);
        }
    }

    /**
     * Generate URL-safe slug from name
     */
    private function generateSlug(string $name): string {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return substr($slug, 0, 50);
    }
}
