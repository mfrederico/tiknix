// Browser drive: the runner. Runs in /browserdrive, a page a person leaves open. It claims a session an agent opened,
// shows the session's page in a frame of this same site, plays each step there (Playwright-style: find, wait until it is
// there and visible, then act with the events a person's input would fire) and posts the result back.
// Input is synthetic: events a script dispatches, not a real mouse or keyboard (isTrusted is false), so what the browser
// keeps for real gestures (pointer lock, fullscreen, starting audio, file pickers, the clipboard) cannot be driven.
(() => {
  const CSRF = window.BD_CSRF, $ = id => document.getElementById(id);
  const frame = $('bdFrame'); let SHIM = '', running = false, session = null, stop = false, leaving = false; // leaving: the page started to go elsewhere during a step
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  // what is happening, made visible on the runner page (over the frame, never inside the driven page): the step's caption,
  // a pointer that moves to where it acts, and an outline round the thing it acts on
  const stage = $('bdStage'), cap = document.createElement('div'), ptr = document.createElement('div'), box = document.createElement('div');
  cap.className = 'bd-cap'; ptr.className = 'bd-ptr'; box.className = 'bd-box'; stage.style.position = 'relative'; stage.append(cap, ptr, box);
  const scale = () => (frame.getBoundingClientRect().width / frame.offsetWidth) || 1;
  const showAt = (x, y, press) => { const s = scale(); ptr.style.transform = `translate(${x * s}px, ${y * s}px)`; ptr.classList.toggle('down', !!press); ptr.classList.add('on'); };
  const outline = el => { if (!el || !el.getBoundingClientRect) { box.classList.remove('on'); return; } const r = el.getBoundingClientRect(), s = scale(); Object.assign(box.style, { left: r.left * s + 'px', top: r.top * s + 'px', width: r.width * s + 'px', height: r.height * s + 'px' }); box.classList.add('on'); clearTimeout(outline.t); outline.t = setTimeout(() => box.classList.remove('on'), 900); };
  const caption = t => { cap.textContent = t; cap.classList.toggle('on', !!t); };
  const api = async (path, body) => {
    const r = await fetch('/browserdrive/' + path, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body || {}) });
    const j = await r.json().catch(() => ({})); if (!r.ok || j.success === false) throw new Error(j.message || j.error || 'HTTP ' + r.status); return j.data !== undefined ? j.data : j;
  };
  const say = (t, cls) => { const li = document.createElement('li'); li.className = cls || ''; li.textContent = new Date().toLocaleTimeString() + '  ' + t; $('bdLog').prepend(li); while ($('bdLog').children.length > 200) $('bdLog').lastChild.remove(); };
  const status = t => { $('bdStatus').textContent = t; };

  // ---- the frame: its window, instrumented on every page load (console, errors, the hidden-tab frame shim) ----
  const W = () => frame.contentWindow, D = () => frame.contentWindow.document;
  function instrument() {
    const w = W(); if (!w || w.__BD) return; w.__BD = { logs: [] };
    const push = (lvl, text) => { w.__BD.logs.push({ lvl, text: String(text).slice(0, 1000), t: Date.now() }); if (w.__BD.logs.length > 500) w.__BD.logs.shift(); };
    for (const lvl of ['log', 'info', 'warn', 'error']) { const o = w.console[lvl].bind(w.console); w.console[lvl] = (...a) => { push(lvl, a.map(x => { try { return typeof x === 'string' ? x : x instanceof w.Error ? x.stack || x.message : JSON.stringify(x); } catch (e) { return String(x); } }).join(' ')); o(...a); }; }
    w.addEventListener('beforeunload', () => { leaving = true; }); w.addEventListener('pagehide', () => { leaving = true; });
    w.addEventListener('error', e => push('error', (e.message || e.error) + (e.filename ? ' @' + e.filename.replace(/^.*\//, '') + ':' + e.lineno : '')));
    w.addEventListener('unhandledrejection', e => push('error', 'unhandled: ' + String(e.reason && e.reason.message || e.reason)));
    if (SHIM) { const s = w.document.createElement('script'); s.textContent = SHIM; (w.document.head || w.document.documentElement).appendChild(s); }
  }
  frame.addEventListener('load', () => { try { instrument(); } catch (e) { say('could not instrument the page: ' + e.message, 'bad'); } });
  const navigate = (src, timeout = 30000) => new Promise((ok, no) => { const t = setTimeout(() => no(new Error('the page did not load within ' + timeout / 1000 + ' s')), timeout); frame.addEventListener('load', () => { clearTimeout(t); setTimeout(ok, 50); }, { once: true }); frame.src = src; });
  const drain = () => { try { const L = W().__BD ? W().__BD.logs.splice(0) : []; return L; } catch (e) { return []; } };

  // ---- finding things: CSS, text=…, xpath=…; or a point { x, y } in the frame's viewport ----
  const visible = el => { if (!el || !el.isConnected) return false; const r = el.getBoundingClientRect(), cs = W().getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && +cs.opacity !== 0; };
  function query(target) {
    const d = D(), s = String(target);
    if (s.startsWith('xpath=')) { const r = d.evaluate(s.slice(6), d, null, XPathResult.ORDERED_NODE_SNAPSHOT_TYPE, null); return Array.from({ length: r.snapshotLength }, (_, i) => r.snapshotItem(i)).filter(n => n.nodeType === 1); }
    if (s.startsWith('text=')) { const want = s.slice(5).trim().toLowerCase(), all = [...d.querySelectorAll('body *')].filter(e => !['SCRIPT', 'STYLE'].includes(e.tagName));
      const txt = e => (e.innerText || e.value || e.getAttribute('aria-label') || e.title || '').replace(/\s+/g, ' ').trim().toLowerCase();
      const exact = all.filter(e => txt(e) === want), part = exact.length ? exact : all.filter(e => txt(e).includes(want));
      return part.filter(e => !part.some(o => o !== e && e.contains(o))); } // the innermost: the button, not the page around it
    return [...d.querySelectorAll(s)];
  }
  async function find(target, timeout = 5000, mustSee = true) { // Playwright's auto-wait: there, and visible, or a clear error
    const until = Date.now() + timeout; let last = [];
    do { last = query(target); const el = mustSee ? last.find(visible) : last[0]; if (el) return el; await sleep(100); } while (Date.now() < until);
    throw new Error(last.length ? `"${target}" is on the page but not visible (${last.length} match${last.length > 1 ? 'es' : ''})` : `nothing matches "${target}" after ${timeout / 1000} s`);
  }
  async function point(target) {
    if (target && typeof target === 'object' && 'x' in target) return { x: +target.x, y: +target.y, el: D().elementFromPoint(+target.x, +target.y) || D().body };
    const el = await find(target); el.scrollIntoView({ behavior: 'instant', block: 'center', inline: 'center' }); await sleep(30);
    const r = el.getBoundingClientRect(), x = r.left + r.width / 2, y = r.top + r.height / 2, hit = D().elementFromPoint(x, y);
    if (hit && hit !== el && !el.contains(hit) && !hit.contains(el)) throw new Error(`"${target}" is covered by ${describe(hit)} at its centre`);
    outline(el); showAt(x, y); await sleep(120); // a beat so a person watching sees where it goes
    return { x, y, el };
  }
  // A drag's two ends must be on screen together and measured AFTER the last scroll: measuring one, then scrolling to the other,
  // presses wherever the first has moved away from. A {x, y} end is where the frame shows now.
  async function dragPoints(from, to) {
    const isAt = t => t && typeof t === 'object' && 'x' in t, fe = isAt(from) ? null : await find(from), te = isAt(to) ? null : await find(to);
    const at = (t, el) => { if (!el) return { x: +t.x, y: +t.y, el: D().elementFromPoint(+t.x, +t.y) || D().body }; const r = el.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, el }; };
    const on = p => p.x >= 0 && p.y >= 0 && p.x < W().innerWidth && p.y < W().innerHeight;
    const near = { behavior: 'instant', block: 'nearest', inline: 'nearest' }, mid = { behavior: 'instant', block: 'center', inline: 'center' }; // instant: a page with smooth scrolling has not moved yet when the ends are measured
    let a, b, fits = false;
    for (const move of [() => { if (te) te.scrollIntoView(near); if (fe) fe.scrollIntoView(near); }, () => { if (te) te.scrollIntoView(mid); }, () => { if (fe) fe.scrollIntoView(mid); }]) {
      move(); await sleep(30); a = at(from, fe); b = at(to, te); if (on(a) && on(b)) { fits = true; break; }
    }
    if (!fits) throw new Error(`the drag's two ends do not fit on the screen together: make the frame taller first, with viewport { width, height }`);
    for (const [p, t, el] of [[a, from, fe], [b, to, te]]) { if (!el) continue; const hit = D().elementFromPoint(p.x, p.y); if (hit && hit !== el && !el.contains(hit) && !hit.contains(el)) throw new Error(`"${t}" is covered by ${describe(hit)} at its centre`); }
    if (fe) outline(fe); showAt(a.x, a.y); await sleep(120); if (te) outline(te); showAt(b.x, b.y); await sleep(120); showAt(a.x, a.y);
    return { a, b };
  }
  const describe = el => el ? el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '') : 'nothing';

  // ---- input: the event sequences a person's mouse and keyboard fire ----
  const mev = (type, p, o = {}) => { const w = W(), init = { bubbles: true, cancelable: true, composed: true, view: w, clientX: p.x, clientY: p.y, screenX: p.x, screenY: p.y, button: o.button || 0, buttons: o.buttons ?? 0, detail: o.detail || 0, ...o };
    const el = D().elementFromPoint(p.x, p.y) || p.el || D().body;
    if (type.startsWith('pointer')) el.dispatchEvent(new w.PointerEvent(type, { ...init, pointerId: 1, pointerType: 'mouse', isPrimary: true })); else el.dispatchEvent(new (type === 'wheel' ? w.WheelEvent : w.MouseEvent)(type, init)); return el; };
  async function click(p, button = 0, count = 1) {
    const b = button === 1 ? 4 : button === 2 ? 2 : 1;
    mev('pointerover', p); mev('pointerenter', p); mev('mouseover', p); mev('pointermove', p); mev('mousemove', p);
    for (let i = 1; i <= count; i++) {
      showAt(p.x, p.y, true); mev('pointerdown', p, { button, buttons: b }); const el = mev('mousedown', p, { button, buttons: b, detail: i }); if (el.focus) el.focus({ preventScroll: true });
      mev('pointerup', p, { button }); mev('mouseup', p, { button, detail: i }); showAt(p.x, p.y, false);
      if (button === 0) { const t = D().elementFromPoint(p.x, p.y) || p.el; t.dispatchEvent(new (W().MouseEvent)('click', { bubbles: true, cancelable: true, composed: true, view: W(), clientX: p.x, clientY: p.y, screenX: p.x, screenY: p.y, detail: i })); } /* with its coordinates: element.click() reports 0, 0 */
      else if (button === 2) mev('contextmenu', p, { button });
    }
    if (count === 2) mev('dblclick', p, { detail: 2 });
  }
  const KEYS = { Enter: 13, Tab: 9, Escape: 27, Backspace: 8, Delete: 46, ' ': 32, Space: 32, ArrowLeft: 37, ArrowUp: 38, ArrowRight: 39, ArrowDown: 40, Shift: 16, Control: 17, Alt: 18, Meta: 91, Home: 36, End: 35, PageUp: 33, PageDown: 34 };
  const codeOf = k => k === ' ' || k === 'Space' ? 'Space' : /^[a-z]$/i.test(k) ? 'Key' + k.toUpperCase() : /^[0-9]$/.test(k) ? 'Digit' + k : k;
  function key(type, k, mods, target) {
    const w = W(), name = k === 'Space' ? ' ' : k, ev = new w.KeyboardEvent(type, { key: name, code: codeOf(k), bubbles: true, cancelable: true, composed: true, view: w, shiftKey: mods.Shift, ctrlKey: mods.Control, altKey: mods.Alt, metaKey: mods.Meta, repeat: false });
    const kc = KEYS[k] ?? (name.length === 1 ? name.toUpperCase().charCodeAt(0) : 0); Object.defineProperty(ev, 'keyCode', { get: () => kc }); Object.defineProperty(ev, 'which', { get: () => kc });
    return (target || D().activeElement || D().body).dispatchEvent(ev);
  }
  const setValue = (el, v) => { const proto = el instanceof W().HTMLTextAreaElement ? W().HTMLTextAreaElement.prototype : el instanceof W().HTMLSelectElement ? W().HTMLSelectElement.prototype : W().HTMLInputElement.prototype; const d = Object.getOwnPropertyDescriptor(proto, 'value'); if (d && d.set) d.set.call(el, v); else el.value = v; }; // the native setter: frameworks that track a value see the change
  const fire = (el, type) => el.dispatchEvent(new (W().Event)(type, { bubbles: true }));

  // ---- screenshots: a canvas exactly; anything else drawn from the page into an SVG (close, not pixel-perfect) ----
  async function shot(target) {
    const d = D(), el = target ? await find(target) : d.documentElement;
    const cv = el.tagName === 'CANVAS' ? el : null; if (cv) { /* a canvas named as the target: exactly. The whole page is drawn from the page, its canvases in place (a background canvas is not the page) */ await new Promise(r => W().requestAnimationFrame(r)); return { png: cv.toDataURL('image/png'), w: cv.width, h: cv.height, how: 'canvas' }; }
    const r = target ? el.getBoundingClientRect() : { left: 0, top: 0, width: W().innerWidth, height: W().innerHeight }, clone = el.cloneNode(true), src = [el, ...el.querySelectorAll('*')], dst = [clone, ...clone.querySelectorAll('*')];
    if (src.length > 4000) throw new Error('the page has ' + src.length + ' elements: screenshot a part of it (a target)');
    src.forEach((s, i) => { const t = dst[i]; if (!t || !t.style) return; const cs = W().getComputedStyle(s); let css = ''; for (const p of cs) css += p + ':' + cs.getPropertyValue(p) + ';'; t.setAttribute('style', css);
      if (s.tagName === 'CANVAS') { const img = d.createElement('img'); try { img.src = s.toDataURL(); } catch (e) {} img.setAttribute('style', css); t.replaceWith(img); }
      if (s.tagName === 'INPUT' || s.tagName === 'TEXTAREA') t.setAttribute('value', s.value); });
    const html = new XMLSerializer().serializeToString(clone), svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${r.width}" height="${r.height}"><foreignObject x="${-r.left}" y="${-r.top}" width="${Math.max(r.width + r.left, W().innerWidth)}" height="${Math.max(r.height + r.top, W().innerHeight)}">${html}</foreignObject></svg>`;
    const img = new Image(); img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg); await img.decode().catch(() => { throw new Error('the page could not be drawn as a picture'); });
    const c = document.createElement('canvas'); c.width = Math.ceil(r.width); c.height = Math.ceil(r.height); c.getContext('2d').drawImage(img, 0, 0);
    try { return { png: c.toDataURL('image/png'), w: c.width, h: c.height, how: 'dom' }; } catch (e) { throw new Error('the picture was tainted by an image from another site: screenshot a part without it'); }
  }
  const safe = v => { try { return v === undefined ? null : JSON.parse(JSON.stringify(v)); } catch (e) { return String(v); } };

  // JavaScript for evaluate and waitFor: an expression, or a function to call with arg, or else the body of a function
  const script = src => { src = String(src).trim(); const w = W(); let fn; try { fn = new w.Function('return (' + src + '\n)'); } catch (e) { fn = new w.Function('arg', src); } return async arg => { let v = await fn.call(w, arg); if (typeof v === 'function') v = await v.call(w, arg); return v; }; };
  // ---- the commands ----
  async function play(c) {
    switch (c.do) {
      case 'goto': await navigate(c.path); return { url: W().location.pathname + W().location.search };
      case 'reload': await navigate(W().location.href); return {};
      case 'back': { const p = new Promise(r => frame.addEventListener('load', r, { once: true })); W().history.back(); await Promise.race([p, sleep(5000)]); return { url: W().location.pathname }; }
      case 'viewport': frame.style.width = (+c.width || 1280) + 'px'; frame.style.height = (+c.height || 800) + 'px'; fit(); await sleep(100); return { width: W().innerWidth, height: W().innerHeight };
      case 'click': await click(await point(c.target), +c.button || 0, +c.count || 1); return {};
      case 'dblclick': await click(await point(c.target), 0, 2); return {};
      case 'hover': { const p = await point(c.target); mev('pointerover', p); mev('mouseover', p); mev('pointermove', p); mev('mousemove', p); return {}; }
      case 'fill': { const el = await find(c.target); el.focus(); if (el.isContentEditable) el.textContent = String(c.value ?? ''); else setValue(el, String(c.value ?? '')); el.dispatchEvent(new (W().InputEvent)('input', { bubbles: true, inputType: 'insertText', data: String(c.value ?? '') })); fire(el, 'change'); return {}; }
      case 'type': { const el = c.target ? await find(c.target) : D().activeElement; if (c.target) el.focus();
        for (const ch of String(c.text ?? '')) { const go = key('keydown', ch, {}, el); if (go) { key('keypress', ch, {}, el); if ('value' in el && el.tagName !== 'BUTTON') { setValue(el, el.value + ch); el.dispatchEvent(new (W().InputEvent)('input', { bubbles: true, inputType: 'insertText', data: ch })); } } key('keyup', ch, {}, el); if (c.delay) await sleep(+c.delay); }
        if ('value' in el) fire(el, 'change'); return {}; }
      case 'press': { const parts = String(c.key).split('+'), k = parts.pop(), mods = { Shift: parts.includes('Shift'), Control: parts.includes('Control'), Alt: parts.includes('Alt'), Meta: parts.includes('Meta') }, el = c.target ? await find(c.target) : null; if (el) el.focus();
        for (const m of parts) key('keydown', m, mods, el); const go = key('keydown', k, mods, el); if (k.length === 1 && go) key('keypress', k, mods, el);
        if (c.hold) await sleep(+c.hold); key('keyup', k, mods, el); for (const m of parts.reverse()) key('keyup', m, mods, el);
        const a = el || D().activeElement; if (k === 'Enter' && go && a && a.form && a.tagName === 'INPUT') a.form.requestSubmit(); // what a browser does for a real Enter in a form
        return {}; }
      case 'check': case 'uncheck': { const el = await find(c.target); if (el.checked !== (c.do === 'check')) await click(await point(c.target)); return { checked: el.checked }; }
      case 'select': { const el = await find(c.target), want = String(c.value), opt = [...el.options].find(o => o.value === want || o.textContent.trim() === want); if (!opt) throw new Error(`"${c.target}" has no option "${want}"`); setValue(el, opt.value); fire(el, 'input'); fire(el, 'change'); return { value: el.value }; }
      case 'drag': { const { a, b } = await dragPoints(c.from, c.to), n = Math.max(1, +c.steps || 10);
        if (a.el && a.el.draggable) { const dt = new (W().DataTransfer)(), dev = (t, el, p) => el.dispatchEvent(new (W().DragEvent)(t, { bubbles: true, cancelable: true, dataTransfer: dt, clientX: p.x, clientY: p.y })); // HTML drag and drop
          dev('dragstart', a.el, a); const tgt = D().elementFromPoint(b.x, b.y) || D().body; dev('dragenter', tgt, b); dev('dragover', tgt, b); dev('drop', tgt, b); dev('dragend', a.el, b); return { how: 'html-dnd' }; }
        mev('pointermove', a); mev('mousemove', a); mev('pointerdown', a, { buttons: 1 }); mev('mousedown', a, { buttons: 1, detail: 1 });
        for (let i = 1; i <= n; i++) { const p = { x: a.x + (b.x - a.x) * i / n, y: a.y + (b.y - a.y) * i / n }; showAt(p.x, p.y, true); mev('pointermove', p, { buttons: 1 }); mev('mousemove', p, { buttons: 1 }); await sleep(16); }
        mev('pointerup', b); mev('mouseup', b, { detail: 1 }); return { how: 'pointer' }; }
      case 'mouse': { const p = { x: +c.x, y: +c.y }, btn = +c.button || 0, map = { move: ['pointermove', 'mousemove'], down: ['pointerdown', 'mousedown'], up: ['pointerup', 'mouseup'] }[c.action]; if (!map) throw new Error('mouse action is move, down or up');
        for (const t of map) mev(t, p, { button: btn, buttons: c.action === 'down' ? 1 : 0 }); return {}; }
      case 'wheel': { const p = { x: +c.x || W().innerWidth / 2, y: +c.y || W().innerHeight / 2 }; showAt(p.x, p.y); const el = D().elementFromPoint(p.x, p.y) || D().body, w = W();
        const ev = new w.WheelEvent('wheel', { bubbles: true, cancelable: true, composed: true, view: w, clientX: p.x, clientY: p.y, deltaX: +c.dx || 0, deltaY: +c.dy || 0, deltaMode: 0 }); const go = el.dispatchEvent(ev);
        if (go) { let t = el; while (t && t !== D().documentElement && !(t.scrollHeight > t.clientHeight && /(auto|scroll)/.test(w.getComputedStyle(t).overflowY))) t = t.parentElement; // a scripted wheel event scrolls nothing: scroll what the browser would, unless the page took the event
          if (t && t !== D().documentElement) t.scrollBy(+c.dx || 0, +c.dy || 0); else w.scrollBy(+c.dx || 0, +c.dy || 0); }
        return { scrolled: go, scrollY: w.scrollY }; }
      case 'waitFor': { const timeout = Math.min(60000, +c.timeout || 10000), until = Date.now() + timeout, state = c.state || 'visible';
        const fn = c.js ? script(c.js) : null;
        while (Date.now() < until) { let ok = false;
          if (fn) { try { ok = !!(await fn()); } catch (e) { ok = false; } }
          else if (c.text) ok = (D().body.innerText || '').toLowerCase().includes(String(c.text).toLowerCase());
          else { const els = query(c.target); ok = state === 'attached' ? els.length > 0 : state === 'detached' ? els.length === 0 : state === 'hidden' ? !els.some(visible) : els.some(visible); }
          if (ok) return { waited: timeout - (until - Date.now()) }; await sleep(100); }
        throw new Error(`waited ${timeout / 1000} s and ${c.js ? 'the condition never held' : c.text ? `the text "${c.text}" never appeared` : `"${c.target}" was never ${state}`}`); }
      case 'wait': await sleep(Math.min(60000, +c.ms || 0)); return {};
      case 'evaluate': return { value: safe(await script(String(c.js))(c.arg)) };
      case 'text': return { value: ((await find(c.target, 5000, false)).innerText || '').trim() };
      case 'value': return { value: (await find(c.target, 5000, false)).value ?? null };
      case 'attr': return { value: (await find(c.target, 5000, false)).getAttribute(String(c.name)) };
      case 'count': return { value: query(c.target).length };
      case 'html': return { value: (c.target ? (await find(c.target, 5000, false)).outerHTML : D().documentElement.outerHTML).slice(0, 200000) };
      case 'url': return { value: W().location.pathname + W().location.search + W().location.hash };
      case 'title': return { value: D().title };
      case 'screenshot': return await shot(c.target);
      case 'console': return { value: drain() };
    }
    throw new Error('this runner does not know the command ' + c.do);
  }

  // ---- the loop: claim a session, play its steps, give it back ----
  async function run() {
    running = true; stop = false; $('bdStart').textContent = 'Stop'; status('waiting for a session…');
    SHIM = SHIM || await (await fetch('/browserdrive/shimjs', { cache: 'no-store' })).text();
    let broken = false; // said on the page: a tab that cannot claim must not go on saying it is waiting
    while (!stop) {
      try {
        if (!session) { const c = await api('claim'); if (broken) { broken = false; status('waiting for a session…'); } if (!c.session) { await sleep(3000); continue; }
          session = c; status(`session ${c.session}: ${c.path}${c.note ? ' · ' + c.note : ''}`); say(`claimed session ${c.session} at ${c.path}`, 'ok'); await navigate(c.path); }
        const n = await api('next', { session: session.session, wait: 20 }), nx = n.next; if (!nx) continue;
        if (nx.closed) { say(`session ${session.session} ended: ${nx.why || 'closed'}`); session = null; frame.src = 'about:blank'; caption(''); ptr.classList.remove('on'); status('waiting for a session…'); continue; }
        const t0 = performance.now(); let result;
        caption(`#${nx.n} ${nx.cmd.do}${nx.cmd.target ? ' ' + (typeof nx.cmd.target === 'string' ? nx.cmd.target : JSON.stringify(nx.cmd.target)) : nx.cmd.key ? ' ' + nx.cmd.key : nx.cmd.path ? ' ' + nx.cmd.path : ''}`);
        leaving = false; const loaded = new Promise(r => frame.addEventListener('load', r, { once: true }));
        try { result = { ok: true, ...(await play(nx.cmd)) }; say(`#${nx.n} ${nx.cmd.do} ${JSON.stringify(nx.cmd).slice(0, 120)} ✓`); }
        catch (e) { result = { error: String(e && e.message || e) }; say(`#${nx.n} ${nx.cmd.do} ✗ ${result.error}`, 'bad'); }
        await sleep(150); if (leaving && !['goto', 'reload', 'back'].includes(nx.cmd.do)) await Promise.race([loaded, sleep(15000)]); // a step that sent the page elsewhere is answered from where it landed
        let url = ''; try { url = W().location.pathname + W().location.search; } catch (e) { url = '(another site)'; } /* a page of another site cannot be read: it is outside the sandbox, and the server ends the session */
        await api('done', { session: session.session, step: nx.step, result: { ...result, url, ms: Math.round(performance.now() - t0), logs: nx.cmd.do === 'console' ? undefined : drain() } });
      } catch (e) { say('runner: ' + e.message, 'bad'); if (!session) { broken = true; status('NOT WORKING: ' + e.message); } if (/not claimed|no such session/.test(e.message)) { session = null; frame.src = 'about:blank'; } await sleep(3000); }
    }
    running = false; $('bdStart').textContent = 'Start'; status('stopped: no session will be claimed by this page');
  }
  function fit() { const box = $('bdStage'), s = Math.min(1, box.clientWidth / frame.offsetWidth); frame.style.transform = `scale(${s})`; box.style.height = frame.offsetHeight * s + 'px'; }
  addEventListener('resize', fit); fit();
  $('bdStart').onclick = () => { if (running) { stop = true; $('bdStart').textContent = 'Stopping…'; } else run(); };
  if ($('bdStart').dataset.auto === '1') run();
})();
