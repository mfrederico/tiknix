<?php
/**
 * InstagramConnector — Instagram (Meta) OAuth via the "Instagram API with Instagram
 * Login" path (control-plane custody). A Professional (Business/Creator) IG account
 * logs in directly — no Facebook Page required — and grants read access to its media.
 *
 * Token lifecycle (this is the first connector whose tokens EXPIRE):
 *   code -> short-lived token (1h, api.instagram.com) -> long-lived token (60d,
 *   graph.instagram.com) -> refresh (graph.instagram.com/refresh_access_token).
 * The long-lived token + its unix `expires_at` are stored (expires_at in metadata and,
 * when the column exists, on connections.expiresAt). The token is stored ENCRYPTED on
 * the control plane and never written into a builder instance — instances reach the
 * feed only through the MCP broker.
 *
 * Meta app model: ONE tiknix-owned Meta app (conf/instagram.ini [oauth]) that every
 * client authorizes. Own-account/tester works in dev mode with zero App Review; serving
 * arbitrary clients needs Advanced Access on instagram_business_basic + Business
 * Verification. Graph API host + scope names verified against v25.0 (July 2026).
 */

namespace app\services\connectors;

class InstagramConnector extends AbstractConnector {

    private const GRAPH = 'https://graph.instagram.com';

    public function key(): string { return 'instagram'; }

    public function meta(): array {
        return [
            'label'     => 'Instagram',
            'auth_type' => 'oauth',
            'blurb'     => 'Connect an Instagram (Professional) account to pull its reels & photos into a public showcase page.',
            'category'  => 'Social',
            'icon'      => 'instagram',
            'color'     => 'danger',
            'features'  => ['Reels', 'Photos', 'Public page'],
        ];
    }

    /** Read scope for the Instagram-Login path (old short names deprecated Jan 2025). */
    public function defaultScopes(): string {
        return (string)($this->oauth()['scopes'] ?? 'instagram_business_basic');
    }

    public function authorizeUrl(array $ctx): string {
        $o = $this->oauth();
        $q = http_build_query([
            'client_id'     => (string)($o['client_id'] ?? ''),
            'redirect_uri'  => (string)($ctx['redirect_uri'] ?? ''),
            'response_type' => 'code',
            'scope'         => (string)($ctx['scopes'] ?? $this->defaultScopes()),
            'state'         => (string)($ctx['state'] ?? ''),
        ]);
        return 'https://www.instagram.com/oauth/authorize?' . $q;
    }

    public function exchangeCode(array $ctx): array {
        $params = $ctx['params'] ?? [];
        $o      = $this->oauth();
        $code   = (string)($params['code'] ?? '');
        // Instagram sometimes appends "#_" to the returned code — strip it.
        $code = preg_replace('/#_$/', '', $code);
        if ($code === '') {
            if (!empty($params['error'])) {
                throw new \Exception('Instagram authorization was denied (' . (string)$params['error'] . ').');
            }
            throw new \Exception('Missing authorization code.');
        }

        // 1) code -> short-lived token (+ user_id). Form POST to api.instagram.com.
        [$s1, $b1] = $this->http('POST', 'https://api.instagram.com/oauth/access_token', [
            'headers' => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            'body'    => http_build_query([
                'client_id'     => (string)($o['client_id'] ?? ''),
                'client_secret' => (string)($o['client_secret'] ?? ''),
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => (string)($ctx['redirect_uri'] ?? ''),
                'code'          => $code,
            ]),
        ]);
        $j1 = json_decode($b1, true);
        if ($s1 < 200 || $s1 >= 300 || empty($j1['access_token'])) {
            throw new \Exception('Instagram token exchange failed (HTTP ' . $s1 . ').');
        }
        $shortToken = (string)$j1['access_token'];
        $igUserId   = (string)($j1['user_id'] ?? '');

        // 2) short-lived -> long-lived (60 days) on graph.instagram.com.
        [$s2, $b2] = $this->http('GET', self::GRAPH . '/access_token?' . http_build_query([
            'grant_type'    => 'ig_exchange_token',
            'client_secret' => (string)($o['client_secret'] ?? ''),
            'access_token'  => $shortToken,
        ]));
        $j2 = json_decode($b2, true);
        // Fall back to the short-lived token if the exchange is unavailable (still usable ~1h).
        $token     = ($s2 >= 200 && $s2 < 300 && !empty($j2['access_token'])) ? (string)$j2['access_token'] : $shortToken;
        $expiresIn = (int)($j2['expires_in'] ?? 3600);
        $expiresAt = time() + $expiresIn;

        // 3) profile (username, account type). ig user id may already be known from step 1.
        $username = ''; $accountType = '';
        [$s3, $b3] = $this->http('GET', self::GRAPH . '/me?' . http_build_query([
            'fields'       => 'user_id,username,account_type',
            'access_token' => $token,
        ]));
        if ($s3 >= 200 && $s3 < 300) {
            $j3 = json_decode($b3, true) ?: [];
            $username    = (string)($j3['username'] ?? '');
            $accountType = (string)($j3['account_type'] ?? '');
            if ($igUserId === '') $igUserId = (string)($j3['user_id'] ?? '');
        }
        if ($igUserId === '') throw new \Exception('Could not resolve the Instagram user id.');
        $name = $username !== '' ? '@' . $username : $igUserId;

        return [
            'access_token'  => $token,
            'token_type'    => 'Bearer',
            'scopes'        => (string)($this->defaultScopes()),
            'external_eid'  => $igUserId,
            'external_name' => $name,
            'external_url'  => $username !== '' ? 'https://instagram.com/' . $username : '',
            'metadata'      => [
                'ig_user_id'   => $igUserId,
                'username'     => $username,
                'account_type' => $accountType,
                'expires_at'   => $expiresAt,
                'obtained_at'  => time(),
            ],
        ];
    }

