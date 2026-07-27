/**
 * The path a customer actually walks: make a project, build something in it, see it
 * live, wire up publishing, then throw the project away.
 *
 * The project is DISPOSABLE and owned by this file end to end. That is not squeamishness
 * — a suite that clicks every button in a real project would open real pull requests,
 * restart real containers and rsync over real servers. Here everything it breaks is
 * something it made two minutes ago.
 *
 * Serial by necessity: each step is the previous step's precondition.
 */
const fs = require('fs');
const { test, expect } = require('../fixtures/base');
const { buildApp, seedApp, projectDir } = require('../fixtures/app');
const { writeRun, readRun } = require('../lib/global-setup');
const env = require('../lib/env');

test.describe.configure({ mode: 'serial' });

// Slug rules: lowercase first letter, then letters/numbers/hyphens. The run id keeps
// concurrent or interrupted runs from colliding on a name.
const RUN_ID = Math.random().toString(36).slice(2, 8);
const SLUG = `e2e${RUN_ID}`;

let project = { id: 0, slug: '' };

test('a project can be created from the picker, and creating it selects it', async ({ page, watch }) => {
  await page.goto('/projects', { waitUntil: 'domcontentloaded' });

  // The create call answers with the new id and the slug the server actually minted
  // (it appends a suffix), and the page navigates away the instant it lands. Reading
  // the body from a waitForResponse loses that race, so intercept and keep a copy.
  let json = null;
  await page.route('**/projects/create', async route => {
    const response = await route.fetch({ timeout: 180_000 });
    json = await response.json().catch(() => null);
    await route.fulfill({ response });
  });

  await page.getByRole('button', { name: 'New project' }).click();
  await page.locator('#proj-new-slug').fill(SLUG);
  await page.getByRole('button', { name: /create/i }).click();

  await expect.poll(() => json !== null, { timeout: 180_000, message: 'the create call never answered' })
    .toBe(true);
  expect(json.success, `creating the project failed: ${json.message}`).toBe(true);

  project = { id: Number(json.data.id), slug: String(json.data.slug) };
  // Record it BEFORE anything else can fail: global-teardown removes whatever is here.
  writeRun({ project: { ...project, deleted: false } });
  watch.watchInstance(project.slug);

  expect(project.id).toBeGreaterThan(0);
  expect(project.slug).toMatch(/^e2e/);

  await page.waitForURL(/\/dashboard/, { timeout: 30_000 });

  // "Create and work on it" — so it must now be the selected project.
  await page.goto('/projects', { waitUntil: 'domcontentloaded' });
  const selected = await page.evaluate(() => {
    const b = [...document.querySelectorAll('.proj-pick')].find(x => /continue/i.test(x.innerText));
    return b ? Number(b.dataset.id) : 0;
  });
  expect(selected, 'creating a project did not leave it selected').toBe(project.id);
});

test('the project is provisioned as a working copy on disk', async () => {
  project = readRun().project || project;
  const dir = projectDir(project.slug);

  // Provisioning clones and composer-installs; a minute is the documented expectation.
  const deadline = Date.now() + 180_000;
  while (Date.now() < deadline) {
    if (fs.existsSync(`${dir}/public/index.php`) && fs.existsSync(`${dir}/conf/config.ini`)) break;
    await new Promise(r => setTimeout(r, 3000));
  }

  expect(fs.existsSync(`${dir}/public/index.php`), `${dir} was never provisioned`).toBe(true);
  expect(fs.existsSync(`${dir}/controls`), 'the project has no controls/ directory').toBe(true);
});

test('a controller, a view and a permission row are all it takes to ship a page', async ({ page, watch }) => {
  project = readRun().project || project;
  watch.watchInstance(project.slug);

  const files = buildApp(project.slug);
  expect(files.length).toBe(3);

  const out = seedApp(project.slug);
  expect(out.seeded, `the seed did not run: ${out.seeded}`).toMatch(/seeded 2 widgets/);

  // Anonymous, on the project's own domain: this is what a visitor gets.
  const url = `https://${project.slug}.${env.APP_NAMESPACE}.com/widget`;
  const anon = await page.request.get(url, { headers: { Cookie: '' }, timeout: 30_000 });
  expect(anon.status(), `${url} did not serve the new page`).toBe(200);

  const html = await anon.text();
  expect(html, 'the view did not render').toContain('E2E_WIDGET_PAGE_OK');
  expect(html, 'the beans the seed stored are not on the page').toContain('Sprocket');
  expect(html, 'the second bean is missing').toContain('Flange');
  expect(html).toContain('2 widgets');
});

