## File Structure — the runtime and the app

tiknix is being split into a **runtime** every app runs on and the **app** itself
(RUNTIME-SPLIT-MAP.md). The runtime lives in `runtime/` (later `vendor/tiknix/runtime/`, a
versioned package); the app is everything else at the root.

```
runtime/controls, runtime/lib, runtime/models,       the RUNTIME: primitives (Bean, Sites, Mailer,
runtime/services, runtime/mcptools, runtime/views    ConnectionBindings…), stock pages, MCP tools,
                                                     pipelines, connectors, runtime seeds
/controls /lib /models /services /mcptools /views    the APP's own code
/concepts /connectors                                installed plugins and connector manifests
/conf /data /database /secure /log /public           the app's config, data and web root
/routes                                              route bootstraps (Flight::defaultRoute())
```

**Never edit a file under `runtime/`.** The runtime is upgraded as a whole; an edit there is
overwritten by the next update. To change a runtime page, controller or library:

1. **Extend it** — a slot, a setting, or your own class that calls the runtime's. Always first.
2. **Override it** — `php scripts/clitool.php --override=controls/Help.php` copies the runtime
   file to the same path in the app. The app's file then REPLACES the runtime's (controllers
   and libraries through the autoloader, views through the view resolver, seeds by file name).
   An overridden file is **never upgraded again**: `--update` moves the rest of the runtime and
   names the override STALE when the runtime changed it; its owner reconciles it by hand and
   runs `--override-record=<path>`. `--overrides` lists them all. Override the smallest thing
   that works — a view, not its controller.

Find the app root with `\app\Paths::root()` and the runtime's tree with
`\app\Paths::runtime()` — never `dirname(__DIR__)`, which means a different directory
depending on where a file lives.
