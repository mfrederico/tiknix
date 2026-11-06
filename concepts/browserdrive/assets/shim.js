// Browser drive: the frame shim, put into each page the runner shows (assets/runner.js). A browser stops animation frames in
// a tab that is not on screen, and a person's runner page is often behind another tab: a page that animates (a canvas, a
// simulation) would then stand still. While the document is hidden, requestAnimationFrame is served from a message-channel
// pump (not throttled like timers) at about 60 frames a second; while it is visible, the browser's own is used, and a frame
// waiting on either moves to the other when the tab is shown or hidden.
(() => {
  const native = window.requestAnimationFrame.bind(window), cancelNative = window.cancelAnimationFrame.bind(window), ch = new MessageChannel();
  const queue = new Map(), waiting = new Map(); let next = 1, last = 0, pumping = false; // queue: frames the pump owes (by our id); waiting: our id -> [native id, callback]
  const pump = () => { if (!pumping) { pumping = true; ch.port2.postMessage(0); } };
  const toNative = (id, cb) => waiting.set(id, [native(t => { waiting.delete(id); cb(t); }), cb]);
  ch.port1.onmessage = () => {
    if (!document.hidden) { pumping = false; for (const [id, cb] of [...queue]) { queue.delete(id); toNative(id, cb); } return; }
    const now = performance.now();
    if (now - last >= 16) { last = now; const due = [...queue.values()]; queue.clear(); for (const cb of due) { try { cb(now); } catch (e) { console.error(e); } } }
    if (queue.size) ch.port2.postMessage(0); else pumping = false;
  };
  document.addEventListener('visibilitychange', () => { if (!document.hidden) return; for (const [id, [nid, cb]] of [...waiting]) { cancelNative(nid); waiting.delete(id); queue.set(id, cb); } if (queue.size) pump(); });
  window.requestAnimationFrame = cb => { const id = next++; if (document.hidden) { queue.set(id, cb); pump(); } else toNative(id, cb); return id; };
  window.cancelAnimationFrame = id => { queue.delete(id); const w = waiting.get(id); if (w) { cancelNative(w[0]); waiting.delete(id); } };
  window.__BROWSERDRIVE_SHIM = true;
})();
