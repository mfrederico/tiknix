## No Fallbacks

A fallback turns a broken thing into a plausible answer, which is worse than a crash: the
crash gets fixed, the plausible answer gets believed. These all shipped here and each one
cost real debugging time:

| The fallback | What it produced |
|---|---|
| `EngineRegistry::model()` returned `'sonnet'` for an undeclared tier | an Anthropic model id sent to a provider that had never heard of it, failing deep inside a build with nothing pointing at the missing config line |
| `PlanExecutor` ran a task on claude when its engine had no headless launcher | a plan that completed "successfully" with work done by a provider nobody chose, billed to another account |
| A `catch` returned `''` when a member's API key could not be decrypted | the operator's key silently used instead of the member's, while the UI still showed a key was saved |
| `/api/version` reported `"version":"unknown"` when its config key was missing | a misconfigured endpoint answering `status: ok` — the generated code's own comment described the fallback as deliberate |
| RedBean fluid mode swallowed `no such column` | a wrong query reported `(0 rows)`, indistinguishable from an empty table |

The rule in practice:

- **Missing config, credential, or dependency** → throw, or `error_log('ERROR …')` and return
  a value the caller cannot mistake for success. Name the setting and the file to fix.
- **Distinguish "absent" from "broken".** An empty row means no value; a value that fails to
  decrypt/parse is a fault. They must not produce the same result.
- **Never widen a test, a query, or a permission** to make a failure go away.
- `?:` and `??` are fine for a genuinely optional value. They are not fine for something the
  code needs to be correct.