    /**
     * Refresh the long-lived token (must be >=24h old and not expired). Returns the new
     * token + unix expiry, or throws if the token is already dead (needs re-auth).
     */
    public function refreshToken($conn, string $token): ?array {
        [$s, $b] = $this->http('GET', self::GRAPH . '/refresh_access_token?' . http_build_query([
            'grant_type'   => 'ig_refresh_token',
            'access_token' => $token,
        ]));
        $j = json_decode($b, true);
        if ($s < 200 || $s >= 300 || empty($j['access_token'])) {
            throw new \Exception('Instagram token refresh failed (HTTP ' . $s . ') — the account may need to reconnect.');
        }
        return ['access_token' => (string)$j['access_token'], 'expires_at' => time() + (int)($j['expires_in'] ?? 5184000)];
    }

    /**
     * Fetch and normalize the account's media (reels + photos). Reads the ig user id
     * from the connection's external_eid.
     */
    public function fetchFeed($conn, string $token, array $opts = []): array {
        $igUserId = (string)($conn->externalEid ?? '');
        if ($igUserId === '') throw new \Exception('Connection has no Instagram user id.');
        $limit  = max(1, min(100, (int)($opts['limit'] ?? 24)));
        $fields = 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count,children{media_type,media_url,thumbnail_url}';
        $query  = ['fields' => $fields, 'limit' => $limit, 'access_token' => $token];
        if (!empty($opts['cursor'])) $query['after'] = (string)$opts['cursor'];

        [$s, $b] = $this->http('GET', self::GRAPH . '/' . rawurlencode($igUserId) . '/media?' . http_build_query($query));
        if ($s === 401 || $s === 403) {
            throw new \Exception('Instagram rejected the token (HTTP ' . $s . ') — reconnect the account.');
        }
        if ($s < 200 || $s >= 300) {
            throw new \Exception('Instagram API error (HTTP ' . $s . ').');
        }
        $j = json_decode($b, true) ?: [];
        $items = [];
        foreach (($j['data'] ?? []) as $m) {
            $items[] = self::normalizeMedia($m);
        }
        return ['items' => $items, 'cursor' => (string)($j['paging']['cursors']['after'] ?? '')];
    }

    /** Map a Graph media object to the connector-neutral feed item shape. */
    private static function normalizeMedia(array $m): array {
        $type    = strtoupper((string)($m['media_type'] ?? ''));
        $product = strtoupper((string)($m['media_product_type'] ?? ''));
        $kind = 'photo';
        if ($product === 'REELS')            $kind = 'reel';
        elseif ($type === 'VIDEO')           $kind = 'video';
        elseif ($type === 'CAROUSEL_ALBUM')  $kind = 'carousel';
        $children = [];
        foreach (($m['children']['data'] ?? []) as $c) {
            $children[] = [
                'kind'          => strtoupper((string)($c['media_type'] ?? '')) === 'VIDEO' ? 'video' : 'photo',
                'media_url'     => (string)($c['media_url'] ?? ''),
                'thumbnail_url' => (string)($c['thumbnail_url'] ?? ''),
            ];
        }
        return [
            'id'             => (string)($m['id'] ?? ''),
            'kind'           => $kind,
            'media_url'      => (string)($m['media_url'] ?? ''),
            'thumbnail_url'  => (string)($m['thumbnail_url'] ?? ''),
            'permalink'      => (string)($m['permalink'] ?? ''),
            'caption'        => (string)($m['caption'] ?? ''),
            'timestamp'      => (string)($m['timestamp'] ?? ''),
            'like_count'     => (int)($m['like_count'] ?? 0),
            'comments_count' => (int)($m['comments_count'] ?? 0),
            'children'       => $children,
        ];
    }

    // --- Broker (read) tools for instances -------------------------------------

    public function brokerTools(): array {
        return [
            [
                'name'        => 'get_profile',
                'description' => 'Fetch the connected Instagram account profile (username, account type).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name'        => 'get_feed',
                'description' => 'List the connected Instagram account\'s recent media (reels + photos).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'limit'       => ['type' => 'integer', 'description' => 'Max items, 1-100 (default 24).'],
                    'cursor'      => ['type' => 'string',  'description' => 'Paging cursor from a prior call.'],
                    'environment' => ['type' => 'string',  'description' => 'Which connection: development|staging|production (default production).'],
                ]],
            ],
        ];
    }

    public function callBrokerTool(string $tool, $conn, string $token, array $args): array {
        switch ($tool) {
            case 'get_profile':
                [$s, $b] = $this->http('GET', self::GRAPH . '/me?' . http_build_query([
                    'fields'       => 'user_id,username,account_type,media_count',
                    'access_token' => $token,
                ]));
                if ($s < 200 || $s >= 300) throw new \Exception('Instagram API error (HTTP ' . $s . ').');
                return json_decode($b, true) ?: [];
            case 'get_feed':
                return $this->fetchFeed($conn, $token, [
                    'limit'  => (int)($args['limit'] ?? 24),
                    'cursor' => (string)($args['cursor'] ?? ''),
                ]);
            default:
                throw new \Exception('Unknown Instagram broker tool: ' . $tool);
        }
    }
}
