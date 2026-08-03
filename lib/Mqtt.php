<?php
/**
 * MQTT publisher — the server half of live delivery.
 *
 * The broker is a doorbell, not a mailbox. What goes over it is "thread 42
 * changed, come and fetch it"; the message itself stays in the database and is
 * fetched over HTTP through the same authorisation as everything else. So a
 * payload here carries IDS ONLY. A worst-case leak reveals that a conversation
 * moved, not what was said in it.
 *
 * Speaks MQTT 3.1.1 over a plain socket to 127.0.0.1:1883 rather than pulling in
 * a client library: publishing at QoS 0 is CONNECT, PUBLISH, DISCONNECT and
 * nothing else. There is no subscribe side here — PHP never listens.
 *
 * A failed publish is LOGGED and reported, never swallowed, but it does not fail
 * the caller. Posting a message must not depend on the broker being up: the
 * browser still polls on a slow cadence, so a broker outage costs latency rather
 * than delivery. That is the one and only reason this returns false instead of
 * throwing.
 *
 * The broker itself is installed by capricorn (configure/install-mosquitto.sh);
 * see capricorn/docs/MQTT.md for the listeners, the topic ACL and the account
 * model this file assumes.
 */

namespace app;

use \Flight;

class Mqtt {

    /** Every topic this system uses lives under here. Mirrors the broker ACL. */
    public const TOPIC_ROOT = 'tnx';

    /** MQTT 3.1.1. The broker also speaks 5, but nothing here needs it. */
    private const PROTOCOL_LEVEL = 0x04;

    /**
     * Short by design. This runs inside a web request that is already holding a
     * stored message; a broker that is wedged must cost milliseconds, not the
     * page. Anything slower than this is an outage, and the poll fallback covers
     * an outage perfectly well.
     */
    private const TIMEOUT = 1.0;

    private static ?self $instance = null;

    /** @var resource|null */
    private $socket = null;
    private bool $failed = false;   // don't re-dial a broker that just refused us

