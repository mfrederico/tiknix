#!/usr/bin/env node
/*
 * acp-spike.js — Phase 0 de-risking harness for ACP in tiknix. ZERO npm deps (just node).
 *
 * Proves the end-to-end chain we care about BEFORE touching real surfaces:
 *   client (this)  --ACP/JSON-RPC/stdio-->  agent (kimi acp | claude adapter | …)
 *                                              |
 *                                              +-- MCP --> tiknix tools (reuse_digest, …)
 *
 * It runs:  initialize -> session/new (passing tiknix's MCP server) -> session/prompt
 * and it serves the agent's callbacks (session/request_permission, fs/read|write_text_file),
 * streaming session/update to your terminal. The prompt deliberately asks the agent to CALL a
 * tiknix MCP tool — if you see a tool_call for it, MCP-passthrough-over-ACP works. Wrap ACP_AGENT
 * in your jail to also prove the jail-hairpin.
 *
 * Usage:
 *   node spike/acp-spike.js
 *   ACP_AGENT="kimi acp" ACP_CWD="/path/to/an/instance" node spike/acp-spike.js
 *   ACP_AGENT="npx -y @agentclientprotocol/claude-agent-acp" node spike/acp-spike.js
 *   ACP_AGENT="/home/ubuntu/capricorn/bin/jail-run.sh … kimi acp" node spike/acp-spike.js   # jailed
 *   ACP_MCP_HTTP_URL="https://tiknix.com/mcp/message" ACP_MCP_HTTP_TOKEN="tk_…" node spike/acp-spike.js
 *
 * All config is env-driven — see CFG below.
 *
 * ── Assumptions to confirm on first run (grounded in agentclientprotocol.com/protocol/v1) ──
 *  • FRAMING: newline-delimited JSON (ndjson), one compact object per line. If the handshake
 *    hangs with no reply, the agent may want LSP Content-Length framing — flip FRAMING below.
 *  • protocolVersion sent as the NUMBER 1. If the agent rejects it, try the string "1".
 *  • session/update discriminator read as update.sessionUpdate ?? update.type (we accept either).
 *  • permission reply sent FLAT: {outcome:"selected", optionId}. If rejected, try nested
 *    {outcome:{outcome:"selected", optionId}}.
 * These are 1-line tweaks; the harness + plumbing is the point.
 */
'use strict';
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

// ── config ────────────────────────────────────────────────────────────────
const CFG = {
  agent:   process.env.ACP_AGENT   || 'kimi acp',
  cwd:     process.env.ACP_CWD     || process.cwd(),
  protoVersion: process.env.ACP_PROTO ? JSON.parse(process.env.ACP_PROTO) : 1,
  prompt:  process.env.ACP_PROMPT  ||
    'Call the tiknix MCP tool "reuse_digest" (or "codebase_map" if that is unavailable) and ' +
    'summarize in TWO lines what controllers and models already exist. Do NOT create or edit any files.',
  // tiknix MCP server passed to the agent via session/new. Default: the stdio server.
  mcpHttpUrl:   process.env.ACP_MCP_HTTP_URL   || '',
  mcpHttpToken: process.env.ACP_MCP_HTTP_TOKEN || '',
  mcpCmd:       process.env.ACP_MCP_CMD  || 'php',
  mcpArgs:      process.env.ACP_MCP_ARGS ? process.env.ACP_MCP_ARGS.split(' ') : ['mcptools/mcp-fastmcp.php'],
  timeoutMs: Number(process.env.ACP_TIMEOUT || 120000),
};
const FRAMING = process.env.ACP_FRAMING || 'ndjson';   // 'ndjson' | 'headers'

// ── verification checklist (filled in as things happen) ─────────────────────
const seen = { initialized:false, sessionNew:false, mcpHttp:null, loadSession:null,
               toolCall:false, tiknixTool:false, permission:false, fsRead:false, fsWrite:false,
               promptDone:false, stopReason:null };

// ── tiny logger ─────────────────────────────────────────────────────────────
const ts = () => new Date().toISOString().slice(11, 23);
const log  = (...a) => console.log(`[${ts()}]`, ...a);
const out  = (...a) => console.log('   ', ...a);

// ── spawn the agent ──────────────────────────────────────────────────────────
log(`spawning agent:  ${CFG.agent}`);
log(`session cwd:     ${CFG.cwd}`);
const child = spawn('sh', ['-c', CFG.agent], { stdio: ['pipe', 'pipe', 'inherit'] });
child.on('error', (e) => { log('AGENT SPAWN ERROR:', e.message); process.exit(1); });
child.on('exit', (code, sig) => { log(`agent exited (code=${code} sig=${sig})`); finish(); });

