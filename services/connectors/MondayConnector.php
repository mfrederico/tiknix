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
     *
     * Moved 2024-10 -> 2025-07 for `Item.description`, which does not exist before
     * 2025-07 and is where these boards keep the actual brief: 28 of 45 items and
     * 53 of 80 subitems carry one, and without it a planner was handed the phase
     * heading alone. Every other query this class issues was re-run against both
     * versions first and behaves identically -- me, boards, items_page, items(ids:)
     * and the updates lookup.
     */
    private const API_VERSION = '2025-07';

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
                # state: active here as well as on the board LIST. Without it a
                # stale link or bookmark to a board somebody archived still returns
                # its items, and they import as live work -- the list refuses to
                # offer that board and this refuses to read it. (# not //: this is
                # GraphQL, and a // here is a syntax error at the far end.)
                boards (ids: $board, state: active) {
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
                            description { blocks (limit: 25) { content } }
                            subitems {
                                id
                                name
                                state
                                board { id }
                                column_values { id text type }
                                description { blocks (limit: 25) { content } }
                            }
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

        $subTitles = $this->subitemColumnTitles($token, $page['items'] ?? []);

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
                'description'=> self::blocksToText($it['description']['blocks'] ?? []),
                // id => filename, read from the blocks before they are flattened to
                // text. A caller that wants the FILES cannot get them back out of a
                // rendered brief, and re-fetching the item to find them again would
                // be a second round trip for something already in hand.
                'assets'     => $this->assetsInBlocks($it['description']['blocks'] ?? []),
                'subitems'   => self::shapeSubitems($it['subitems'] ?? [], $subTitles),
            ];
        }

        return ['items' => $items, 'cursor' => (string) ($page['cursor'] ?? '')];
    }

    /**
     * monday description blocks, flattened to plain text.
     *
     * A block's `content` is a JSON string in Quill delta format --
     * {"deltaFormat":[{"insert":"Define the Phase 1 functionality...","attributes":{...}}]}
     * -- so the readable words are the `insert` values in order. Anything that
     * does not parse is passed through as-is rather than dropped: a brief that
     * arrives slightly malformed is worth more to a planner than no brief, and
     * silently returning '' would look exactly like an item with no description.
     */
    private static function blocksToText(array $blocks): string {
        $out = [];
        foreach ($blocks as $b) {
            $raw = (string) ($b['content'] ?? '');
            if (trim($raw) === '') continue;

            $decoded = json_decode($raw, true);

            // A description is not only prose. monday mixes `file` and `image`
            // blocks in among the text, and reading only deltaFormat dropped them
            // without trace — one item here says "We will be using design provided
            // by Ivan below to recreate this landing page" and the thing below it
            // was a 1.7MB HTML mockup that never reached the brief. A planner told
            // to work from a design it cannot see is worse off than one told there
            // is a design it has not been given.
            if (is_array($decoded) && !isset($decoded['deltaFormat'])) {
                foreach (self::blockAttachments($decoded) as $att) $out[] = $att;
                continue;
            }

            if (!is_array($decoded)) {
                $out[] = trim(strip_tags($raw));
                continue;
            }

            $line = '';
            foreach ((array) $decoded['deltaFormat'] as $op) {
                if (is_string($op['insert'] ?? null)) $line .= $op['insert'];
            }
            $line = trim($line);
            if ($line !== '') $out[] = $line;
        }

        // Blocks are paragraphs; a newline between them keeps a list a list.
        return trim(implode("\n", $out));
    }

    /**
     * A file or image block, described in one line.
     *
     * The assetId is kept in the text on purpose: it is the only handle monday
     * gives for fetching the thing later (assets(ids:) -> public_url), so a brief
     * that names it can be acted on, and one that says "see attached" cannot.
     */
    private static function blockAttachments(array $decoded): array {
        $out = [];

        // A `file` block carries a files[] array; an `image` block is one url.
        $files = $decoded['files'] ?? null;
        if (is_array($files)) {
            foreach ($files as $f) {
                $name = (string) ($f['name'] ?? $f['fileName'] ?? basename((string) ($f['url'] ?? '')));
                $id   = (string) ($f['assetId'] ?? '');
                if ($name === '' && $id === '') continue;
                $out[] = '[attachment: ' . ($name !== '' ? $name : 'file')
                       . ($id !== '' ? ' — monday asset ' . $id : '') . ']';
            }
            return $out;
        }

        if (!empty($decoded['assetId']) || !empty($decoded['url'])) {
            $id   = (string) ($decoded['assetId'] ?? '');
            $name = basename(parse_url((string) ($decoded['url'] ?? ''), PHP_URL_PATH) ?: '') ?: 'image';
            $out[] = '[image: ' . $name . ($id !== '' ? ' — monday asset ' . $id : '') . ']';
        }

        return $out;
    }

    /**
     * Every asset referenced by a description, id => name.
     *
     * Discovery is the awkward part of monday attachments: these files are not on
     * the item's `assets`, not in a file COLUMN, and not on an update. They exist
     * only as assetIds inside description blocks, so the only way to find them is
     * to read the blocks. assets(ids:) then turns an id into a public_url that
     * downloads without a token.
     */
    public function assetsInBlocks(array $blocks): array {
        $ids = [];
        foreach ($blocks as $b) {
            $decoded = json_decode((string) ($b['content'] ?? ''), true);
            if (!is_array($decoded)) continue;

            foreach ((array) ($decoded['files'] ?? []) as $f) {
                if (!empty($f['assetId'])) $ids[(string) $f['assetId']] =
                    (string) ($f['name'] ?? $f['fileName'] ?? basename((string) ($f['url'] ?? '')));
            }
            if (!empty($decoded['assetId'])) {
                $ids[(string) $decoded['assetId']] =
                    basename(parse_url((string) ($decoded['url'] ?? ''), PHP_URL_PATH) ?: '') ?: 'image';
            }
        }
        return $ids;
    }

    /**
     * Asset metadata by id, including the public_url that downloads without auth.
     *
     * public_url is SHORT-LIVED — monday signs it for about an hour — so it is
     * fetched when a download is about to happen rather than stored. A stored one
     * is a link that works in testing and 403s a week later.
     */
    public function assets(string $token, array $assetIds): array {
        $assetIds = array_values(array_filter(array_map('strval', $assetIds), fn($s) => $s !== ''));
        if (!$assetIds) return [];

        $out = [];
        foreach (array_chunk($assetIds, 100) as $chunk) {
            $data = $this->query($token, '
                query ($ids: [ID!]!) {
                    assets (ids: $ids) {
                        id name file_extension file_size created_at public_url
                        uploaded_by { name }
                    }
                }', ['ids' => $chunk]);

            foreach (($data['assets'] ?? []) as $as) {
                $out[(string) $as['id']] = [
                    'id'         => (string) $as['id'],
                    'name'       => (string) ($as['name'] ?? ''),
                    'extension'  => ltrim((string) ($as['file_extension'] ?? ''), '.'),
                    'size'       => (int) ($as['file_size'] ?? 0),
                    'created_at' => (string) ($as['created_at'] ?? ''),
                    'public_url' => (string) ($as['public_url'] ?? ''),
                    'uploaded_by'=> (string) ($as['uploaded_by']['name'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * Subitems, flattened to what an importer needs.
     *
     * Status comes from the column TYPE alone here, with no title to go on: a
     * subitem's columns belong to the hidden subitem board, and fetching that
     * board's titles would repeat the lookup for every subitem on the page. One
     * status column is unambiguous and is used; more than one is not, and returns
     * blank rather than a guess — the same rule the parent items follow, for the
     * same reason.
     */
    private static function shapeSubitems(array $subs, array $subTitles = []): array {
        $out = [];
        foreach ($subs as $sub) {
            // Title-keyed, exactly like a parent item's — see items(). A subitem
            // board carries Status AND Priority, both of type `status`, so without
            // the titles the only honest answer is "cannot tell", and a subitem
            // marked Done would import as open work.
            $statuses = [];
            foreach (($sub['column_values'] ?? []) as $cv) {
                if ((string) ($cv['type'] ?? '') !== 'status') continue;
                $id  = (string) $cv['id'];
                $key = ($subTitles[$id] ?? '') !== '' ? $subTitles[$id] : $id;
                $statuses[$key] = trim((string) ($cv['text'] ?? ''));
            }

            $out[] = [
                'id'          => (string) ($sub['id'] ?? ''),
                'name'        => (string) ($sub['name'] ?? ''),
                'state'       => (string) ($sub['state'] ?? ''),
                'statuses'    => $statuses,
                'description' => self::blocksToText($sub['description']['blocks'] ?? []),
            ];
        }
        return $out;
    }

    /**
     * Column titles for the SUBITEM board, fetched once per page.
     *
     * Every subitem of a board lives on the same hidden "Subitems of X" board, so
     * this is one extra query rather than the per-subitem `board { columns }`
     * traversal — which on a page carrying 86 subitems would repeat the same
     * lookup 86 times for one answer.
     */
    private function subitemColumnTitles(string $token, array $items): array {
        $boardId = '';
        foreach ($items as $it) {
            foreach (($it['subitems'] ?? []) as $sub) {
                $boardId = (string) ($sub['board']['id'] ?? '');
                if ($boardId !== '') break 2;
            }
        }
        if ($boardId === '') return [];

        $data = $this->query($token, '
            query ($ids: [ID!]) { boards (ids: $ids, state: active) { columns { id title type } } }',
            ['ids' => [$boardId]]);

        $titles = [];
        foreach (($data['boards'][0]['columns'] ?? []) as $c) {
            $titles[(string) ($c['id'] ?? '')] = trim((string) ($c['title'] ?? ''));
        }
        return $titles;
    }

    /**
     * Many items at once by id, in the same shape items() returns.
     *
     * For re-checking and re-pulling work already imported: the refresh pass wants
     * `state` and `statuses`, a re-import wants `name` and `fields`, and both are
     * in the one reply — so this fetches the whole item rather than making the two
     * callers issue two different queries against the same ids. One call rather than one per task,
     * because monday charges a complexity budget per query and a board's worth of
     * single lookups exhausts it — "Complexity budget exhausted, reset in 45
     * seconds" is a real reply and a slow way to learn this.
     *
     * An id monday does not return is ABSENT from the result rather than present
     * and empty. The caller has to tell "deleted from monday" apart from "still
     * there, no status set", and a zero value cannot carry that difference.
     */
    public function itemsById(string $token, array $itemIds): array {
        $itemIds = array_values(array_filter(array_map('strval', $itemIds), fn($s) => $s !== ''));
        if (!$itemIds) return [];

        $out = [];
        // limit is NOT optional. items(ids:) defaults to 25 and silently returns the
        // first 25 of however many you asked for -- no error, no warning, just a
        // shorter list. A refresh over 28 tasks got 25 back and reported the other
        // three as "no longer visible in monday", which is a live task told it was
        // deleted. Chunked to match the limit so the two can never disagree.
        //
        // 25 per request, not 100. Pulling description blocks made this query far
        // heavier -- 28 items measured at 6.8s -- so a chunk of 100 would sit right
        // on the 25s timeout in query(), and the first symptom of crossing it is
        // "monday.com sent no usable answer (HTTP 0)" partway through a sync.
        foreach (array_chunk($itemIds, 25) as $chunk) {
            $data = $this->query($token, '
                query ($ids: [ID!], $limit: Int!) {
                    items (ids: $ids, limit: $limit) {
                        id
                        name
                        state
                        url
                        group { id title }
                        board { id name columns { id title type } }
                        column_values { id text type }
                        # Not optional. The sync rebuilds every brief from this
                        # reply, so a field missing HERE is a field DELETED from the
                        # task: the first sync stripped every description the import
                        # had brought in, and reported that as a change it had made.
                        # (No apostrophes in here -- this block lives inside a
                        # single-quoted PHP string and one would close it.)
                        description { blocks (limit: 25) { content } }
                        # A subitem knows its parent; a top-level item returns null.
                        # The sync needs it to rebuild a subitem brief the way the
                        # import wrote it -- "Part of: 3_Product Data" rather than
                        # "Project: Subitems of Click Simple", which names the hidden
                        # board somebody never chose and loses the phase.
                        parent_item { id name }
                    }
                }', ['ids' => $chunk, 'limit' => count($chunk)]);

            foreach (($data['items'] ?? []) as $it) {
                $statusCols = [];
                foreach (($it['board']['columns'] ?? []) as $c) {
                    if ((string) ($c['type'] ?? '') === 'status') {
                        $statusCols[(string) $c['id']] = trim((string) ($c['title'] ?? ''));
                    }
                }
                // Seeded from the BOARD's columns so a blank status stays visible as
                // a blank rather than vanishing — see items() for why that matters.
                $titles = [];
                foreach (($it['board']['columns'] ?? []) as $c) {
                    $titles[(string) ($c['id'] ?? '')] = trim((string) ($c['title'] ?? ''));
                }

                $statuses = array_fill_keys(array_values(array_filter($statusCols)), '');
                $fields   = [];
                foreach (($it['column_values'] ?? []) as $cv) {
                    $id   = (string) $cv['id'];
                    $key  = ($titles[$id] ?? '') !== '' ? $titles[$id] : $id;
                    $text = trim((string) ($cv['text'] ?? ''));
                    if (isset($statusCols[$id])) $statuses[$key] = $text;
                    if ($text !== '') $fields[$key] = $text;
                }

                $out[(string) $it['id']] = [
                    'id'       => (string) $it['id'],
                    'name'     => (string) ($it['name'] ?? ''),
                    'state'    => (string) ($it['state'] ?? ''),
                    'board'    => (string) ($it['board']['name'] ?? ''),
                    'board_id' => (string) ($it['board']['id'] ?? ''),
                    'group'    => (string) ($it['group']['title'] ?? ''),
                    'url'      => (string) ($it['url'] ?? ''),
                    'fields'      => $fields,
                    'statuses'    => $statuses,
                    'description' => self::blocksToText($it['description']['blocks'] ?? []),
                    'assets'      => $this->assetsInBlocks($it['description']['blocks'] ?? []),
                    'parent_name' => (string) ($it['parent_item']['name'] ?? ''),
                ];
            }
        }

        return $out;
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

        $opts = [
            'headers' => [
                'Content-Type: application/json',
                'Authorization: ' . $token,
                'API-Version: ' . self::API_VERSION,
            ],
            'body'    => $body,
            'timeout' => 25,
        ];

        // Retried ONCE, and only for a transport failure — never for a GraphQL
        // error, which would repeat a bad query and charge for it twice.
        //
        // Observed against a real account: the first request of a pair succeeds in
        // about 6s and the one straight after it hangs for the full timeout with
        // zero bytes received. That is throttling arriving as silence rather than
        // as 429, so backing off briefly is the answer and asking for less is not.
        [$status, $raw, $transportError] = $this->http('POST', self::ENDPOINT, $opts);

        if ($transportError !== '' && $raw === '') {
            usleep(2500000);
            [$status, $raw, $retryError] = $this->http('POST', self::ENDPOINT, $opts);
            // Keep the FIRST reason if the retry also fails: it is the one that
            // describes the condition, and "timed out, then timed out" says less
            // than "timed out" with the attempt count implied.
            if ($raw === '') $transportError .= ' (retried once' . ($retryError !== '' ? '; same' : '') . ')';
            else             $transportError = '';
        }

        $json = json_decode($raw ?: '', true);
        if (!is_array($json)) {
            // Say WHICH failure. "HTTP 0" alone covers a timeout, a refused
            // connection, a DNS miss and a TLS problem, and they want different
            // things done about them — a timeout means ask for less at a time.
            throw new \Exception('monday.com sent no usable answer (HTTP ' . $status . ')'
                . ($transportError !== '' ? ': ' . $transportError : '') . '.');
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
