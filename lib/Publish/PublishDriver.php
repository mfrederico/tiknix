<?php
/**
 * PublishDriver — where an instance actually runs.
 *
 * "Publish" is its own connector category, not a property of the GitHub connection.
 * A publish connection answers one question — where does this instance run — and owns
 * everything that follows from it: the target's config, its domain binding, its
 * certificate, and how a change reaches it.
 *
 * Drivers differ enormously in what they control. Tiknix Hosted owns the whole stack
 * (container, proxy, TLS); an rsync target owns nothing but a directory on someone
 * else's server. So the interface is deliberately small, and everything optional is
 * reported through capabilities() rather than assumed:
 *
 *   deploy()   stand the target up, or update its shape
 *   status()   what is there right now (for the card)
 *   refresh()  re-apply settings to a live target without destroying data
 *
 * A driver NEVER routine-deploys code. Code reaches a target by its own mechanism —
 * the container's puller, a git push, an rsync run — so deploy() is for creating or
 * reshaping a target, not for shipping a commit.
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
     * Recognised flags: domain, tls, refresh, recreate, logs, sshKey.
     * @return array<string,bool>
     */
    public static function capabilities(): array;

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
