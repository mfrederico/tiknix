<?php
/**
 * Storebroker — the control-plane checkout broker for the shop.tiknix sidecar.
 *
 * The sidecar NEVER holds a Stripe secret or core's app_key (custody stays on the
 * control plane — the same discipline as Mcp::brokerToolCall). Instead the sidecar
 * makes ONE signature-verified server-to-server call here with the line items it
 * priced server-side; this endpoint resolves the instance's OWN BYO-Stripe
 * connection, decrypts it in-process, creates a hosted Checkout Session, zeroes the
 * secret, and returns only the hosted URL.
 *
 * Trust model: the shared `[sidecar.shop] sso_secret` (aud "shop-checkout") signs
 * the request, so a buyer's browser can never forge prices — the sidecar set them.
 * The instance's owner + connection are looked up HERE from the instance id; the
 * request's claims about ownership are never trusted. authcontrol: storebroker::* =
 * 101 (public — it authenticates itself via the HMAC signature).
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Sidecar\Token;
use app\services\connectors\ConnectorRegistry;
use RedBeanPHP\R;

class Storebroker extends Control {

    /** POST /storebroker/checkout — { token: <signed> } → { url } | error. */
    public function checkout($params = []) {
        $secret = (string) (Flight::get('sidecar.shop.sso_secret') ?? '');
        if ($secret === '') { Flight::jsonError('Store checkout is not configured.', 503); return; }

        $token  = (string) ($this->getParam('token') ?: '');
        $claims = $token !== '' ? Token::verify($token, $secret, 'shop-checkout') : null;
        if (!$claims) { Flight::jsonError('Invalid checkout request.', 403); return; }

        $instanceId = (int) ($claims['instance_id'] ?? 0);
        $items      = is_array($claims['items'] ?? null) ? $claims['items'] : [];
        $email      = (string) ($claims['email'] ?? '');
        $successUrl = $this->safeUrl((string) ($claims['success_url'] ?? ''));
        $cancelUrl  = $this->safeUrl((string) ($claims['cancel_url'] ?? ''));
        if ($instanceId <= 0 || !$items || !$successUrl || !$cancelUrl) { Flight::jsonError('Malformed checkout request.', 400); return; }

        // Resolve the instance + its owner HERE (never trust client-supplied ownership).
        $inst = R::load('instance', $instanceId);
        if (!$inst->id) { Flight::jsonError('Store not found.', 404); return; }
        $ownerId = (int) $inst->memberId;

        // The instance's OWN enabled Stripe (Payments) connection.
        [$conn, $connector] = $this->resolveInstancePayment($ownerId, $instanceId);
        if (!$conn || !$connector) { Flight::jsonError('This store is not set up to accept payments.', 409); return; }

        // Normalize the signed line items (prices came from the sidecar, server-side).
        $lineItems = [];
        foreach ($items as $it) {
            $amt = max(0, (int) ($it['amount_cents'] ?? 0));
            $qty = max(1, min(999, (int) ($it['quantity'] ?? 1)));
            $title = mb_substr(trim((string) ($it['title'] ?? 'Item')), 0, 200);
            if ($amt <= 0) continue;
            $lineItems[] = ['title' => $title, 'amount_cents' => $amt,
                'currency' => strtolower(substr((string) ($it['currency'] ?? 'usd'), 0, 3)), 'quantity' => $qty];
        }
        if (!$lineItems) { Flight::jsonError('Empty cart.', 400); return; }

        $tokenPlain = EncryptionService::decrypt($conn->accessToken);
        $url = '';
        try {
            $res = $connector->createCheckout($conn, $tokenPlain, [
                'mode'  => 'payment',
                'items' => $lineItems,
                'success_url'         => $successUrl,
                'cancel_url'          => $cancelUrl,
                'client_reference_id' => 'shop:' . $instanceId . ':' . ($claims['order_id'] ?? ''),
                'collect'             => ['billing' => true, 'phone' => false, 'shipping' => false, 'countries' => ['US']],
            ]);
            $url = (string) ($res['url'] ?? '');
        } catch (\Throwable $e) {
            error_log('[storebroker] checkout failed: ' . $e->getMessage());
        } finally {
            if (function_exists('sodium_memzero')) sodium_memzero($tokenPlain);
        }

        if ($url === '') { Flight::jsonError('Could not start checkout.', 502); return; }
        Flight::json(['url' => $url]);
    }

    /**
     * POST /storebroker/webhook — verify a Stripe webhook the shop sidecar forwarded.
     *
     * The webhook lands on the STORE's own domain (the sidecar); only core can verify
     * it, because the per-instance webhook secret (whsec) is encrypted with core's
     * app_key. The sidecar signs the forward with the shop secret + names the instance
     * (from its storefront URL); core resolves that instance's connection, decrypts the
     * whsec + token, has the connector verify Stripe's signature + re-fetch the session,
     * and returns the paid order reference. Signature failure → 400 so Stripe retries.
     *
     * Request: { token: <signed {instance_id} aud 'shop-webhook'>, payload: <raw>,
     *            sig: <Stripe-Signature header> }
     * Reply:   { paid: true, order_id, session_id } | { paid: false } | error
     */
    public function webhook($params = []) {
        $secret = (string) (Flight::get('sidecar.shop.sso_secret') ?? '');
        if ($secret === '') { Flight::jsonError('Not configured.', 503); return; }

        $claims = Token::verify((string) ($this->getParam('token') ?: ''), $secret, 'shop-webhook');
        if (!$claims) { Flight::jsonError('Invalid webhook request.', 403); return; }
        $instanceId = (int) ($claims['instance_id'] ?? 0);
        $raw = (string) ($this->getParam('payload') ?: '');
        $sig = (string) ($this->getParam('sig') ?: '');
        if ($instanceId <= 0 || $raw === '') { Flight::jsonError('Malformed.', 400); return; }

        $inst = R::load('instance', $instanceId);
        if (!$inst->id) { Flight::jsonError('Store not found.', 404); return; }
        [$conn, $connector] = $this->resolveInstancePayment((int) $inst->memberId, $instanceId);
        if (!$conn || !$connector) { Flight::json(['paid' => false, 'reason' => 'no-connection']); return; }

        $token  = EncryptionService::decrypt($conn->accessToken);
        $whsec  = '';
        $encW = (string) ($conn->webhookSecret ?? '');
        if ($encW !== '') { try { $whsec = EncryptionService::decrypt($encW); } catch (\Throwable $e) { $whsec = ''; } }
        $status = 502; $out = ['paid' => false];
        try {
            $order = $connector->webhookOrder($conn, $token, $raw, ['Stripe-Signature' => $sig], $whsec);
            if ($order && !empty($order['session_id'])) {
                // reference = "shop:<instanceId>:<orderId>" — trust only a matching instance.
                $ref = explode(':', (string) ($order['reference'] ?? ''));
                if (($ref[0] ?? '') === 'shop' && (int) ($ref[1] ?? 0) === $instanceId && (int) ($ref[2] ?? 0) > 0) {
                    $out = ['paid' => true, 'order_id' => (int) $ref[2], 'session_id' => (string) $order['session_id']];
                }
            }
            $status = 200;
        } catch (\Throwable $e) {
            error_log('[storebroker] webhook verify failed: ' . $e->getMessage());
            $status = 400;   // signature / fetch failure — Stripe will retry
        } finally {
            if (function_exists('sodium_memzero')) { sodium_memzero($token); if ($whsec !== '') sodium_memzero($whsec); }
        }
        if ($status !== 200) { Flight::jsonError('Webhook verification failed.', $status); return; }
        Flight::json($out);
    }

    /** An instance's own enabled Payments connection + its connector, or [null,null]. */
    private function resolveInstancePayment(int $memberId, int $instanceId): array {
        if ($memberId <= 0 || $instanceId <= 0) return [null, null];
        $env = 'production';
        foreach (ConnectorRegistry::all() as $c) {
            if (($c->meta()['category'] ?? '') !== 'Payments') continue;
            foreach (['production', 'development'] as $tryEnv) {
                $conn = Bean::findOne('connections',
                    'member_id = ? AND instance_id = ? AND environment = ? AND connector_type = ? AND enabled = 1',
                    [$memberId, $instanceId, $tryEnv, $c->key()]);
                if ($conn && $conn->id && empty($conn->revokedAt)) return [$conn, $c];
            }
        }
        return [null, null];
    }

    /** Only allow success/cancel URLs back to the store sidecar host. */
    private function safeUrl(string $url): ?string {
        if (!preg_match('#^https?://#i', $url)) return null;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $shopHost = strtolower((string) parse_url((string) (Flight::get('sidecar.shop.url') ?? ''), PHP_URL_HOST));
        return ($shopHost !== '' && $host === $shopHost) ? $url : null;
    }
}
