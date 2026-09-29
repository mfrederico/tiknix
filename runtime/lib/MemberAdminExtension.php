<?php
/**
 * MemberAdminExtension — what the member admin screens (controls/Admin) do beyond the
 * runtime's own fields. The runtime knows members; the PLATFORM also bills them for
 * projects (quotas, free-project grants, plan tiers, billing tenants, invitations), so it
 * plugs that in through Admin::$memberExtension (lib/controlplane.php). An app sets none.
 * RUNTIME-SPLIT-MAP.md step 2.
 */

namespace app;

use RedBeanPHP\OODBBean;

interface MemberAdminExtension {

    /** Per-member extra for the member list, keyed by member id (the view shows what it gets). */
    public function listing(array $members): array;

    /** Before an edited member is stored: apply the extension's own posted fields. */
    public function beforeSave(OODBBean $member, object $data, int $adminId): void;

    /** After an edited member is stored. */
    public function afterSave(OODBBean $member): void;

    /** Before the edit screen reads the member (may stamp lazily-computed columns). */
    public function prepareEdit(int $memberId): void;

    /** Extra view data for the edit screen; 'panel' names a view the edit screen includes. */
    public function editView(OODBBean $member): array;

    /** A member about to be deleted (the row still exists). */
    public function deleting(int $memberId): void;

    /** A member an admin just created. */
    public function created(OODBBean $member): void;
}
