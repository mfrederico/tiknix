## Gotchas

- `json_encode([])` emits `[]`, not `{}` — use `(object)[]` when the client expects an object
- `Bean::getAll()` / `getRow()` return **arrays**; views written against beans expect
  objects — convert before rendering
- PHP 8 strict types: cast query-string ints explicitly, e.g. `$page = (int)($page ?? 1)`
- Escape all view output: `<?= htmlspecialchars($var) ?>`
- No silent defaults in layouts — let an undefined view variable error, so a
  controller/view mismatch surfaces immediately instead of rendering blank
- **A lead is written one way:** `Model_Lead::capture($email, $first, $last, ['gate' => …])`
  — one lead per email, blanks filled in, `source` set on create. The `gate` is required:
  `LeadGate::forPublicForm($params, $ip, [...])` for anything a visitor posted (Turnstile
  must be connected under Connections → Security, or it throws; honeypot, timing and content
  checks flag the lead as spam rather than refuse it), or `LeadGate::trusted('why')` when no
  visitor is involved. Never `Bean::dispense('lead')` in a controller.