// ── JSON-RPC transport (ndjson by default) ────────────────────────────────────
let nextId = 1;
const pending = new Map();
function send(obj) {
  const json = JSON.stringify(obj);
  if (FRAMING === 'headers') child.stdin.write(`Content-Length: ${Buffer.byteLength(json)}\r\n\r\n${json}`);
  else child.stdin.write(json + '\n');
}
function rpc(method, params) {
  const id = nextId++;
  send({ jsonrpc: '2.0', id, method, params });
  log(`→ ${method}`, params ? JSON.stringify(params).slice(0, 160) : '');
  return new Promise((res, rej) => pending.set(id, { res, rej, method }));
}
function reply(id, result)      { send({ jsonrpc: '2.0', id, result }); }
function replyErr(id, code, msg){ send({ jsonrpc: '2.0', id, error: { code, message: msg } }); }

// incoming: ndjson line-buffer (also tolerates Content-Length frames)
let buf = '';
child.stdout.setEncoding('utf8');
child.stdout.on('data', (chunk) => {
  buf += chunk;
  if (FRAMING === 'headers') {
    let m;
    while ((m = buf.match(/Content-Length:\s*(\d+)\r\n\r\n/i))) {
      const len = +m[1], start = m.index + m[0].length;
      if (buf.length < start + len) break;
      dispatch(buf.slice(start, start + len)); buf = buf.slice(start + len);
    }
    return;
  }
  let nl;
  while ((nl = buf.indexOf('\n')) >= 0) {
    const line = buf.slice(0, nl).trim(); buf = buf.slice(nl + 1);
    if (line) dispatch(line);
  }
});

function dispatch(line) {
  let msg; try { msg = JSON.parse(line); } catch { log('‹non-json›', line.slice(0, 120)); return; }
  if (msg.method && msg.id !== undefined)      onRequest(msg);        // agent → client request
  else if (msg.method)                          onNotification(msg);  // agent → client notification
  else if (msg.id !== undefined) {                                    // response to our rpc()
    const p = pending.get(msg.id); if (!p) return; pending.delete(msg.id);
    if (msg.error) { log(`← ERROR (${p.method}):`, JSON.stringify(msg.error)); p.rej(msg.error); }
    else p.res(msg.result);
  }
}

// ── agent → client requests we must serve ─────────────────────────────────────
function onRequest(msg) {
  const { id, method, params } = msg;
  switch (method) {
    case 'session/request_permission': {
      seen.permission = true;
      const opts = params.options || [];
      const pick = opts.find(o => /allow/i.test(o.kind || o.optionId || '')) || opts[0];
      log(`← session/request_permission  → auto-selecting "${pick && (pick.optionId)}"`);
      reply(id, pick ? { outcome: 'selected', optionId: pick.optionId }   // flat form (see header note)
                     : { outcome: 'cancelled' });
      return;
    }
    case 'fs/read_text_file': {
      seen.fsRead = true;
      try {
        let c = fs.readFileSync(params.path, 'utf8');
        if (params.line || params.limit) {
          const lines = c.split('\n');
          const from = (params.line ? params.line - 1 : 0);
          c = lines.slice(from, params.limit ? from + params.limit : undefined).join('\n');
        }
        log(`← fs/read_text_file ${params.path}  (${c.length} bytes)`);
        reply(id, { content: c });
      } catch (e) { replyErr(id, -32000, e.message); }
      return;
    }
    case 'fs/write_text_file': {
      seen.fsWrite = true;
      try {
        fs.mkdirSync(path.dirname(params.path), { recursive: true });
        fs.writeFileSync(params.path, params.content);
        log(`← fs/write_text_file ${params.path}  (${(params.content||'').length} bytes) [WROTE]`);
        reply(id, {});
      } catch (e) { replyErr(id, -32000, e.message); }
      return;
    }
    default:
      // we advertised terminal:false, so terminal/* shouldn't arrive; refuse anything unknown.
      log(`← unhandled agent request: ${method} → -32601`);
      replyErr(id, -32601, `method not supported by spike: ${method}`);
  }
}