    public static function getInstance(): self {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    // ---- configuration ----------------------------------------------------------------

    /**
     * Is live delivery configured?
     *
     * Enabled-but-misconfigured is an ERROR, not a quiet no. A half-set-up broker
     * silently degrading to polling is exactly the failure that goes unnoticed for
     * a month, so it says so once per request and then behaves as off.
     */
    public static function enabled(): bool {
        if (!Flight::get('mqtt.enabled')) return false;

        if (self::secret() === '') {
            self::logOnce('Mqtt: [mqtt] enabled but secret is empty — live delivery is OFF. '
                        . 'Set [mqtt] secret in conf/config.ini (openssl rand -hex 32).');
            return false;
        }
        return true;
    }

    private static function secret(): string {
        return trim((string) Flight::get('mqtt.secret'));
    }

    private static function host(): string {
        return (string) (Flight::get('mqtt.host') ?: '127.0.0.1');
    }

    private static function port(): int {
        return (int) (Flight::get('mqtt.port') ?: 1883);
    }

    /** The path browsers connect to. nginx proxies it to the websockets listener. */
    public static function wsPath(): string {
        return (string) (Flight::get('mqtt.ws_path') ?: '/mqtt');
    }

    // ---- member credentials -----------------------------------------------------------

    /**
     * A member's broker username is their id, and that is the whole of the access
     * model: the broker ACL is `pattern read tnx/%u/#`, so the username the broker
     * has already authenticated decides the subtree and there is no per-member rule
     * to write, or to forget to remove.
     */
    public static function usernameFor(int $memberId): string {
        return (string) $memberId;
    }

    /**
     * Deterministic, so nothing is stored and nothing can drift out of step with
     * the account list — the broker's password file and this function are two
     * derivations of the same secret rather than two copies of a value.
     *
     * Rotating [mqtt] secret invalidates every credential at once, which is the
     * behaviour you want from a rotation.
     */
    public static function passwordFor(int $memberId): string {
        $secret = self::secret();
        if ($secret === '') {
            // Returning '' here would mint a credential that authenticates nobody and
            // looks like a broker fault at 3am. Refuse instead.
            throw new \RuntimeException('Mqtt: cannot derive a credential with no [mqtt] secret');
        }
        return hash_hmac('sha256', 'mqtt:member:' . $memberId, $secret);
    }

    /** Everything a browser needs to connect, or null when live delivery is off. */
    public static function browserCredentials(int $memberId): ?array {
        if ($memberId <= 0 || !self::enabled()) return null;

        return [
            'path'     => self::wsPath(),
            'username' => self::usernameFor($memberId),
            'password' => self::passwordFor($memberId),
            'topic'    => self::TOPIC_ROOT . '/' . $memberId . '/#',
        ];
    }

    // ---- publishing -------------------------------------------------------------------

    /**
     * Tell one member something changed. IDs only — see the file header.
     *
     * @return bool false if the notice did not reach the broker (already logged)
     */
    public static function wake(int $memberId, array $data = []): bool {
        if ($memberId <= 0 || !self::enabled()) return false;

        return self::getInstance()->publish(
            self::TOPIC_ROOT . '/' . $memberId . '/wake',
            json_encode($data, JSON_UNESCAPED_SLASHES)
        );
    }

    /** Wake several people about the same thing. Returns how many notices went out. */
    public static function wakeAll(array $memberIds, array $data = []): int {
        if (!self::enabled()) return 0;

        $sent = 0;
        foreach (array_unique(array_map('intval', $memberIds)) as $id) {
            if (self::wake($id, $data)) $sent++;
        }
        return $sent;
    }

    public function publish(string $topic, string $payload): bool {
        if (!$this->connect()) return false;

        // PUBLISH, QoS 0: fixed header, then a length-prefixed topic, then the body.
        // No packet identifier — that belongs to QoS 1 and 2, which would mean waiting
        // for an acknowledgement inside a web request for a message we are willing to
        // lose anyway.
        $body = self::str($topic) . $payload;

        if (!$this->write("\x30" . self::remainingLength(strlen($body)) . $body)) {
            $this->fail('Mqtt: publish to ' . $topic . ' failed mid-write');
            return false;
        }
        return true;
    }

    // ---- wire protocol ----------------------------------------------------------------

    private function connect(): bool {
        if (is_resource($this->socket)) return true;
        if ($this->failed) return false;     // one dial per request, not one per recipient

        $host = self::host();
        $port = self::port();

        $errno = 0; $errstr = '';
        $sock = @fsockopen($host, $port, $errno, $errstr, self::TIMEOUT);
        if (!$sock) {
            $this->fail(sprintf('Mqtt: cannot reach broker at %s:%d — %s (%d)',
                $host, $port, $errstr ?: 'no error reported', $errno));
            return false;
        }
        stream_set_timeout($sock, (int) self::TIMEOUT, (int) ((self::TIMEOUT - (int) self::TIMEOUT) * 1e6));
        $this->socket = $sock;

        // A client id must be unique on the broker: two connections sharing one are
        // MQTT's definition of "disconnect the other". Concurrent PHP-FPM workers
        // publishing at once is normal, so this has to be per-connection, not per-host.
        $clientId = 'tnx-php-' . bin2hex(random_bytes(8));

        $user = (string) (Flight::get('mqtt.username') ?: '');
        $pass = (string) (Flight::get('mqtt.password') ?: '');

        $flags = 0x02;                                   // clean session
        if ($user !== '') $flags |= 0x80;
        if ($pass !== '') $flags |= 0x40;

        $vh = self::str('MQTT') . chr(self::PROTOCOL_LEVEL) . chr($flags) . pack('n', 60);
        $payload = self::str($clientId);
        if ($user !== '') $payload .= self::str($user);
        if ($pass !== '') $payload .= self::str($pass);

        $packet = $vh . $payload;
        if (!$this->write("\x10" . self::remainingLength(strlen($packet)) . $packet)) {
            $this->fail('Mqtt: broker closed the connection during CONNECT');
            return false;
        }

        // CONNACK is 4 bytes: 0x20, length 2, session-present flag, return code.
        // Reading it is not optional politeness — it is the only place a wrong
        // password or a missing account is ever reported.
        $ack = @fread($this->socket, 4);
        if ($ack === false || strlen($ack) < 4 || $ack[0] !== "\x20") {
            $this->fail('Mqtt: no CONNACK from broker (got ' . strlen((string) $ack) . ' bytes)');
            return false;
        }

        $code = ord($ack[3]);
        if ($code !== 0) {
            $this->fail('Mqtt: broker refused the connection — ' . self::connackReason($code)
                      . '. Check the tnx-pub account in /etc/mosquitto/tiknix.passwd '
                      . 'and [mqtt] username/password in conf/config.ini.');
            return false;
        }

        return true;
    }

    /** CONNACK return codes, MQTT 3.1.1 §3.2.2.3. */
    private static function connackReason(int $code): string {
        return [
            1 => 'unacceptable protocol version',
            2 => 'client id rejected',
            3 => 'broker unavailable',
            4 => 'bad username or password',
            5 => 'not authorised',
        ][$code] ?? ('unknown return code ' . $code);
    }

    private function write(string $bytes): bool {
        if (!is_resource($this->socket)) return false;

        // fwrite can be short on a socket. Loop rather than assume, or a long
        // payload silently arrives truncated and the broker drops the connection.
        $len = strlen($bytes);
        for ($off = 0; $off < $len;) {
            $n = @fwrite($this->socket, substr($bytes, $off));
            if ($n === false || $n === 0) return false;
            $off += $n;
        }
        return true;
    }

    /** Length-prefixed UTF-8 string, the MQTT encoding for topics and ids. */
    private static function str(string $s): string {
        return pack('n', strlen($s)) . $s;
    }

    /**
     * MQTT's variable-length integer: seven bits per byte, the top bit meaning
     * "another byte follows".
     */
    private static function remainingLength(int $n): string {
        $out = '';
        do {
            $byte = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) $byte |= 0x80;
            $out .= chr($byte);
        } while ($n > 0);
        return $out;
    }

    private function fail(string $message): void {
        self::logOnce($message);
        $this->failed = true;
        $this->close();
    }

    /**
     * One line per request, not one per recipient. A broker that is down would
     * otherwise write a log line for every participant of every message, which
     * buries the first and most useful one.
     */
    private static function logOnce(string $message): void {
        static $seen = [];
        if (isset($seen[$message])) return;
        $seen[$message] = true;

        $log = Flight::get('log');
        if ($log) $log->error($message);
    }

    private function close(): void {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, "\xE0\x00");   // DISCONNECT
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    public function __destruct() {
        $this->close();
    }
}
