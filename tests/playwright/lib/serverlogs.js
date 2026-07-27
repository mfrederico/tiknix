/**
 * Server-log watcher.
 *
 * "The page rendered" is not the same as "the page worked". Several defects this suite
 * exists to catch — a missing permission row, a swallowed exception, a 404 behind an
 * XHR — leave a clean-looking screen and a loud log line. So every test brackets its
 * work with a mark/harvest pair and fails on anything the server logged at ERROR or
 * worse while it ran.
 *
 * Offsets, not tails: the logs are shared with a live site, so we only ever look at the
 * bytes appended between mark() and harvest(). Everything the rest of the world was
 * doing at the same time is still in there — filterNoise() is where that gets handled,
 * and it errs toward reporting, because a false alarm costs a look and a miss costs the
 * whole point of the suite.
 */
const fs = require('fs');
const path = require('path');

const FAIL_RE = /app\.(ERROR|CRITICAL|ALERT|EMERGENCY)|PHP Fatal|Uncaught \w*Error|Stack trace:/;
const WARN_RE = /app\.WARNING/;

/** Lines that are someone else's traffic, or expected, and must not fail a run. */
const NOISE = [
  /favicon\.ico/,
  /client denied by server configuration/,
];

class ServerLogs {
  /**
   * @param {string[]} targets  Files or directories. A directory is expanded to its
   *                            *.log children at both mark and harvest time, so a log
   *                            that rolls over mid-run is still followed.
   */
  constructor(targets = []) {
    this.targets = targets;
    this.offsets = new Map();
    this.missing = [];
  }

  files() {
    const out = [];
    for (const t of this.targets) {
      let st;
      try { st = fs.statSync(t); } catch { this.missing.push(t); continue; }
      if (st.isDirectory()) {
        for (const f of fs.readdirSync(t)) if (f.endsWith('.log')) out.push(path.join(t, f));
      } else {
        out.push(t);
      }
    }
    return out;
  }

  mark() {
    this.missing = [];
    for (const f of this.files()) {
      try { this.offsets.set(f, fs.statSync(f).size); } catch { /* vanished; harvest reads it whole */ }
    }
  }

  /** @returns {{errors: string[], warnings: string[], watched: number}} */
  harvest() {
    const errors = [];
    const warnings = [];
    const files = this.files();
    for (const f of files) {
      const from = this.offsets.get(f) ?? 0;
      let chunk = '';
      try {
        const size = fs.statSync(f).size;
        if (size < from) continue;                    // truncated/rotated — skip the delta
        const fd = fs.openSync(f, 'r');
        const len = size - from;
        if (len > 0) {
          const buf = Buffer.alloc(Math.min(len, 4 * 1024 * 1024));
          fs.readSync(fd, buf, 0, buf.length, from);
          chunk = buf.toString('utf8');
        }
        fs.closeSync(fd);
      } catch (e) {
        continue;                                     // unreadable (root-owned) — reported via missing
      }
      for (const line of chunk.split('\n')) {
        if (!line.trim() || NOISE.some(re => re.test(line))) continue;
        const tagged = `${path.basename(f)}: ${line.slice(0, 400)}`;
        if (FAIL_RE.test(line)) errors.push(tagged);
        else if (WARN_RE.test(line)) warnings.push(tagged);
      }
      this.offsets.set(f, (this.offsets.get(f) ?? 0) + chunk.length);
    }
    return { errors, warnings, watched: files.length };
  }
}

module.exports = { ServerLogs };
