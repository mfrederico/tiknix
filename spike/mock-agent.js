#!/usr/bin/env node
/*
 * mock-agent.js — a fake ACP *agent* (ndjson/JSON-RPC over stdio) used ONLY to validate
 * acp-spike.js's client harness without a real engine. It simulates a prompt turn that:
 * asks for permission, emits a tiknix-tool tool_call, reads a file via the client, streams
 * a message, and finishes. Run:  ACP_AGENT="node spike/mock-agent.js" node spike/acp-spike.js
 */
'use strict';
const send = (o) => process.stdout.write(JSON.stringify(o) + '\n');
const reply = (id, result) => send({ jsonrpc: '2.0', id, result });
let cid = 1000; const waiting = new Map();
function callClient(method, params) {           // agent → client request
  const id = cid++; send({ jsonrpc: '2.0', id, method, params });
  return new Promise((res) => waiting.set(id, res));
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let buf = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (c) => {
  buf += c; let nl;
  while ((nl = buf.indexOf('\n')) >= 0) { const l = buf.slice(0, nl).trim(); buf = buf.slice(nl + 1); if (l) handle(JSON.parse(l)); }
});

async function handle(m) {
  if (m.method === undefined && m.id !== undefined) {           // client → agent response
    const w = waiting.get(m.id); if (w) { waiting.delete(m.id); w(m.result); } return;
  }
  const { id, method, params } = m;
  if (method === 'initialize') {
    reply(id, { protocolVersion: 1, agentCapabilities: {
      loadSession: false, promptCapabilities: { image: false, audio: false, embeddedContext: false },
      mcpCapabilities: { http: false, sse: false } } });
  } else if (method === 'session/new') {
    reply(id, { sessionId: 'sess-mock-1' });
  } else if (method === 'session/prompt') {
    const sid = params.sessionId;
    send({ jsonrpc: '2.0', method: 'session/update',
      params: { sessionId: sid, update: { sessionUpdate: 'agent_message_chunk', content: { type: 'text', text: 'Let me check what already exists…\n' } } } });
    // ask the client for permission to use a tool
    await callClient('session/request_permission', { sessionId: sid,
      toolCall: { toolCallId: 't1', title: 'reuse_digest', kind: 'other' },
      options: [ { optionId: 'allow_once', name: 'Allow once', kind: 'allow_once' },
                 { optionId: 'reject_once', name: 'Reject', kind: 'reject_once' } ] });
    // emit the (tiknix) tool call
    send({ jsonrpc: '2.0', method: 'session/update',
      params: { sessionId: sid, update: { sessionUpdate: 'tool_call',
        toolCall: { toolCallId: 't1', title: 'reuse_digest (tiknix)', kind: 'other', status: 'in_progress' } } } });
    // exercise the client's filesystem capability
    try { await callClient('fs/read_text_file', { sessionId: sid, path: process.cwd() + '/composer.json', limit: 5 }); } catch {}
    send({ jsonrpc: '2.0', method: 'session/update',
      params: { sessionId: sid, update: { sessionUpdate: 'tool_call_update',
        toolCall: { toolCallId: 't1', status: 'completed' } } } });
    send({ jsonrpc: '2.0', method: 'session/update',
      params: { sessionId: sid, update: { sessionUpdate: 'agent_message_chunk', content: { type: 'text', text: 'Controllers: Shop, Ecommerce, Connections. Models: Member, Contact.' } } } });
    await sleep(20);
    reply(id, { stopReason: 'completed' });
  } else if (id !== undefined) {
    send({ jsonrpc: '2.0', id, error: { code: -32601, message: 'mock: ' + method } });
  }
}
