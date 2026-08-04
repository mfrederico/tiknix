<?php
/**
 * monday.com — boards and items, over GraphQL.
 *
 * Registered by existing on disk: ConnectorRegistry scans this directory, so this
 * appears on the Connections hub with the paste-a-key flow every api_key
 * connector already has.
 *
 * Auth is a personal API token from monday (Admin → API, or the developer menu).
 * Like Telegram and unlike Shopify or Stripe, there is no OAuth app for the
 * operator of this install to register first — the token belongs to whoever
 * pasted it. So isConfigured() is true with no conf/monday.ini.
 *
 * Everything is one POST to one endpoint; GraphQL puts the method in the body.
 * Two things about monday that shape the code below:
 *
 *   - It answers HTTP 200 with an "errors" array as readily as it answers 4xx,
 *     so the BODY decides success, never the status.
 *   - Queries are budgeted by complexity rather than by request count, and the
 *     budget is returned in the response. Asking for fewer fields is what makes a
 *     query cheap, which is why the item query below names its fields instead of
 *     pulling whole objects.
 *
 * API version is PINNED. monday ships breaking changes behind a version header
 * and silently serves the account's default when it is absent — which means the
 * same code could work today and return a different shape next quarter with
 * nothing in this repo having changed.
 */

namespace app\services\connectors;

class MondayConnector extends AbstractConnector {

    private const ENDPOINT = 'https://api.monday.com/v2';

    /**
     * The version this connector's queries are written against.
     *
     * `boards.items` became `items_page` in 2023-10; naming a version is what
     * stops that class of change arriving unannounced.
     */
    private const API_VERSION = '2024-10';

    public function key(): string {
        return 'monday';
    }

    public function meta(): array {
        return [
            'label'     => 'monday.com',
            'auth_type' => 'api_key',
            'blurb'     => 'Connect a monday.com account to pull board items in as work you '
                         . 'can break down and build from.',
            'category'  => 'Project',
            'icon'      => 'kanban',
            'color'     => 'warning',
            'features'  => ['Boards', 'Items', 'Break down into tasks'],
        ];
    }

    /** The token is the whole credential — nothing for this install to register. */
    public function isConfigured(): bool {
        return true;
    }

    public function authorizeUrl(array $ctx): string {
        throw new \Exception('monday.com connects with an API token, not OAuth. '
                           . 'Copy one from monday: avatar menu → Developers → My access tokens.');
    }

    public function exchangeCode(array $ctx): array {
        throw new \Exception('monday.com connects with an API token, not OAuth.');
    }

    // ---- connecting ------------------------------------------------------------------

    /**
     * Validate a token by asking monday who it belongs to.
     *
     * Only checks that the token is non-empty and has no whitespace before
     * spending the round trip. Deliberately not a format check: monday has issued
     * more than one token shape over the years, and a regex that rejects a valid
     * token is a worse failure than one extra API call — the person would be
     * looking at a token that works, being told it does not.
     */
    public function validateApiKey(string $key): array {
        $token = trim($key);
        if ($token === '') {
            throw new \Exception('Paste your monday.com API token.');
        }
        if (preg_match('/\s/', $token)) {
            throw new \Exception('That token contains a space — it may have been copied with '
                               . 'surrounding text. Copy just the token.');
        }

        $me = $this->query($token, 'query { me { id name email account { id name slug } } }');
        $who = $me['me'] ?? [];
        if (empty($who['id'])) {
            throw new \Exception('monday.com accepted the request but did not say who the token '
                               . 'belongs to. Check the token has not been revoked.');
        }

        $account = $who['account'] ?? [];
        $slug    = (string) ($account['slug'] ?? '');

        return [
            'access_token'  => $token,
            'token_type'    => 'api_token',
            'scopes'        => '',
            // What the far end calls this thing: the monday ACCOUNT, not the user.
            // A token is re-issued per person, but the account is what the boards
            // belong to and what stays the same across a token rotation.
            'external_eid'  => (string) ($account['id'] ?? $who['id']),
            'external_name' => (string) ($account['name'] ?? $who['name'] ?? 'monday.com'),
            'external_url'  => $slug !== '' ? 'https://' . $slug . '.monday.com' : 'https://monday.com',
        ];
    }

