<?php
/**
 * ErrorReporter — instance-side error capture for the control-plane firehose.
 *
 * Modeled on cannonwms' ExceptionReporter/ErrorNotifier: a signature hash +
 * local rate-limit. But instead of emailing, it fire-and-forget POSTs the error
 * to the control plane, which dedups and — for a NEW signature on a PUBLISHED,
 * IDLE instance — auto-triages it into a fix workspace.
 *
 * Collision safety (see controls/Firehose.php for the control-plane half):
 *   Layer 1 (here) — origin gate: only reports when [firehose] role = "live".
 *   Task workspaces are stamped role = "workspace" by WorkspaceManager, so an
 *   agent's mid-build/test errors are muted and can never spawn a duplicate fix.
 *   Layers 2 (active-build guard) + 3 (signature dedup) live on the ingest side.
 *
 * Registration: bootstrap calls ErrorReporter::register() (a shutdown hook for
 * uncaught fatals). The common case — a controller throw — is captured
 * explicitly at the FlightMap dispatch catch. We deliberately do NOT override
 * set_exception_handler/set_error_handler so Flight's own handling is untouched.
 */

namespace app;

use Flight;

class ErrorReporter
{
    /** One POST per signature per window (seconds) — a hot error can't flood. */
    const RATE_WINDOW = 300;
    const STATE_DIR   = '/tmp/tiknix-firehose';
    /** Fire-and-forget: never stall the user's request on the reporter. */
    const TIMEOUT     = 2;

    public static function register(): void
    {
        // Additive only — catch true fatals (parse/OOM/timeout) that bypass the
        // controller try/catch. Flight keeps its own exception/error handlers.
        register_shutdown_function([self::class, 'onShutdown']);
    }

    public static function onShutdown(): void
    {
        $err = error_get_last();
        if (!$err) return;
        $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
        if (!($err['type'] & $fatal)) return;
        self::capture(
            new \ErrorException($err['message'], 0, $err['type'], $err['file'] ?? 'unknown', $err['line'] ?? 0),
            'php_fatal'
        );
    }

    /**
     * Explicit capture from a try/catch (the FlightMap controller catch is the
     * primary caller). Safe to call anywhere — it self-gates and never throws.
     *
     * @param string $type    controller | exception | php_error | php_fatal
     * @param array  $context Free-form extra payload (controller, method, …)
     */
    public static function capture(\Throwable $e, string $type = 'exception', array $context = []): void
    {
        try {
            if (!self::enabled()) return;

            $tag = self::instanceTag();
            $sig = md5(implode('|', [
                $tag,
                $type,
                $e->getFile() . ':' . $e->getLine(),
                self::firstLine($e->getMessage()),
            ]));
            if (self::rateLimited($sig)) return;
            self::markSent($sig);

            self::post([
                'signature'    => $sig,
                'type'         => $type,
                'instance'     => $tag,
                'message'      => self::firstLine($e->getMessage()),
                'full_message' => mb_substr((string)$e->getMessage(), 0, 2000),
                'class'        => get_class($e),
                'file'         => self::relFile($e->getFile()),
                'line'         => (int)$e->getLine(),
                'trace'        => mb_substr($e->getTraceAsString(), 0, 4000),
                'url'          => (string)($_SERVER['REQUEST_URI'] ?? ''),
                'http_method'  => (string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'),
                'context'      => $context,
            ]);
        } catch (\Throwable $inner) {
            // The reporter must never break the request it is reporting on.
        }
    }

    /** Layer 1 origin gate: report only from a configured, live instance. */
    private static function enabled(): bool
    {
        $url = (string)(Flight::get('firehose.ingest_url') ?? '');
        if ($url === '') return false;                       // not provisioned for firehose
        if (!filter_var(Flight::get('firehose.report') ?? true, FILTER_VALIDATE_BOOLEAN)) return false;
        return ((string)(Flight::get('firehose.role') ?? 'live')) === 'live';
    }

    private static function instanceTag(): string
    {
        $tag = (string)(Flight::get('firehose.instance') ?? '');
        if ($tag !== '') return $tag;
        // Derive from baseurl host: bidsurge.tiknix.com -> bidsurge.tiknix
        $base = (string)(Flight::get('app.baseurl') ?? Flight::get('baseurl') ?? '');
        $host = parse_url($base, PHP_URL_HOST) ?: preg_replace('#^https?://#', '', $base);
        return preg_replace('/\.com$/', '', (string)$host);
    }

    private static function post(array $payload): void
    {
        $url = (string)Flight::get('firehose.ingest_url');
        $key = (string)(Flight::get('firehose.api_key') ?? '');
        if (!function_exists('curl_init')) return;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Firehose-Key: ' . $key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
        ]);
        curl_exec($ch);

    }

    private static function rateLimited(string $sig): bool
    {
        $f = self::STATE_DIR . '/' . $sig;
        return is_file($f) && (time() - filemtime($f)) < self::RATE_WINDOW;
    }

    private static function markSent(string $sig): void
    {
        if (!is_dir(self::STATE_DIR)) @mkdir(self::STATE_DIR, 0770, true);
        @touch(self::STATE_DIR . '/' . $sig);
    }

    private static function firstLine(string $s): string
    {
        return mb_substr(trim((string)strtok($s, "\n")), 0, 300);
    }

    /** Best-effort repo-relative path so the ingest side can point Claude at it. */
    private static function relFile(string $f): string
    {
        $root = (string)(Flight::get('app.root') ?? dirname(__DIR__));
        return ($root !== '' && str_starts_with($f, $root)) ? ltrim(substr($f, strlen($root)), '/') : $f;
    }
}
