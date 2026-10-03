<?php
/**
 * PublishDriver — an export target: a copy of the project's code on a server of the
 * customer's own, run from the control plane.
 *
 * A project runs in its own container, and what you build IS the live site, so a driver
 * here never stands hosting up; it ships the container's HEAD somewhere else (rsync over
 * SSH, an SSH command). The interface is deliberately small, and everything optional is
 * reported through capabilities() rather than assumed:
 *
 *   deploy()   ship the code to the target
 *   status()   what is there right now (for the card: key ready, last run, last error)
 *   refresh()  ship again
 *   verify()   the handshake — reach the server, prove who we land as, nothing written
 *
 * capabilities()['code'] is true for every driver left: shipping the commit IS the deploy.
 */
namespace app\Publish;

interface PublishDriver {

    /** Stable key stored in the connection row (`metadata_json.driver`). */
    public static function key(): string;

    /** Human label for the card. */
    public static function label(): string;

    /** One line describing what this target does, shown under the label. */
    public static function blurb(): string;

    /**
     * What this driver supports, so the UI does not offer what it cannot do.
     * Recognised flags: code, domain, tls, refresh, recreate, logs, sshKey.
     * @return array<string,bool>
     */
    public static function capabilities(): array;

    /**
     * The privilege level required to run $op ('deploy' | 'refresh' | 'status'), checked
     * against the INSTANCE OWNER — a publish runs unattended from a pipeline, so there is
     * no logged-in person to ask.
     *
     * This exists because targets are not equally cheap. Pushing to a customer's own repo
     * costs us nothing and any member may do it; standing up a container spends real
     * hypervisor capacity, and the UI has always gated that at ADMIN. Without a level
     * here, routing both through one door would have handed every member the ability to
     * provision infrastructure just by naming a different target.
     *
     * Lower number = higher privilege, per LEVELS.
     */
    public static function minLevel(string $op): int;

    /**
     * The settings this target needs, so the UI can render them without knowing anything
     * about the driver. Each entry: name, label, type (text|textarea|number|host),
     * required?, placeholder?, help?.
     *
     * These are NOT credentials — a host, a user, a path, a domain. They are saved into
     * the project's publish pipeline where they are reviewable in the repo. Anything
     * secret (a PAT, a private key) stays on the control plane and never appears here.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function fields(): array;

    /**
     * Handshake: prove the connection works, WITHOUT publishing anything.
     *
     * For ssh and rsync the driver deliberately owns only the connection — the recipe for
     * what to do once you are there belongs to the customer's pipeline. That makes a
     * handshake the driver's real deliverable: without one, the only way to discover that
     * a key was never authorised is to fire a real publish and read the wreckage.
     *
     * Must be SAFE to run repeatedly and must change nothing on the far end.
     *
     * @param array $config the settings as currently entered — a handshake is most useful
     *                      BEFORE anything is saved
     * @return array{ok:bool, message:string, detail?:string[]}
     */
    public function verify(object $inst, array $config): array;

    /**
     * Create or reshape the target.
     * @param object $inst   instance registry row
     * @param array  $config connection metadata (domain, host, path, …)
     * @param array  $opts   caller options (recreate, cert, force, …)
     * @return array{ok:bool, steps?:string[], error?:string}
     */
    public function deploy(object $inst, array $config, array $opts = []): array;

    /**
     * Current state, for the card. Must never throw and must distinguish
     * "not configured" from "configured but not deployed".
     * @return array<string,mixed>
     */
    public function status(object $inst, array $config): array;

    /**
     * Re-apply settings to a live target, preserving its data. Drivers that cannot do
     * this report refresh=false in capabilities() and may return ok=false here.
     * @return array{ok:bool, steps?:string[], error?:string}
     */
    public function refresh(object $inst, array $config, array $opts = []): array;
}
