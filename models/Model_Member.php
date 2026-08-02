<?php
/**
 * Member FUSE Model — behaviour that belongs to a person.
 *
 * Reached through the bean: RedBean always hands back an OODBBean, and an undefined
 * method on it dispatches here via __call. So `$member->displayName()` works anywhere a
 * member bean is in scope, with no type changes at the call site. `$member->box()` gets
 * this object directly if a caller wants it typed.
 *
 * Enables associations for the member bean: ownApikeyList, ownContactList,
 * ownSettingsList, ownTeammemberList.
 */

class Model_Member extends \RedBeanPHP\SimpleModel {

    /** Sending EMAIL from Communications is granted per person; in-app messaging is not. */
    private const EMAIL_FLAG = 'email_out';

    /**
     * First and last name joined, or '' when neither is set.
     *
     * One place, because the join was being rebuilt inline wherever it was needed — and
     * every copy carried the same dead defence, `$member->firstName ?? $member->first_name`.
     * RedBean resolves BOTH spellings to the same column, so that fallback can never fire;
     * it reads like care and is noise.
     */
    public function fullName(): string {
        return trim(trim((string) ($this->bean->firstName ?? ''))
             . ' ' . trim((string) ($this->bean->lastName ?? '')));
    }

    /**
     * The name to show for this person.
     *
     * ONE chain, because there were three. This model had its own that ignored
     * display_name entirely, lib/functions.php had another, and controls/Invites.php had a
     * third — so the same account read "Matthew Frederico" on the teams page and "Tiknix
     * Team" in Communications, and an invitation once went out saying "12:54:54 has
     * invited you" because a bare timestamp in display_name is a non-empty string and
     * therefore truthy.
     *
     * A candidate must contain a LETTER to win. A name with no letter in it is not a name,
     * whatever else it is.
     *
     * NOTE: `$member->displayName` is the COLUMN and `$member->displayName()` is this
     * method. PHP tells them apart, but they are not the same answer — the column is one
     * candidate, this is the decision.
     */
    public function displayName(string $fallback = 'Unknown'): string {
        $bean  = $this->bean;
        $named = fn(string $s) => $s !== '' && preg_match('/\p{L}/u', $s);

        $display = trim((string) ($bean->displayName ?? ''));
        if ($named($display)) return $display;

        $full = $this->fullName();
        if ($named($full)) return $full;

        $username = trim((string) ($bean->username ?? ''));
        if ($named($username)) return $username;

        // Local part of the email, as a last resort before giving up.
        $email = trim((string) ($bean->email ?? ''));
        if ($email !== '') {
            $local = explode('@', $email)[0];
            if ($named($local)) return $local;
        }

        return $fallback;
    }