test('a permission-controlled route refuses an anonymous visitor', async ({ page }) => {
  project = readRun().project || project;

  // The counterpart to the test above. /widget is public because its seed SAYS so —
  // and this proves the row is what decides, not the fact that the file exists.
  //
  // Worth being precise about, because the fallback runs the other way: a route with
  // NO authcontrol row at all falls through to PUBLIC (PermissionCache::check), so the
  // row is what makes a page private, not what makes it reachable.
  const url = `https://${project.slug}.${env.APP_NAMESPACE}.com/member/settings`;
  const anon = await page.request.get(url, { headers: { Cookie: '' }, maxRedirects: 0, timeout: 30_000 });
  expect([302, 303, 401, 403], `${url} answered ${anon.status()} to an anonymous visitor`)
    .toContain(anon.status());
});

test('the task board builds against the selected project, and does not ask which', async ({ page, watch }) => {
  project = readRun().project || project;
  watch.watchInstance(project.slug);

  // Establish the sidecar session through core's SSO hop, then go to the form itself.
  await page.goto('/sidecar/app/workbench', { waitUntil: 'domcontentloaded' });
  await page.frameLocator('.sidecar-embed iframe').locator('body').waitFor({ timeout: 30_000 });
  await page.goto('https://workbench.tiknix.com/workbench/create', { waitUntil: 'domcontentloaded' });

  // No second picker. Core's Projects page is the only place a project is chosen, so a
  // chooser here could disagree with the chip in the shell — and whichever the form used
  // would silently win. Not a hidden field either: the server must not take it from the
  // request at all.
  expect(await page.locator('select[name="instance_id"]').count(),
    'the create form still offers its own instance selector').toBe(0);
  expect(await page.locator('input[name="instance_id"]').count(),
    'the create form still posts an instance_id').toBe(0);

  // It must SHOW what it will build against.
  const body = await page.locator('body').innerText();
  expect(body, 'the form does not name the project it will build against').toContain(project.slug);

  // And the task must actually land on that project. Safe to create: this task lives in
  // the disposable project's own workbench.db and goes away with it.
  const title = `e2e task ${RUN_ID}`;
  await page.locator('#title').fill(title);
  // Unanchored on purpose: bootstrap-icons draws its glyph with CSS ::before content,
  // and Chromium folds that private-use character into the accessible name — so the name
  // is "\uF64D Create Task" and /^create task/ never matches it.
  await page.getByRole('button', { name: /create task/i }).click();
  await page.waitForLoadState('domcontentloaded');

  expect(page.url(), 'creating the task did not open it').toMatch(/\/workbench\/view\?id=\d+/);
  const task = await page.locator('body').innerText();
  expect(task, 'the created task does not show the title it was given').toContain(title);
  expect(task, 'the task was filed against a different project than the one selected')
    .toContain(project.slug);
});

