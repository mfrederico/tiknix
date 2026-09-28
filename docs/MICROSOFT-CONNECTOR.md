# Microsoft 365 / Outlook connector

`services/connectors/MicrosoftConnector.php` (`app\services\connectors\MicrosoftConnector`,
`key() === 'microsoft'`), registered like every other provider through
`ConnectorRegistry` and reached the same way (`/connections`, the MCP broker). It
is pinned here — [MicrosoftConnectorCharacterizationTest](tests/unit/MicrosoftConnectorCharacterizationTest.php)
covers `authorizeUrl()`, `brokerTools()` and `callBrokerTool()` — so the connector
can move into core beside Shopify, Stripe and QuickBooks with a known interface.
**The connector class itself stays in `services/connectors/` for now**: a concept
(plugin) cannot carry connector classes, so moving it is a core change, not a
concept change. See "Moving into core" below.

## Auth: always the customer's own Azure app

`requiresOwnApp()` returns `true`. A connected mailbox is reached **only**
through the customer's own Azure AD app registration — never a shared tiknix
app. Sending cold outreach email as someone's real mailbox is their credential,
their consent grant, their callback on their own domain; the same reasoning
`ShopifyConnector` uses for a merchant's own custom app. There is no
`conf/microsoft.ini [oauth]` fallback client id/secret for this reason:
`AbstractConnector::appFor()` throws rather than inventing a shared identity
nobody asked for when no custom app is present.

Concretely, in `controls/Connections.php`:

- `GET /connections?id=<instance>` — the connection hub; the "connect
  Microsoft" form collects the Azure app's **client id** (`app_key`) and
  **client secret** (`app_secret`), POSTed (not GET — a secret does not belong
  in a query string) to `/connections/connect/microsoft?id=<instance>`.
- The client id/secret are held in `$_SESSION['oauth_custom_app']`, keyed to
  the signed OAuth state's hash, until the callback — never in the state
  itself, which rides through the browser and the provider.
- `GET /connections/callback/microsoft` is the registered Azure **redirect
  URI** (`https://<host>/connections/callback/microsoft` —
  `Connections::connectorRedirectUri()`); it resolves the stashed app
  credentials, calls `MicrosoftConnector::exchangeCode()`, and stores the
  connection encrypted via `ConnectionStore`.