    /** Initials for an avatar chip. */
    public function initials(): string {
        $parts = preg_split('/[\s@._-]+/', $this->displayName(), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $a = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1));
        $b = isset($parts[1]) ? mb_strtoupper(mb_substr($parts[1], 0, 1)) : '';
        return ($a . $b) ?: '?';
    }

    /**
     * Is this the public-user-entity — i.e. nobody signed in?
     *
     * Asked of the identity itself rather than by comparing ids at each call site, so
     * there is one answer and it cannot drift.
     */
    public function isGuest(): bool {
        return (int) $this->bean->level === LEVELS['PUBLIC']
            || (string) $this->bean->username === 'public-user-entity';
    }

    public function isAdmin(): bool {
        return (int) $this->bean->level <= LEVELS['ADMIN'];
    }

    /** Teams this member belongs to. */
    public function teamIds(): array {
        $id = (int) $this->bean->id;
        if ($id <= 0) return [];
        // array_values: find() returns id-KEYED rows, and an id-keyed array passed into an
        // IN (?,?) binding maps its KEYS to parameter positions. See CLAUDE.md.
        return array_values(array_unique(array_map(
            fn($r) => (int) $r->teamId,
            \app\Bean::find('teammember', 'member_id = ?', [$id])
        )));
    }

    /**
     * Everyone who shares at least one team with this member, excluding themselves.
     * Returns member beans, so a caller can render a name without a second query.
     */
    public function teammates(): array {
        $teamIds = $this->teamIds();
        if (!$teamIds) return [];

        $ids = array_values(array_unique(array_map('intval', \app\Bean::getCol(
            'SELECT DISTINCT member_id FROM teammember WHERE team_id IN ('
            . \app\Bean::genSlots($teamIds) . ')', $teamIds
        ))));
        $ids = array_values(array_filter($ids, fn($i) => $i !== (int) $this->bean->id));
        if (!$ids) return [];

        return array_values(\app\Bean::find('member',
            'id IN (' . \app\Bean::genSlots($ids) . ') AND status = ? ORDER BY username',
            array_merge($ids, ['active'])));
    }

    // ---- instances -------------------------------------------------------------------

    /** Instances this member owns outright. */
    public function ownedInstanceIds(): array {
        $id = (int) $this->bean->id;
        if ($id <= 0 || !in_array('instance', \app\Bean::inspect(), true)) return [];
        return array_values(array_map('intval', \app\Bean::getCol(
            'SELECT id FROM instance WHERE member_id = ?', [$id])));
    }

    /**
     * Instances shared with a team this member is on.
     *
     * DISTINCT deliberately: an instance shared with two teams the same person belongs to
     * is one instance. TaskAccessControl had it, ProjectContext's copy did not, and they
     * agreed only because nothing is multi-team shared yet — a duplicate id would have
     * flowed straight into an IN (?,?) binding.
     */
    public function sharedInstanceIds(): array {
        $teamIds = $this->teamIds();
        if (!$teamIds || !in_array('instance_team', \app\Bean::inspect(), true)) return [];

        return array_values(array_unique(array_map('intval', \app\Bean::getCol(
            'SELECT DISTINCT instance_id FROM instance_team WHERE team_id IN ('
            . \app\Bean::genSlots($teamIds) . ')', $teamIds))));
    }

    /** Everything this member may use: owned plus shared. */
    public function accessibleInstanceIds(): array {
        return array_values(array_unique(array_merge(
            $this->ownedInstanceIds(), $this->sharedInstanceIds())));
    }

    /**
     * Every project this member may work on, as beans — the source list for the picker.
     *
     * Owned first, then shared, and DELETED ones excluded. That last part is why this is
     * not just a load() over accessibleInstanceIds(): the id list deliberately includes
     * everything the member may touch, while the picker must not offer a project that has
     * been destroyed.
     */
    public function accessibleInstances(): array {
        $id = (int) $this->bean->id;
        if ($id <= 0) return [];

        $own = \app\Bean::find('instance', 'member_id = ? AND status != ?', [$id, 'deleted']);

        $shared = [];
        $ids = $this->sharedInstanceIds();
        if ($ids) {
            $shared = \app\Bean::find('instance',
                'id IN (' . \app\Bean::genSlots($ids) . ') AND member_id != ? AND status != ?',
                array_merge($ids, [$id, 'deleted']));
        }

        // array_values because find() returns beans keyed by id — merging keyed arrays
        // would silently drop rows whose ids collide across the two result sets.
        return array_merge(array_values($own), array_values($shared));
    }

    public function sharesTeamWith(int $otherId): bool {
        if ($otherId <= 0) return false;
        $other = \app\Bean::load('member', $otherId);
        if (!$other->id) return false;
        return (bool) array_intersect($this->teamIds(), $other->box()->teamIds());
    }

    /**
     * May this member open a conversation with another?
     *
     * You may message people you share a team with. Not everyone with an account — an
     * invite-only product still contains strangers, and a messenger where anyone can open
     * a conversation with anyone is a spam surface with a nicer name. Admins are exempt;
     * they can already act on every account.
     */
    public function canMessage(int $otherId): bool {
        $me = (int) $this->bean->id;
        if ($me <= 0 || $otherId <= 0 || $me === $otherId) return false;
        if ($this->isAdmin()) return true;
        return $this->sharesTeamWith($otherId);
    }

    /**
     * May this member send EMAIL out of Communications?
     *
     * In-app messaging needs no grant, because both ends already share a team. Email
     * leaves the building under Tiknix's name and reaches addresses nobody verified, so
     * it is handed out per person like invitations.
     */
    public function canSendEmail(): bool {
        return \app\Feature::allows(self::EMAIL_FLAG, (int) $this->bean->id, (int) $this->bean->level);
    }
}
