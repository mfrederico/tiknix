/**
 * The audit engine: given a rendered page, find every control on it and judge it.
 *
 * The standard this suite holds pages to is the owner's, stated plainly: *people should
 * be able to use this without thinking*. That fails in three ways, and each has a check
 * here:
 *
 *   1. the link is dead            -> resolve every destination, fail on >= 400
 *   2. the link lies               -> compare the LABEL against where it actually goes
 *   3. the control is unlabelled   -> every button needs an accessible name
 *
 * Check 2 is the one that found real breakage before: a "Publish" button that posted to
 * a route which had moved to a sidecar 404'd for weeks, because nothing was comparing
 * the promise to the destination.
 *
 * Nothing here CLICKS anything. Actuation is the caller's business, because only the
 * caller knows whether the thing being acted on is disposable — see isDestructive().
 */

/** Never fetched: following these has side effects the sweep must not cause. */
const NO_FETCH = [
  /\/auth\/logout/i,        // would end the session mid-sweep (tested deliberately elsewhere)
  /^mailto:/i, /^tel:/i, /^javascript:/i, /^data:/i,
];

/** Wording that means "this changes or destroys something". */
const DESTRUCTIVE_WORDS = /\b(delete|remove|revoke|destroy|disconnect|reset|wipe|purge|cancel|terminate|restart|reboot|deploy|publish|send|invite|trash|archive|unlink|rotate|leave)\b/i;

/**
 * Words carried by nearly every label; useless for judging where a link goes.
 *
 * The verbs are here for a reason worth stating: a label of nothing but a generic verb
 * ("Edit", "View", "Manage") is judged UNJUDGEABLE, not wrong. Such a label leans on the
 * row it sits in for meaning, which is legitimate in a table — and the check that
 * matters for it is the one we still run: does it resolve?
 */
const STOPWORDS = new Set([
  'the', 'a', 'an', 'my', 'your', 'our', 'to', 'and', 'or', 'of', 'for', 'in', 'on',
  'go', 'view', 'open', 'read', 'see', 'show', 'this', 'here', 'more', 'all', 'new',
  'back', 'manage', 'page', 'now', 'get', 'it', 'is', 'with',
  'edit', 'add', 'save', 'copy', 'select', 'choose', 'continue', 'next', 'close',
  'cancel', 'submit', 'change', 'update', 'details', 'info',
]);

/**
 * A link to a RECORD, whose label is the record's own name.
 *
 * "Testingbeautimous maximus" pointing at /communications/thread/2 is a message preview
 * linking to that message — the label is data, and demanding that data echo the URL
 * would be nonsense. Content links are still resolved; they are simply not judged on
 * wording. Recognised by an id in the path or query, which is what makes a URL a record.
 */