    // ---- reading ---------------------------------------------------------------------

    /**
     * Boards this token can see, most recently used first.
     *
     * Active boards only: monday keeps archived and deleted ones queryable, and
     * offering somebody a board they archived last year as a place to pull work
     * from is offering a mistake.
     */
    public function boards(string $token, int $limit = 100): array {
        $data = $this->query($token, '
            query ($limit: Int!) {
                boards (limit: $limit, order_by: used_at, state: active) {
                    id
                    name
                    description
                    type
                    items_count
                    workspace { id name }
                }
            }', ['limit' => $limit]);

        $out = [];
        foreach (($data['boards'] ?? []) as $b) {
            // Skip subitem boards. monday exposes the subitems of every board as a
            // board in its own right ("Subitems of Click Simple"), which on a real
            // account is half the list and none of it is somewhere you would import
            // work FROM. Filtered on type rather than the name, because the name is
            // just a default a person can change.
            if ((string) ($b['type'] ?? 'board') === 'sub_items_board') continue;

            $out[] = [
                'id'          => (string) ($b['id'] ?? ''),
                'name'        => (string) ($b['name'] ?? 'Untitled board'),
                'description' => (string) ($b['description'] ?? ''),
                'items_count' => (int) ($b['items_count'] ?? 0),
                'workspace'   => (string) ($b['workspace']['name'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Items on one board — the things a person would pick from to build.
     *
     * items_page, not items: monday moved to a cursor-paged shape and the old
     * field is gone at this API version. The cursor comes back so a caller can ask
     * for the next page without re-reading what it already has.
     *
     * @return array{items: array, cursor: string}
     */
    public function items(string $token, string $boardId, int $limit = 50, string $cursor = ''): array {
        // columns {} comes back alongside the items so values can be shown under the
        // titles a person recognises. Without it every field is an opaque generated
        // id — a real board returns color_mm50ntd4 = "Done", which tells a reader
        // nothing and tells a planner less.
        $q = '
            query ($board: [ID!], $limit: Int!, $cursor: String) {
                boards (ids: $board) {
                    columns { id title type }
                    items_page (limit: $limit, cursor: $cursor) {
                        cursor
                        items {
                            id
                            name
                            state
                            updated_at
                            url
                            group { id title }
                            column_values { id text type }
                        }
                    }
                }
            }';

        $data = $this->query($token, $q, [
            'board'  => [$boardId],
            'limit'  => $limit,
            'cursor' => $cursor !== '' ? $cursor : null,
        ]);

        $board = $data['boards'][0] ?? [];
        $page  = $board['items_page'] ?? [];
        $items = [];

        // column id -> human title, e.g. color_mm50ntd4 -> "Status".
        $titles = [];
        // The status-TYPE columns, id => title. A board has more than one: the
        // Manufacturing Transfer board carries `Status` and `Priority`, both type
        // `status`, so "the status column" cannot be found by type alone — and it
        // cannot be found by the title "Status" either, because a board is free to
        // call it State or Progress. Both facts are kept and the caller decides.
        $statusCols = [];
        foreach (($board['columns'] ?? []) as $c) {
            $id = (string) ($c['id'] ?? '');
            $titles[$id] = trim((string) ($c['title'] ?? ''));
            if ((string) ($c['type'] ?? '') === 'status') $statusCols[$id] = $titles[$id];
        }

        foreach (($page['items'] ?? []) as $it) {
            // Keyed by TITLE, falling back to the id when a column has no title.
            // On a real board these are status, priority, dates and people rather
            // than prose — there is often no description column at all, so the item
            // name plus its group is the substance and these are the context.
            // EVERY status column the board has, including the ones this item left
            // blank. `fields` drops empties because a blank is not context worth
            // showing, but dropping them here changed what the set MEANS: an item
            // with no Status but a Priority of "High" arrived looking like it had
            // exactly one status column, and "High" was read as the state of the
            // work. The set has to describe the BOARD, not this row's fill-in.
            $statuses = array_fill_keys(array_values(array_filter($statusCols)), '');

            $fields = [];
            foreach (($it['column_values'] ?? []) as $cv) {
                $id   = (string) $cv['id'];
                $key  = ($titles[$id] ?? '') !== '' ? $titles[$id] : $id;
                $text = trim((string) ($cv['text'] ?? ''));

                // Carried separately with its column title, because `fields` is
                // title-keyed and a title is whatever somebody typed. The type is
                // the only part monday guarantees.
                if (isset($statusCols[$id])) $statuses[$key] = $text;

                if ($text === '') continue;
                $fields[$key] = $text;
            }

            $items[] = [
                'id'         => (string) ($it['id'] ?? ''),
                'name'       => (string) ($it['name'] ?? ''),
                'state'      => (string) ($it['state'] ?? ''),
                'group'      => (string) ($it['group']['title'] ?? ''),
                'url'        => (string) ($it['url'] ?? ''),
                'updated_at' => (string) ($it['updated_at'] ?? ''),
                'fields'     => $fields,
                'statuses'   => $statuses,
            ];
        }

        return ['items' => $items, 'cursor' => (string) ($page['cursor'] ?? '')];
    }

    /** One item in full, for the moment somebody picks it to work on. */
    public function item(string $token, string $itemId): ?array {
        $data = $this->query($token, '
            query ($ids: [ID!]) {
                items (ids: $ids) {
                    id
                    name
                    state
                    url
                    board { id name columns { id title type } }
                    group { id title }
                    column_values { id text type }
                    updates (limit: 20) { id body created_at creator { name } }
                }
            }', ['ids' => [$itemId]]);

        $it = $data['items'][0] ?? null;
        if (!$it) return null;

        // Same title mapping as items() — see the note there.
        $titles = [];
        foreach (($it['board']['columns'] ?? []) as $c) {
            $titles[(string) ($c['id'] ?? '')] = trim((string) ($c['title'] ?? ''));
        }

        $fields = [];
        foreach (($it['column_values'] ?? []) as $cv) {
            $text = trim((string) ($cv['text'] ?? ''));
            if ($text === '') continue;
            $id  = (string) $cv['id'];
            $key = ($titles[$id] ?? '') !== '' ? $titles[$id] : $id;
            $fields[$key] = $text;
        }

        $updates = [];
        foreach (($it['updates'] ?? []) as $u) {
            $updates[] = [
                'body'    => trim(strip_tags((string) ($u['body'] ?? ''))),
                'author'  => (string) ($u['creator']['name'] ?? ''),
                'created' => (string) ($u['created_at'] ?? ''),
            ];
        }

        return [
            'id'      => (string) ($it['id'] ?? ''),
            'name'    => (string) ($it['name'] ?? ''),
            'state'   => (string) ($it['state'] ?? ''),
            'url'     => (string) ($it['url'] ?? ''),
            'board'   => (string) ($it['board']['name'] ?? ''),
            'group'   => (string) ($it['group']['title'] ?? ''),
            'fields'  => $fields,
            'updates' => $updates,
        ];
    }

    // ---- writing back ----------------------------------------------------------------

    /**
     * Post an update (what monday calls a comment) on one item.
     *
     * This is the non-destructive half of posting back: it lands in the item's
     * activity feed, needs nothing configured on the board, and cannot be confused
     * with the board's own data.
     *
     * @return string the new update's id
     */
    public function createUpdate(string $token, string $itemId, string $body): string {
        $data = $this->query($token, '
            mutation ($item: ID!, $body: String!) {
                create_update (item_id: $item, body: $body) { id }
            }', ['item' => $itemId, 'body' => $body]);

        return (string) ($data['create_update']['id'] ?? '');
    }

    /**
     * Create a subitem under an item.
     *
     * monday creates the board's subitems column the first time one is added, so
     * this works on a board that has never used them — but it DOES change the
     * board's structure, which is why it is worth knowing it is happening. The
     * update above is the safe half; this is the one that writes.
     *
     * @return string the new subitem's id
     */
    public function createSubitem(string $token, string $parentItemId, string $name): string {
        $data = $this->query($token, '
            mutation ($parent: ID!, $name: String!) {
                create_subitem (parent_item_id: $parent, item_name: $name) { id }
            }', ['parent' => $parentItemId, 'name' => $name]);

        return (string) ($data['create_subitem']['id'] ?? '');
    }

    /**
     * Post a completed decomposition back to the item it came from.
     *
     * Subitems for structure, then one update summarising — the "both" shape. The
     * update is posted LAST and reports how many subitems actually landed, so a
     * partial failure is visible in monday rather than only in our logs.
     *
     * A subitem that fails does not abort the rest: the point of this call is to
     * get as much of the finished work onto the board as possible, and a summary
     * that says "7 of 9" is more use than nothing.
     *
     * @param  array $tasks  ['title' => string, 'completed_at' => string]
     * @return array{subitems: int, failed: int, update_id: string}
     */
    public function postCompletion(string $token, string $itemId, array $tasks, string $summary = ''): array {
        $made = 0; $failed = 0; $errors = [];

        foreach ($tasks as $t) {
            $title = trim((string) ($t['title'] ?? ''));
            if ($title === '') continue;
            try {
                if ($this->createSubitem($token, $itemId, $title) !== '') $made++;
                else { $failed++; $errors[] = $title . ': no id returned'; }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = $title . ': ' . $e->getMessage();
            }
        }

        $lines = [];
        $lines[] = $summary !== '' ? $summary : 'Completed in tiknix.';
        $lines[] = '';
        foreach ($tasks as $t) {
            $when = trim((string) ($t['completed_at'] ?? ''));
            $lines[] = '✓ ' . (string) ($t['title'] ?? '') . ($when !== '' ? '  (' . $when . ')' : '');
        }
        if ($failed > 0) {
            $lines[] = '';
            $lines[] = $failed . ' subitem(s) could not be created: ' . implode('; ', array_slice($errors, 0, 3));
        }

        $updateId = '';
        try {
            $updateId = $this->createUpdate($token, $itemId, implode("\n", $lines));
        } catch (\Throwable $e) {
            // The subitems are already on the board; losing the summary should not
            // present as "post-back failed" when most of it succeeded.
            \Flight::get('log')?->error('monday: subitems posted but the summary update failed', [
                'item' => $itemId, 'err' => $e->getMessage(),
            ]);
        }

        return ['subitems' => $made, 'failed' => $failed, 'update_id' => $updateId];
    }

    // ---- transport -------------------------------------------------------------------

    /**
     * One GraphQL call.
     *
     * monday answers 200 with an "errors" array as happily as it answers 401, so
     * the body decides. Its own message is passed through rather than replaced:
     * "Not Authenticated", "Complexity budget exhausted, reset in 45 seconds" and
     * "Board not found" all need different actions from whoever is reading, and a
     * friendlier generic message would lose that.
     */
    private function query(string $token, string $query, array $variables = []): array {
        $body = json_encode(array_filter([
            'query'     => $query,
            'variables' => $variables ?: null,
        ], fn($v) => $v !== null));

        [$status, $raw] = $this->http('POST', self::ENDPOINT, [
            'headers' => [
                'Content-Type: application/json',
                'Authorization: ' . $token,
                'API-Version: ' . self::API_VERSION,
            ],
            'body'    => $body,
            'timeout' => 25,
        ]);

        $json = json_decode($raw ?: '', true);
        if (!is_array($json)) {
            throw new \Exception('monday.com sent no usable answer (HTTP ' . $status . ').');
        }

        if (!empty($json['errors'])) {
            $msgs = [];
            foreach ($json['errors'] as $e) {
                $msgs[] = is_array($e) ? (string) ($e['message'] ?? 'unknown') : (string) $e;
            }
            throw new \Exception('monday.com: ' . implode('; ', array_unique($msgs)));
        }

        // A separate shape from GraphQL "errors" — monday reports auth and rate
        // problems as a top-level error_message instead.
        if (!empty($json['error_message'])) {
            throw new \Exception('monday.com: ' . $json['error_message']);
        }

        if ($status >= 400) {
            throw new \Exception('monday.com returned HTTP ' . $status . '.');
        }

        return $json['data'] ?? [];
    }
}