- Because `requiresOwnApp()` is true, the broker **handoff** route
  (`/connections/handoff/microsoft`, used by connectors that borrow the
  platform's shared app) explicitly refuses this connector and points the user
  back at the project's own Connections page instead.

The customer must, at `portal.azure.com` (Entra ID → App registrations):
register an app, add this instance's `/connections/callback/microsoft` as a
**Web** redirect URI, and grant delegated Graph permissions `Mail.Send`,
`Mail.ReadWrite`, `User.Read`, `offline_access` (plus `Calendars.ReadWrite` for
the calendar tools).

`authorizeUrl()` builds:

```
https://login.microsoftonline.com/common/oauth2/v2.0/authorize
    ?client_id=<app client id>
    &response_type=code
    &response_mode=query
    &scope=<space-separated scopes>
    &redirect_uri=<the /connections/callback/microsoft URL>
    &state=<signed OAuthStateService state>
```

The `common` tenant endpoint (not a specific tenant id) is used deliberately,
so both work/school (Microsoft 365) and personal (outlook.com/hotmail)
mailboxes can connect — Graph's `Mail.Send`/`Mail.ReadWrite` scopes behave the
same against either.

`exchangeCode()` trades the callback `code` for a token at
`https://login.microsoftonline.com/common/oauth2/v2.0/token`, then calls
`GET /me` on Graph to fetch the mailbox address itself (`mail` or
`userPrincipalName`) rather than trusting anything the client supplied — the
same reasoning `QuickBooksConnector` uses for the company name. That address
becomes `external_eid`; a mailbox address is never a RedBean `_id`.

## Scopes and refresh

`defaultScopes()`:

```
offline_access User.Read Mail.Send Mail.ReadWrite Calendars.ReadWrite
```

(overridable per-connection via `conf/microsoft.ini [oauth] scopes`, or a
per-connect-attempt `app_scopes` value — `AbstractConnector::scopesFor()`
accepts either comma- or space-separated input and rejoins it with Graph's own
separator, a single space). `offline_access` is what makes a refresh token
come back at all.

**Access tokens expire in ~1 hour** (like QuickBooks; unlike Shopify/Stripe,
which don't expire). `refreshToken($conn, $token)`:

1. Reads the stored refresh token via `ConnectionStore::ownSecret($conn,
   'refreshToken')` — throws if absent ("reconnect it").
2. Reads the stored Azure app credentials via `ConnectionStore::ownApp($conn)`
   — throws if absent, because a refresh **authenticates as the app that
   issued the token**, so it needs the same client id/secret the original
   connect used, not a shared one.
3. POSTs `grant_type=refresh_token` to the same token endpoint.

The MCP broker (`controls/Mcp.php`, `handleToolsCall`) calls `refreshToken()`
proactively — before every tool call, once `conn.expiresAt` is within 60
seconds — and persists the new token via `ConnectionStore::refreshStored()`. A
refresh failure is raised loudly as "connection could not be refreshed …
Reconnect it", never silently retried with the stale token.

## Broker tools

Each tool is namespaced `microsoft:<name>` by the MCP gateway
(`controls/Mcp.php`). All hit `https://graph.microsoft.com/v1.0`.

| Tool | Arguments (`*` = required) | Graph request |
|---|---|---|
| `send_mail` | `to`\*, `subject`\*, `body_html`\*, `to_name`, `cc` (comma-separated), `in_reply_to_message` | No reply: `POST /me/messages` (create draft) then `POST /me/messages/{id}/send`. With `in_reply_to_message`: `POST /me/messages/{id}/createReply` (draft threaded to that message) then `POST /me/messages/{replyId}/send`. Always create-then-send, never the single-shot `/me/sendMail` — that endpoint returns 202 with no body, so there would be no `id`/`conversationId` for the reply-polling pipeline to match an inbound reply back to this thread. |
| `list_messages` | `since` (ISO-8601), `top` (default 25, max 100) | `GET /me/mailFolders/inbox/messages?$top=&$orderby=receivedDateTime desc&$select=id,conversationId,subject,from,toRecipients,receivedDateTime,body[&$filter=receivedDateTime ge {since}]` |
| `get_message` | `id`\* | `GET /me/messages/{id}` |
| `list_events` | `start`\*, `end`\* (both ISO-8601) | `GET /me/calendarView?startDateTime=&endDateTime=&$orderby=start/dateTime&$top=100&$select=id,subject,start,end,organizer,attendees,isOnlineMeeting,onlineMeeting,bodyPreview,isCancelled`. Requires `Calendars.ReadWrite` — a connection made before that scope existed must be reconnected. |
| `create_event` | `subject`\*, `start`\*, `end`\*, `body_html`, `attendee_email`, `attendee_name` | `POST /me/events`. Requires `Calendars.ReadWrite`. |
| `update_event` | `id`\*, `subject`, `start`, `end`, `body_html` (a partial update — only given fields change; at least one is required) | `PATCH /me/events/{id}`. Requires `Calendars.ReadWrite`. |

An unknown tool name throws `Unknown microsoft tool '<name>'.` — the same
"fail loudly" shape every other connector's `callBrokerTool()` uses.

## How plugins (instances) reach it

An instance never holds the Microsoft access token itself. It calls its own
MCP endpoint with its broker key (`Authorization: Bearer <broker key>`,
`BrokerService::keyFromRequest()`), naming the tool as `microsoft:<name>`
(`tools/list` / `tools/call`, `controls/Mcp.php`). `handleToolsCall()` sees the
`microsoft:` prefix, recognizes it via `ConnectorRegistry::has()`, and routes to
`Mcp::brokerToolCall()`, which requires a broker-class key and then:

1. Resolves the instance's connection via `ConnectionStore::forInstall($instanceId, 'microsoft')`.
2. Decrypts the token (`$conn->plainToken`), refreshing first if it's near
   expiry (see above).
3. Calls `$connector->callBrokerTool($toolName, $conn, $token, $arguments)`.
4. Zeroes the token (`sodium_memzero`) and returns the JSON result — the token
   itself is never in the response.

So the only things a plugin/instance ever needs are **`ConnectionStore` (used
control-plane-side only, never by the instance directly) and the six tool
names above** — `Mail`/`Calendar` UI code (`controls/Calendar.php`,
`controls/Prospects.php`) calls `callBrokerTool()` the same way, in-process,
when it already holds a resolved `$conn`/`$token` pair.

## Moving into core

Everything the connector itself needs already lives in core-reachable code
(`AbstractConnector`, `ConnectorRegistry`, `ConnectionStore`, `Mcp.php`) — the
connector class has no concept-specific dependency today. What moving
`services/connectors/MicrosoftConnector.php` into core (next to
`ShopifyConnector`, `StripeGateway`/`StripeConnector`, `QuickBooksConnector`)
actually requires:

- **Nothing code-wise changes in the connector itself** — it already only
  imports `app\ConnectionStore` and extends `app\services\connectors\AbstractConnector`,
  both core classes. This test file pins that surface so a move can be
  verified mechanically (same `key()`, same tool names/schemas, same request
  shapes) rather than by re-reading the diff.
- **`conf/microsoft.ini`** does not need to exist for this connector (no
  server-wide app is ever used — `requiresOwnApp()` is true), so there is no
  config key to migrate.
- **Confirm `ConnectorRegistry`** picks the class up the same way regardless
  of which directory it lives under `services/` PSR-4 root — it currently
  does (`services/connectors/*.php`, `app\services\connectors\` PSR-4), so a
  literal file move needs no registry change.
- **The one thing to verify by hand, not by this test suite**: that no
  concept currently patches, wraps, or duplicates a `services/connectors/Microsoft*`
  file — `concepts.lock`'s per-concept file hash is the way to check that
  before the move, since a concept is never supposed to carry a connector
  class in the first place.