const RECORD_URL = /\/\d+(?:[/?#]|$)|[?&]id=\d+/;

/**
 * Labels whose destination cannot be inferred from their words, with the reason.
 * Keep this SHORT. Every entry is a small admission that a label could be clearer, so
 * it should be adding context a user already has (they are on the page), not excusing a
 * label that genuinely misleads.
 */
const LABEL_EXCEPTIONS = {
  'tiknix': 'wordmark; goes home',
  'home': 'goes to /',
  'logout': 'ends the session; not fetched',
  'login': 'goes to /auth/login',
  'sign in': 'goes to /auth/login',
  'register': 'goes to /auth/register',
  'dashboard': 'goes to /dashboard',
};

const norm = s => (s || '').replace(/\s+/g, ' ').trim();

const tokens = label =>
  norm(label).toLowerCase()
    .replace(/[^a-z0-9 ]+/g, ' ')
    .split(' ')
    .filter(t => t.length >= 3 && !STOPWORDS.has(t));

function isDestructive(label, className = '') {
  return DESTRUCTIVE_WORDS.test(label || '') || /btn-(outline-)?danger|text-danger/.test(className || '');
}

/**
 * Every link and button on the page, with enough context to judge and to click.
 * Runs in the browser so it sees the DOM as the user does — including controls a
 * framework rendered after load.
 */
async function collectControls(page) {
  return page.evaluate(() => {
    const visible = el => {
      const r = el.getBoundingClientRect();
      const s = getComputedStyle(el);
      return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
    };
    // The accessible name, in the order a screen reader resolves it. An image's alt
    // text counts: a badge wrapped in a link is named by its alt, not by its markup.
    const name = el => {
      const img = el.querySelector('img[alt]');
      return (el.getAttribute('aria-label') || el.title || el.innerText || el.value ||
              (img ? img.getAttribute('alt') : '') || '')
        .replace(/\s+/g, ' ').trim();
    };

    const out = [];
    const iconOf = el => {
      const i = el.querySelector('i[class*="bi-"]');
      return i ? (i.className.match(/bi-[\w-]+/) || [''])[0] : '';
    };
    const contextOf = el => {
      const row = el.closest('tr, li, .card, .ui-panel');
      return row ? (row.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 60) : '';
    };

    document.querySelectorAll('a[href]').forEach((el, i) => {
      out.push({
        kind: 'link',
        label: name(el),
        href: el.getAttribute('href'),
        resolved: el.href,
        className: typeof el.className === 'string' ? el.className : '',
        icon: iconOf(el),
        context: contextOf(el),
        visible: visible(el),
        // A tab/accordion toggle is a link by tag but a control by behaviour.
        toggles: el.getAttribute('data-bs-toggle') || '',
        target: el.getAttribute('target') || '',
        index: i,
      });
    });
    document.querySelectorAll('button, input[type=submit], [role=button]').forEach((el, i) => {
      // An icon-only control says what it means through its glyph. Capturing the icon
      // class and the row it sits in turns "35 unlabelled buttons" into a finding
      // someone can actually go and fix.
      out.push({
        kind: 'button',
        label: name(el),
        className: typeof el.className === 'string' ? el.className : '',
        id: el.id || '',
        icon: iconOf(el),
        context: contextOf(el),
        visible: visible(el),
        toggles: el.getAttribute('data-bs-toggle') || '',
        disabled: !!el.disabled,
        formAction: el.getAttribute('formaction') || '',
        index: i,
      });
    });
    return out;
  });
}

/**
 * Does this label describe where it goes?
 *
 * The haystack is the full destination URL plus the destination page's own title and
 * heading, because a good label matches at least one of them — /member/settings for
 * "Settings", docs.tiknix.com for "Read the docs", "Connections" for /connections.
 */
function labelAgrees(label, url, title, heading) {
  const key = norm(label).toLowerCase();
  if (LABEL_EXCEPTIONS[key]) return { ok: true, why: LABEL_EXCEPTIONS[key] };
  if (RECORD_URL.test(url)) return { ok: true, why: 'record link; the label is the record' };

  const t = tokens(label);
  if (!t.length) return { ok: true, why: 'no judgeable words in label' };

  const hay = `${url} ${title} ${heading}`.toLowerCase();
  const hit = t.find(tok => hay.includes(tok) || hay.includes(tok.replace(/s$/, '')));
  return hit
    ? { ok: true, why: `"${hit}" matches` }
    : { ok: false, why: `none of [${t.join(', ')}] appears in ${url} / "${norm(title)}" / "${norm(heading)}"` };
}

/**
 * Resolve every link on the page and judge its label.
 *
 * @returns {{checked: number, dead: object[], mislabelled: object[], skipped: object[]}}
 */
async function auditLinks(page, { origin, sameSiteOnly = true } = {}) {
  const controls = await collectControls(page);
  const links = controls.filter(c => c.kind === 'link');

  const dead = [];
  const mislabelled = [];
  const skipped = [];
  const seen = new Set();
  let checked = 0;

  for (const l of links) {
    const href = l.href || '';
    if (!href || href === '#' || href.startsWith('#')) { skipped.push({ ...l, why: 'in-page anchor' }); continue; }
    if (l.toggles) { skipped.push({ ...l, why: `bootstrap ${l.toggles} toggle` }); continue; }
    if (NO_FETCH.some(re => re.test(href))) { skipped.push({ ...l, why: 'side-effecting or non-http' }); continue; }

    let u;
    try { u = new URL(l.resolved); } catch { dead.push({ ...l, why: 'unparseable href' }); continue; }
    if (!/^https?:$/.test(u.protocol)) { skipped.push({ ...l, why: u.protocol }); continue; }

    // Third-party links (bootstrap CDN, flightphp.com) are not ours to keep alive.
    const ours = u.hostname === new URL(origin).hostname || u.hostname.endsWith('.tiknix.com');
    if (sameSiteOnly && !ours) { skipped.push({ ...l, why: 'third-party' }); continue; }

    const key = u.toString();
    if (seen.has(key)) continue;
    seen.add(key);

    let res;
    try {
      res = await page.request.get(key, { maxRedirects: 5, timeout: 20000 });
    } catch (e) {
      dead.push({ ...l, url: key, why: `request failed: ${e.message.split('\n')[0]}` });
      continue;
    }
    checked++;

    if (res.status() >= 400) {
      dead.push({ ...l, url: key, status: res.status(), why: `HTTP ${res.status()}` });
      continue;
    }

    const body = await res.text().catch(() => '');
    const title = (body.match(/<title[^>]*>([^<]*)</i) || [, ''])[1];
    const heading = (body.match(/<h1[^>]*>([\s\S]*?)<\/h1>/i) || [, ''])[1].replace(/<[^>]+>/g, '');
    const verdict = labelAgrees(l.label, key, title, heading);
    if (!verdict.ok) mislabelled.push({ label: l.label, url: key, why: verdict.why });
  }

  return { checked, dead, mislabelled, skipped, controls };
}

/**
 * Buttons with no accessible name — a control nobody can describe out loud, and one a
 * screen reader announces as just "button".
 *
 * Grouped by icon+class, because a table with thirty rows has one defect, not thirty:
 * the fix is a single line in one template.
 */
function unlabelledButtons(controls) {
  return groupUnnamed(controls, 'button');
}

/**
 * The same defect wearing an anchor tag. An icon-only <a> in a table row — the pencil
 * next to each rule — is a control with no name, however it is marked up.
 */
function unlabelledLinks(controls) {
  return groupUnnamed(controls, 'link').filter(c => c.href && c.href !== '#');
}

function groupUnnamed(controls, kind) {
  const groups = new Map();
  for (const c of controls) {
    if (c.kind !== kind || !c.visible || norm(c.label)) continue;
    const key = `${c.icon}|${c.className}|${c.id || ''}`;
    const g = groups.get(key) || { ...c, count: 0 };
    g.count++;
    groups.set(key, g);
  }
  return [...groups.values()];
}

/** Formats findings for an assertion message that is actually actionable. */
function report(title, rows, fmt) {
  if (!rows.length) return '';
  return `\n${title} (${rows.length}):\n` + rows.map(r => '  - ' + fmt(r)).join('\n');
}

module.exports = {
  collectControls, auditLinks, unlabelledButtons, unlabelledLinks,
  isDestructive, labelAgrees, report, norm,
};
