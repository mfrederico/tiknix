<?php
/**
 * PublishRegistry — the export targets a project's code can be copied to, and the driver
 * that runs each.
 *
 * A project runs in its own container and what you build IS the live site (merge is
 * publish), so there is no "hosting" target to pick any more: the drivers left are copies
 * of the container's HEAD to a server of the customer's own — rsync over SSH, or an SSH
 * command — run from the control plane with a key it keeps (Publish\SshTargetDriver). They
 * are offered on the Deploy page (controls/Deploy.php) and through the publish door
 * (controls/Publish.php) for a pipeline in the project.
 *
 * Gone with the host folders: 'tiknix-hosted' (the container is where every project runs
 * now, provisioned by TenantHost) and 'github-pr' (a PAT read on this host; publishing to a
 * repo is a pipeline in the project).
 */
namespace app\Publish;

class PublishRegistry {

    /** driver key => class. Order is the order shown in the UI. */
    private const DRIVERS = [
        'rsync' => RsyncDriver::class,
        'ssh'   => SshDriver::class,
    ];

    /**
     * @return array<int,array{key:string,label:string,blurb:string,capabilities:array,fields:array,available:bool,reason:string}>
     */
    public static function all(): array {
        $out = [];
        foreach (self::DRIVERS as $key => $class) {
            $out[] = [
                'key'          => $class::key(),
                'label'        => $class::label(),
                'blurb'        => $class::blurb(),
                'capabilities' => $class::capabilities(),
                'fields'       => $class::fields(),
                'available'    => true,
                'reason'       => '',
            ];
        }
        return $out;
    }

    /** @return PublishDriver|null */
    public static function driver(string $key): ?PublishDriver {
        $class = self::DRIVERS[$key] ?? null;
        return $class ? new $class() : null;
    }
}
