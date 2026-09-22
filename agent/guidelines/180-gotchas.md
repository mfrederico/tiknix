## Gotchas

- `json_encode([])` emits `[]`, not `{}` — use `(object)[]` when the client expects an object
- `Bean::getAll()` / `getRow()` return **arrays**; views written against beans expect
  objects — convert before rendering
- PHP 8 strict types: cast query-string ints explicitly, e.g. `$page = (int)($page ?? 1)`
- Escape all view output: `<?= htmlspecialchars($var) ?>`
- No silent defaults in layouts — let an undefined view variable error, so a
  controller/view mismatch surfaces immediately instead of rendering blank