// ── agent → client notifications (the streaming surface) ──────────────────────
function onNotification(msg) {
  if (msg.method !== 'session/update') { log(`← notify ${msg.method}`); return; }
  const u = (msg.params && msg.params.update) || {};
  const kind = u.sessionUpdate ?? u.type;   // accept either discriminator
  switch (kind) {
    case 'agent_message_chunk': process.stdout.write((u.content && u.content.text) || ''); break;
    case 'agent_thought_chunk': /* quiet */ break;
    case 'tool_call':
    case 'tool_call_update': {
      const tc = u.toolCall || u;
      const title = tc.title || tc.name || tc.kind || '';
      if (kind === 'tool_call') {
        seen.toolCall = true;
        if (/reuse_digest|codebase_map|whatprovides|describe|tiknix/i.test(JSON.stringify(tc))) seen.tiknixTool = true;
        log(`\n← tool_call: ${title}  [status=${tc.status || '?'}]`);
      }
      break;
    }
    case 'plan': log('← plan update'); break;
    default: log('← session/update', kind || JSON.stringify(u).slice(0, 100));
  }
}

// ── the flow ──────────────────────────────────────────────────────────────────
function mcpServers() {
  if (CFG.mcpHttpUrl) return [{
    type: 'http', name: 'tiknix', url: CFG.mcpHttpUrl,
    headers: CFG.mcpHttpToken ? [{ name: 'Authorization', value: `Bearer ${CFG.mcpHttpToken}` }] : [],
  }];
  return [{ type: 'stdio', name: 'tiknix', command: CFG.mcpCmd, args: CFG.mcpArgs, env: [] }];
}

(async () => {
  const killTimer = setTimeout(() => { log('TIMEOUT'); finish(); }, CFG.timeoutMs);
  try {
    const init = await rpc('initialize', {
      protocolVersion: CFG.protoVersion,
      clientCapabilities: { fs: { readTextFile: true, writeTextFile: true }, terminal: false },
    });
    seen.initialized = true;
    const caps = (init && init.agentCapabilities) || {};
    seen.loadSession = !!caps.loadSession;
    seen.mcpHttp = !!(caps.mcpCapabilities && caps.mcpCapabilities.http);
    log(`← initialize ok  (loadSession=${seen.loadSession}, mcp.http=${seen.mcpHttp})`);
    if (CFG.mcpHttpUrl && !seen.mcpHttp)
      log('  ⚠ you passed an HTTP MCP server but the agent did NOT advertise mcpCapabilities.http — it may ignore it. Use the stdio server, or a different engine.');

    const ns = await rpc('session/new', { cwd: CFG.cwd, mcpServers: mcpServers() });
    seen.sessionNew = true;
    const sessionId = ns && ns.sessionId;
    log(`← session/new ok  sessionId=${sessionId}`);

    log(`→ session/prompt  "${CFG.prompt.slice(0, 70)}…"\n----- agent output -----`);
    const pr = await rpc('session/prompt', { sessionId, prompt: [{ type: 'text', text: CFG.prompt }] });
    seen.promptDone = true; seen.stopReason = pr && pr.stopReason;
    console.log('\n------------------------');
    log(`← session/prompt done  stopReason=${seen.stopReason}`);
  } catch (e) {
    log('FLOW ERROR:', e && (e.message || JSON.stringify(e)));
  } finally {
    clearTimeout(killTimer); finish();
  }
})();

// ── teardown + verdict ────────────────────────────────────────────────────────
let done = false;
function finish() {
  if (done) return; done = true;
  try { child.stdin.end(); child.kill('SIGTERM'); } catch {}
  const ck = (b) => (b === true ? '✅' : b === false ? '❌' : '—');
  console.log('\n══════ Phase 0 spike checklist ══════');
  console.log(` ${ck(seen.initialized)}  ACP initialize handshake`);
  console.log(` ${ck(seen.sessionNew)}  session/new accepted (mcpServers passed)`);
  console.log(` ${ck(seen.toolCall)}  agent made a tool_call`);
  console.log(` ${ck(seen.tiknixTool)}  ↳ and it hit a TIKNIX MCP tool  ← the passthrough proof`);
  console.log(` ${ck(seen.permission)}  session/request_permission round-trip`);
  console.log(` ${ck(seen.fsRead)}  fs/read_text_file served`);
  console.log(` ${ck(seen.fsWrite)}  fs/write_text_file served`);
  console.log(` ${ck(seen.promptDone)}  session/prompt returned  (stopReason=${seen.stopReason})`);
  console.log(` info: agent loadSession=${ck(seen.loadSession)}  mcp.http=${ck(seen.mcpHttp)}`);
  console.log('═════════════════════════════════════');
  console.log('Passthrough proven when the top two AND the "tiknix MCP tool" line are ✅.');
  console.log('For the jail-hairpin: re-run with ACP_AGENT wrapped in jail-run.sh (stdio MCP), and/or');
  console.log('with ACP_MCP_HTTP_URL set (needs an engine advertising mcp.http).');
  setTimeout(() => process.exit(0), 150);
}
