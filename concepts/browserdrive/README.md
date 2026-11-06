# Browser drive

Playwright-style browser automation for a tiknix app, played in a **person's open page** instead of a browser on the server.

An agent, a script or the MCP tool opens a *session* on a page of the site and sends *steps*: go to a page, click, type,
press keys, drag, wait for something, read text, run JavaScript, take a screenshot. The admin whose API key opened the session
keeps the **runner page** (`/browserdrive`) open in a browser tab. It claims the session, shows the page in a frame of the same site, plays each step
with the pointer, mouse and keyboard events a person's input fires, and sends back the result, with the page's console output.

## Set up

1. Enable the plugin (`php scripts/clitool.php --concept-enable=browserdrive`); its seed sets the permissions.
2. Open `/browserdrive` as an admin and list the **sandbox pages** sessions may open (`/demo`, `/demo/*`). Driven pages act
   as you, signed in, so list only pages and data that are safe to change.
3. Leave `/browserdrive` open. It works in a background tab: a shim keeps animation frames coming so canvases still run.
4. Give the agent or script **your own** API key (`/apikeys`). A session plays only in the tab of the admin whose key opened it, and only that key can send it steps.
5. JavaScript steps (`evaluate`, `waitFor` with `js`) are off. Script reaches every page of the site, not only the sandbox ones, so switch it on at `/browserdrive` only if you need it.

## Drive it

```js
import { connect } from './browserdrive-client.mjs';   // curl -o browserdrive-client.mjs https://your.site/browserdrive/client
const page = await connect({ base: 'https://your.site', key: process.env.BROWSERDRIVE_KEY, path: '/demo' });
await page.click('text=Add rack');
await page.fill('#label', 'Aisle 4');
await page.press('Enter');
await page.waitForText('Saved');
console.log(await page.text('.rack-count'));
await page.screenshot('after.png');
await page.close();
```

Or `BROWSERDRIVE_BASE=… BROWSERDRIVE_KEY=… node browserdrive-client.mjs script.mjs /demo` with `export default async (page) => {…}`.

Under the hood both use the MCP tool `browserdrive_drive` (`open`, `step`, `result`, `close`) at `/mcp/message`, which
the platform authenticates by API key: agents with the app's MCP connection call it directly.

## Limits

- Input is synthetic: anything that needs a trusted gesture (pointer lock, fullscreen, starting audio, file pickers,
  the clipboard) cannot be driven, and a page that checks `isTrusted` ignores it.
- Only pages of the same site, and only the allowed paths. With script switched on, the list no longer contains what a session can reach.
- Screenshots: a canvas exactly; other content drawn from the page through SVG (close, not pixel-perfect; an image from
  another site prevents it).
- It runs only while the admin whose key opened the session has the runner open. A session nobody claims expires after 5 minutes, one whose runner stops
  answering after 90 s, an idle one after 15 minutes.
