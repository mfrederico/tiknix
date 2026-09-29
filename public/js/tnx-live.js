/**
 * tnx-live — live delivery over MQTT, subscribe side.
 *
 * The broker is a doorbell. This connects, subscribes to the member's own topic
 * and calls back when something arrives; it never reads a message body off the
 * wire, because there isn't one — a wake carries ids and the page fetches the
 * actual content over HTTP, through the same authorisation as a page load.
 *
 * Hand-rolled rather than mqtt.js: this needs CONNECT, SUBSCRIBE, incoming
 * PUBLISH and a keepalive ping, and that is genuinely all. The library is 111KB
 * gzipped and would be on every page for a subscriber that fits in a screen of
 * code. The server half (lib/Mqtt.php) speaks the same handful of packets.
 *
 * If any of it fails — no broker, bad credential, hostile proxy — nothing here
 * throws. Polling is still running underneath and remains the guarantee; this
 * only ever makes it faster. That is why every failure path leads to "keep
 * trying quietly" rather than an error the member has to care about.
 *
 * Usage:
 *   TnxLive.start({ path, username, password, topic });
 *   TnxLive.onWake(function (data) { ... });   // data = {t, thread, msg}
 */
window.TnxLive = (function () {
    'use strict';

    // MQTT 3.1.1 control packet types, high nibble of byte 0.
    //
    // SUBSCRIBE is 0x82, not 0x80: its low nibble is RESERVED and the spec fixes
    // it at 0010 (§3.8.1). A broker is required to treat 0x80 as a protocol
    // violation and close the connection — which it does, silently, right after
    // a successful CONNACK, so it looks like the credential was rejected when it
    // was actually accepted.
    var CONNECT = 0x10, CONNACK = 0x20, PUBLISH = 0x30, SUBSCRIBE = 0x82,
        SUBACK  = 0x90, PINGREQ = 0xC0, PINGRESP = 0xD0, DISCONNECT = 0xE0;

    var KEEPALIVE  = 60;      // seconds; ping at half this
    var RETRY_MIN  = 2000;    // ms
    var RETRY_MAX  = 60000;   // ms — a broker that is down stays down; stop hammering

    var ws = null, cfg = null, buf = new Uint8Array(0),
        pingTimer = null, retryTimer = null, retryMs = RETRY_MIN,
        listeners = [], connected = false, stopped = false;

    // ---- packet building -----------------------------------------------------

    function utf8(s) {
        var bytes = new TextEncoder().encode(s);
        var out = new Uint8Array(2 + bytes.length);
        out[0] = (bytes.length >> 8) & 0xFF;
        out[1] = bytes.length & 0xFF;
        out.set(bytes, 2);
        return out;
    }

    /** MQTT's variable-length integer: 7 bits per byte, top bit = "more follows". */
    function remainingLength(n) {
        var out = [];
        do {
            var b = n % 128;
            n = Math.floor(n / 128);
            if (n > 0) b |= 0x80;
            out.push(b);
        } while (n > 0);
        return new Uint8Array(out);
    }

    function concat(parts) {
        var len = 0, i;
        for (i = 0; i < parts.length; i++) len += parts[i].length;
        var out = new Uint8Array(len), off = 0;
        for (i = 0; i < parts.length; i++) { out.set(parts[i], off); off += parts[i].length; }
        return out;
    }

    function packet(type, body) {
        return concat([new Uint8Array([type]), remainingLength(body.length), body]);
    }

    function send(bytes) {
        if (ws && ws.readyState === 1) { ws.send(bytes); return true; }
        return false;
    }

    // ---- connection lifecycle ------------------------------------------------

    function connect() {
        clearTimeout(retryTimer);
        if (stopped) return;

        var url = (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + cfg.path;

        try {
            // The 'mqtt' subprotocol is not decoration — mosquitto's websockets
            // listener rejects a handshake that does not ask for it.
            ws = new WebSocket(url, 'mqtt');
        } catch (e) {
            scheduleRetry();
            return;
        }
        ws.binaryType = 'arraybuffer';

        ws.onopen = function () {
            buf = new Uint8Array(0);

            // A client id must be unique on the broker: MQTT's answer to a collision
            // is to disconnect the older session, so two tabs sharing one id would
            // knock each other offline in a loop. Random per connection.
            var clientId = 'tnx-web-' + Math.random().toString(36).slice(2, 10) +
                                        Date.now().toString(36);

            var flags = 0x02;                       // clean session
            if (cfg.username) flags |= 0x80;
            if (cfg.password) flags |= 0x40;

            var parts = [
                utf8('MQTT'),
                new Uint8Array([0x04, flags, (KEEPALIVE >> 8) & 0xFF, KEEPALIVE & 0xFF]),
                utf8(clientId)
            ];
            if (cfg.username) parts.push(utf8(cfg.username));
            if (cfg.password) parts.push(utf8(cfg.password));

            send(packet(CONNECT, concat(parts)));
        };

        ws.onmessage = function (ev) {
            buf = concat([buf, new Uint8Array(ev.data)]);
            drain();
        };

        ws.onclose = function () {
            connected = false;
            clearInterval(pingTimer);
            scheduleRetry();
        };

        // An error is always followed by a close, which is where the retry lives.
        // Handling it here as well would double the backoff on every failure.
        ws.onerror = function () {};
    }

    function scheduleRetry() {
        if (stopped) return;
        clearTimeout(retryTimer);
        retryTimer = setTimeout(connect, retryMs);
        retryMs = Math.min(retryMs * 2, RETRY_MAX);
    }

    function subscribe() {
        // Packet id 1: with a single subscription and no QoS above 0 there is
        // nothing to correlate, so it never needs to vary.
        var body = concat([new Uint8Array([0, 1]), utf8(cfg.topic), new Uint8Array([0])]);
        send(packet(SUBSCRIBE, body));

        clearInterval(pingTimer);
        pingTimer = setInterval(function () {
            send(new Uint8Array([PINGREQ, 0]));
        }, KEEPALIVE * 500);   // half the keepalive, in ms
    }

    // ---- packet parsing ------------------------------------------------------

    /**
     * A WebSocket frame is not an MQTT packet: one frame can carry several
     * packets or half of one. Parse whatever is complete and leave the rest in
     * the buffer for the next frame.
     */
    function drain() {
        for (;;) {
            if (buf.length < 2) return;

            // Decode the remaining-length varint, which is 1-4 bytes from index 1.
            var mult = 1, len = 0, i = 1, b;
            do {
                if (i >= buf.length) return;          // varint itself is incomplete
                b = buf[i++];
                len += (b & 127) * mult;
                mult *= 128;
                if (i > 5) { reset(); return; }       // malformed; resync by reconnecting
            } while (b & 0x80);

            if (buf.length < i + len) return;         // body not all here yet

            handle(buf[0] & 0xF0, buf.subarray(i, i + len));
            buf = buf.slice(i + len);
        }
    }

    function handle(type, body) {
        if (type === CONNACK) {
            // Byte 1 is the return code. Non-zero means the credential was refused;
            // retrying instantly would just be a login loop against the broker, so
            // fall into the ordinary backoff.
            if (body.length >= 2 && body[1] === 0) {
                connected = true;
                retryMs = RETRY_MIN;                  // a good connection resets the backoff
                subscribe();
            } else if (ws) {
                ws.close();
            }
            return;
        }

        if (type === PUBLISH) {
            // QoS 0, so: topic then payload, with no packet identifier between them.
            if (body.length < 2) return;
            var tlen = (body[0] << 8) | body[1];
            var payload = body.subarray(2 + tlen);

            var data = {};
            try { data = JSON.parse(new TextDecoder().decode(payload)) || {}; }
            catch (e) { data = {}; }                  // a wake with no body is still a wake

            listeners.forEach(function (fn) {
                try { fn(data); } catch (e) { /* one bad listener must not stop the others */ }
            });
            return;
        }

        // SUBACK and PINGRESP need no action — their absence would show up as a
        // dead connection, which the close handler already deals with.
    }

    function reset() {
        buf = new Uint8Array(0);
        if (ws) ws.close();
    }

    // ---- public surface ------------------------------------------------------

    return {
        /** @param opts {path, username, password, topic} — see Mqtt::browserCredentials */
        start: function (opts) {
            if (!opts || !opts.topic || !window.WebSocket) return false;
            cfg = opts;
            stopped = false;
            connect();

            // Coming back to a tab is exactly when a stale socket is discovered.
            // Reconnect immediately rather than waiting out the backoff.
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden && !connected && !stopped) {
                    retryMs = RETRY_MIN;
                    connect();
                }
            });
            return true;
        },

        onWake: function (fn) { if (typeof fn === 'function') listeners.push(fn); },

        /** True while subscribed — the pollers use this to decide their cadence. */
        isConnected: function () { return connected; },

        stop: function () {
            stopped = true;
            clearInterval(pingTimer);
            clearTimeout(retryTimer);
            if (ws && ws.readyState === 1) send(new Uint8Array([DISCONNECT, 0]));
            if (ws) ws.close();
            connected = false;
        }
    };
})();
