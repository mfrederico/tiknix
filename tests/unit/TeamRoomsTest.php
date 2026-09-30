<?php
/**
 * A team's rooms follow its membership (RUNTIME-SPLIT-MAP.md step 5, found by the app-teams
 * e2e spec on a carried app): someone who leaves a team, or is removed from it, is out of its
 * #general too — before, they kept the room and could read the team's conversation.
 */

namespace tests\unit;

use app\Bean;

require_once __DIR__ . '/ConceptsTestCase.php';

class TeamRoomsTest extends ConceptsTestCase {

    private const DB = 'team-rooms-test';

    protected function setUp(): void {
        parent::setUp();
        self::memoryDb();
        if (!Bean::hasDatabase(self::DB)) Bean::addDatabase(self::DB, 'sqlite::memory:');
        Bean::selectDatabase(self::DB);
        \RedBeanPHP\R::nuke();
    }

    protected function tearDown(): void { Bean::selectDatabase('default'); parent::tearDown(); }

    private function member(string $name): \RedBeanPHP\OODBBean {
        $m = Bean::dispense('member');
        $m->email = "{$name}@example.com"; $m->username = $name; $m->level = 100; $m->status = 'active';
        Bean::store($m);
        return $m;
    }

    public function testLeavingATeamLeavesItsRooms(): void {
        $owner = $this->member('roomowner'); $guest = $this->member('roomguest');
        $team = Bean::dispense('team');
        $team->name = 'Crew'; $team->slug = 'crew-' . bin2hex(random_bytes(3)); $team->ownerId = (int) $owner->id; $team->isActive = 1;
        Bean::store($team);
        $memberships = [];
        foreach ([[$owner, 'owner'], [$guest, 'member']] as [$m, $role]) {
            $tm = Bean::dispense('teammember');
            $tm->teamId = (int) $team->id; $tm->memberId = (int) $m->id; $tm->role = $role;
            Bean::store($tm);
            $memberships[$role] = $tm;
        }
        $room = $team->box()->generalRoom();
        $this->assertNotNull($room);
        $room->box()->syncWithTeam();
        $this->assertEqualsCanonicalizing([(int) $owner->id, (int) $guest->id], $room->box()->participantIds());

        Bean::trash($memberships['member']);          // what Teams::leave / removemember do …
        $team->box()->syncRooms();                    // … and then this
        $this->assertSame([(int) $owner->id], array_values($room->box()->participantIds()), 'the one who left is out of #general');
    }
}