test('the publisher offers this project a way to ship, and the handshake reports back', async ({ page, watch }) => {
  project = readRun().project || project;
  watch.watchInstance(project.slug);

  await page.goto('/sidecar/app/publisher', { waitUntil: 'domcontentloaded' });

  // A sidecar is embedded: core renders the shell and an iframe SSO's into the plugin.
  // Everything worth asserting lives inside that frame.
  const frame = page.frameLocator('.sidecar-embed iframe');
  await expect(frame.locator('body'), 'the publisher never loaded inside its frame')
    .toContainText(/publish/i, { timeout: 30_000 });

  const body = await frame.locator('body').innerText();
  expect(body.toLowerCase(), 'the publisher does not name the project it would publish')
    .toContain(SLUG);

  // Every driver the registry offers must be on the page — that is the whole contract
  // between the registry and this UI, and it broke silently once before.
  for (const label of [/github/i, /rsync/i, /ssh/i]) {
    expect(body, `the publisher does not offer a ${label} target`).toMatch(label);
  }

  // A brand-new project has a brand-new key that no server trusts yet, so the handshake
  // is EXPECTED to be refused. What is under test is that it answers at all, and that a
  // refusal explains itself and hands over the key to authorise — a silent or crashing
  // handshake is the failure mode that matters.
  const rsync = frame.locator('.pub-target[value="rsync"]');
  await expect(rsync, 'the publisher offers no rsync target to tick').toHaveCount(1);
  if (!(await rsync.isChecked())) await rsync.check();

  for (const [field, value] of [['host', env.RSYNC_HOST], ['user', env.RSYNC_USER], ['path', env.RSYNC_PATH]]) {
    const f = frame.locator(`.pub-field[data-target="rsync"][data-field="${field}"]`);
    await expect(f, `rsync has no ${field} field`).toHaveCount(1);
    await f.fill(value);
  }

  const answered = page.waitForResponse(r => /publish\/verify/.test(r.url()), { timeout: 60_000 });
  await frame.locator('.pub-verify[data-target="rsync"]').click();
  const res = await answered;
  const json = await res.json().catch(() => null);
  expect(json, 'the handshake returned something that is not JSON').not.toBeNull();

  // A VERDICT is required. This used to also accept "this project has no broker key yet",
  // on the assumption that a new project would not have one — it does: provisioning mints
  // it before anyone sees the project. Accepting that answer meant the day provisioning
  // stopped writing conf/broker.ini, this test would have shrugged and passed.
  expect(res.status(),
    `the handshake did not return a verdict: ${json && json.message}`).toBe(200);

  // Refused is the expected verdict — no server has been told to trust this project's key
  // yet. But a refusal has to be USABLE: say why, and hand over the key to authorise.
  const verdict = json.data || {};
  const shown = (await frame.locator('#verify-rsync').innerText()).trim();
  expect(shown, 'the handshake said nothing back to the person who clicked it').not.toBe('');

  if (!verdict.ok) {
    const said = `${json.message || ''} ${shown}`;
    expect(said, 'a refused handshake did not explain itself')
      .toMatch(/key|permission|denied|refus|connect|host|authoriz|authoris/i);
    expect(json.message || '', 'a refused handshake did not hand over the public key to trust')
      .toMatch(/ssh-(ed25519|rsa)/);
  }

  // The console/network watcher would otherwise call the refusal a defect.
  watch.allowConsole(/publish\/verify|status of 4\d\d/);

  // A refused key is logged, by design.
  watch.allowServer(/publish|verify|ssh/i);
});

test('the project can be deleted from the picker, and the confirmation actually guards it', async ({ page, watch }) => {
  project = readRun().project || project;
  watch.allowServer(/delete|provision|instance/i);

  await page.goto('/projects', { waitUntil: 'domcontentloaded' });

  // The control plane itself is not deletable, and the picker must not offer to.
  const cards = await page.locator('.proj-item').count();
  const deletable = await page.locator('.proj-del').count();
  expect(deletable, 'every project offered a delete button, including ones you do not own')
    .toBeLessThan(cards);

  await page.locator(`.proj-del[data-id="${project.id}"]`).click();

  const confirmBtn = page.locator('#proj-del-confirm');
  await expect(confirmBtn, 'the delete button was live before anything was typed').toBeDisabled();

  // Nearly right is still wrong — this is the guard, so prove it holds.
  await page.locator('#proj-del-input').fill(`${project.slug}.tiknix.co`);
  await expect(confirmBtn, 'a mistyped domain unlocked the delete button').toBeDisabled();

  const domain = `${project.slug}.${env.APP_NAMESPACE}.com`;
  await page.locator('#proj-del-input').fill(domain);
  await expect(confirmBtn, 'the exact domain did not unlock the delete button').toBeEnabled();

  // The page reloads on success, so keep a copy of the answer rather than racing it.
  let json = null, status = 0, raw = '';
  await page.route('**/projects/delete', async route => {
    const response = await route.fetch({ timeout: 180_000 });
    status = response.status();
    raw = await response.text().catch(() => '');
    try { json = JSON.parse(raw); } catch { /* reported below with the body */ }
    await route.fulfill({ response, body: raw });
  });

  await confirmBtn.click();
  await expect.poll(() => status !== 0, { timeout: 180_000, message: 'delete never answered' }).toBe(true);
  expect(json && json.success,
    `deleting the project failed (HTTP ${status}): ${json ? json.message : raw.slice(0, 300)}`)
    .toBe(true);

  writeRun({ project: { ...project, deleted: true } });

  // Gone from the picker, and no longer claimed as the project you are working on.
  await page.goto('/projects', { waitUntil: 'domcontentloaded' });
  expect(await page.locator(`.proj-pick[data-id="${project.id}"]`).count(),
    'the deleted project is still in the picker').toBe(0);

  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
  const shell = await page.locator('body').innerText();
  expect(shell, 'the shell still says you are working on the deleted project')
    .not.toContain(project.slug);
});
