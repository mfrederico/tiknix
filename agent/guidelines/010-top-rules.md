## Top Rules

1. **Check logs first** when something misbehaves: `tail -50 log/app-$(date +%Y-%m-%d).log`
2. Use the CLI tool for DB ops: `php scripts/clitool.php --help` (see [CLI Tool](#cli-tool))
3. **No explicit routes** — `Flight::defaultRoute()` auto-routes `/controller/method`
4. Use the `Bean::` wrapper (`lib/Bean.php`), never `R::` directly (except bootstrap + schema seeds)
5. String external IDs use the `_eid` suffix, never `_id` (reserved for RedBeanPHP integer FKs)
6. **No fallbacks. Fail loudly.** When something required is missing or wrong, raise or log
   an ERROR that names it. Never substitute a placeholder, a default, or the nearest working
   alternative — see [No Fallbacks](#no-fallbacks) below.
