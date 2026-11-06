// Browser drive: the Node client (Node 18+, no dependencies). Playwright-style calls to the site's MCP tool browserdrive_drive;
// a person's open runner page (/browserdrive on the same site) plays each step in a real browser and the result comes back.
//
//   curl -o browserdrive-client.mjs https://<site>/browserdrive/client   (an ES module: keep the .mjs name)
//   import { connect } from './browserdrive-client.mjs';
//   const page = await connect({ base: 'https://example.com', key: process.env.BROWSERDRIVE_KEY, path: '/demo' });
//   await page.click('text=Add rack'); await page.fill('#name', 'Aisle 4'); await page.press('Enter');
//   console.log(await page.text('.status')); await page.screenshot('after.png'); await page.close();
//
// Or as a command:  BROWSERDRIVE_BASE=https://example.com BROWSERDRIVE_KEY=tk_… node browserdrive-client.mjs script.mjs /demo
// where script.mjs is  export default async (page) => { … }.  A failed step throws, with the runner's reason.
import { writeFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

export async function connect({ base, key, path, note = '', wait = 25, log = false } = {}) {
  if (!base || !key || !path) throw new Error('connect needs base (the site), key (an admin API key from /apikeys) and path (an allowed page to start on)');
  let rpc = 0;
  const tool = async args => { // the plugin's MCP tool, browserdrive_drive, at the site's /mcp/message (the platform checks the key)
    const r = await fetch(base.replace(/\/$/, '') + '/mcp/message', { method: 'POST', headers: { Authorization: 'Bearer ' + key, 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ jsonrpc: '2.0', id: ++rpc, method: 'tools/call', params: { name: 'browserdrive_drive', arguments: args } }) });
    const j = await r.json().catch(() => null); if (!r.ok || !j) throw new Error(`browserdrive: HTTP ${r.status} from /mcp/message`);
    if (j.error) throw new Error('browserdrive: ' + (j.error.message || JSON.stringify(j.error)));
    const text = j.result && j.result.content && j.result.content[0] ? j.result.content[0].text : ''; let out; try { out = JSON.parse(text); } catch (e) { throw new Error('browserdrive: ' + text); }
    if (j.result.isError) throw new Error('browserdrive: ' + (out.error || text)); return out;
  };
  const opened = await tool({ action: 'open', path, note }); if (!opened.ok) throw new Error('browserdrive open: ' + opened.error);
  const session = opened.session;
  async function step(cmd) {
    const png = cmd.do === 'screenshot';
    let r = await tool({ action: 'step', session, cmd, wait, png });
    while (r.pending) r = await tool({ action: 'result', step: r.step, wait, png }); // no runner yet, or a long step: keep asking
    if (r.error && !r.step) throw new Error(`${cmd.do}: ${r.error}`);
    const res = r.result || {};
    if (log && res.logs && res.logs.length) for (const l of res.logs) console.log(`[page ${l.lvl}] ${l.text}`);
    if (r.status === 'failed' || res.error) { const e = new Error(`step ${r.n} ${cmd.do}: ${res.error || r.why || 'failed'}`); e.result = res; throw e; }
    return res;
  }
  const val = async cmd => (await step(cmd)).value;
  return {
    session,
    step,
    goto: path => step({ do: 'goto', path }), reload: () => step({ do: 'reload' }), back: () => step({ do: 'back' }),
    viewport: (width, height) => step({ do: 'viewport', width, height }),
    click: (target, o = {}) => step({ do: 'click', target, ...o }), dblclick: target => step({ do: 'dblclick', target }), hover: target => step({ do: 'hover', target }),
    fill: (target, value) => step({ do: 'fill', target, value }), type: (target, text, o = {}) => step({ do: 'type', target, text, ...o }),
    press: (key, o = {}) => step({ do: 'press', key, ...o }), check: target => step({ do: 'check', target }), uncheck: target => step({ do: 'uncheck', target }),
    select: (target, value) => step({ do: 'select', target, value }), drag: (from, to, o = {}) => step({ do: 'drag', from, to, ...o }),
    mouse: (action, x, y, o = {}) => step({ do: 'mouse', action, x, y, ...o }), wheel: (dy, o = {}) => step({ do: 'wheel', dy, ...o }),
    waitFor: (target, o = {}) => step({ do: 'waitFor', target, ...o }), waitForText: (text, o = {}) => step({ do: 'waitFor', text, ...o }),
    waitForFunction: (js, o = {}) => step({ do: 'waitFor', js: String(js), ...o }), wait: ms => step({ do: 'wait', ms }),
    evaluate: (js, arg) => val({ do: 'evaluate', js: String(js), arg }),
    text: target => val({ do: 'text', target }), value: target => val({ do: 'value', target }), attr: (target, name) => val({ do: 'attr', target, name }),
    count: target => val({ do: 'count', target }), html: target => val({ do: 'html', target }), url: () => val({ do: 'url' }), title: () => val({ do: 'title' }),
    console: () => val({ do: 'console' }),
    async screenshot(file, target) { const r = await step({ do: 'screenshot', target }); const b64 = String(r.png || '').split(',')[1] || ''; if (file) writeFileSync(file, Buffer.from(b64, 'base64')); return { ...r, bytes: b64.length * 3 / 4 }; },
    close: () => tool({ action: 'close', session }),
  };
}

if (import.meta.url === pathToFileURL(process.argv[1] || '').href) { // node browserdrive-client.mjs script.mjs /start-path
  const [script, path] = process.argv.slice(2);
  if (!script || !path) { console.error('usage: BROWSERDRIVE_BASE=… BROWSERDRIVE_KEY=… node browserdrive-client.mjs script.mjs /start-path'); process.exit(2); }
  const page = await connect({ base: process.env.BROWSERDRIVE_BASE, key: process.env.BROWSERDRIVE_KEY, path, note: script, log: true });
  console.error(`session ${page.session}: waiting for a runner page to claim it…`);
  try { await (await import(pathToFileURL(script).href)).default(page); console.error('done'); }
  catch (e) { console.error(e.message); process.exitCode = 1; }
  finally { await page.close().catch(() => {}); }
}
