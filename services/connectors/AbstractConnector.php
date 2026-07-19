<?php
/**
 * AbstractConnector — shared plumbing for connectors: control-plane credential
 * loading (conf/<key>.ini) and a tiny cURL helper. Concrete connectors implement
 * key(), meta(), authorizeUrl() and exchangeCode().
 */

namespace app\services\connectors;

abstract class AbstractConnector implements ConnectorInterface {

    /** Full conf/<key>.ini contents (control plane only). */
    protected function config(): array {
        // services/connectors/ -> services/ -> project root
        $file = dirname(__DIR__, 2) . '/conf/' . $this->key() . '.ini';
        return @parse_ini_file($file, true) ?: [];
    }

    /** The [oauth] section of conf/<key>.ini. */
    protected function oauth(): array {
        $c = $this->config();
        return $c['oauth'] ?? [];
    }

    public function isConfigured(): bool {
        $o = $this->oauth();
        return !empty($o['client_id']) && !empty($o['client_secret']);
    }

    /** Connectors are OAuth-only by default; api_key connectors override this. */
    public function validateApiKey(string $key): array {
        throw new \Exception('Connector "' . $this->key() . '" does not support API-key auth.');
    }

    /** Connectors expose no broker tools by default; override to add them. */
    public function brokerTools(): array {
        return [];
    }

    public function callBrokerTool(string $tool, $conn, string $token, array $args): array {
        throw new \Exception('Connector "' . $this->key() . '" exposes no broker tools.');
    }

    /** Non-payment connectors don't support checkout; payment providers override. */
    public function createCheckout($conn, string $token, array $order): array {
        throw new \Exception('Connector "' . $this->key() . '" is not a payment provider.');
    }

    /** Non-payment connectors have no webhook orders; payment providers override. */
    public function webhookOrder($conn, string $token, string $rawBody, array $headers, string $secret = ''): ?array {
        return null;
    }

    /** Non-payment connectors have no subscriptions; payment providers override. */
    public function subscriptionFromEvent($conn, string $token, string $rawBody, array $headers, string $secret = ''): ?array {
        return null;
    }

    /** Non-payment connectors have no billing portal; payment providers override. */
    public function billingPortalUrl($conn, string $token, string $customerId, string $returnUrl): string {
        throw new \Exception('Connector "' . $this->key() . '" has no billing portal.');
    }

    /**
     * Minimal cURL request. Returns [httpStatus, body].
     * @param array $opts ['headers' => string[], 'body' => string]
     */
    protected function http(string $method, string $url, array $opts = []): array {
        $ch = curl_init($url);
        $co = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $opts['headers'] ?? [],
        ];
        if (strtoupper($method) === 'POST') {
            $co[CURLOPT_POST]       = true;
            $co[CURLOPT_POSTFIELDS] = $opts['body'] ?? '';
        }
        curl_setopt_array($ch, $co);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$status, is_string($body) ? $body : ''];
    }
}
