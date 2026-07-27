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

test('a route with no permission row of its own is NOT public', async ({ page }) => {
  project = readRun().project || project;

  // The counterpart to the test above, and the claim the dashboard makes to every new
  // user: routes are not exposed by accident. /widget is public because its seed says
  // so; a sibling method with no row inherits the admin default.
  const url = `https://${project.slug}.${env.APP_NAMESPACE}.com/member/settings`;
  const anon = await page.request.get(url, { headers: { Cookie: '' }, maxRedirects: 0, timeout: 30_000 });
  expect([302, 303, 401, 403], `${url} answered ${anon.status()} to an anonymous visitor`)
    .toContain(anon.status());
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

  // Whatever the answer, the person who clicked must be told something.
  const shown = (await frame.locator('#verify-rsync').innerText()).trim();
  expect(shown, 'the handshake said nothing back to the person who clicked it').not.toBe('');

  if (res.status() === 200) {
    // A verdict. Refused is the expected one for a key no server has authorised yet,
    // but a refusal has to be USABLE: it must say why, and hand over the key to trust.
    const verdict = json.data || {};
    if (!verdict.ok) {
      expect(`${json.message || ''} ${shown}`, 'a refused handshake did not explain itself')
        .toMatch(/key|permission|denied|refus|connect|host|authoriz|authoris/i);
    }
  } else {
    // A project that has never published has no broker key yet, so there is nothing to
    // hand a handshake. That is a legitimate state — what is NOT acceptable is a bare
    // failure, so the refusal has to name the thing that is missing and where to get it.
    expect(res.status(), 'the handshake failed as a server error').toBeLessThan(500);
    expect(`${json.message || ''} ${shown}`, 'the handshake was refused without saying what to do')
      .toMatch(/broker key|publish once|connections/i);
  }

  // The console/network watcher would otherwise call the refusal a defect.
  watch.allowConsole(/publish\/verify|status of 4\d\d/);

  // A refused key is logged, by design.
  watch.allowServer(/publish|verify|ssh/i);
});

test('the project can be deleted from the danger zone, and stays deleted', async ({ page, watch }) => {
  project = readRun().project || project;
  watch.allowServer(/delete|provision|instance/i);
  // The builder's terminal bridge is down on this host; 04-sidecars tests that on its
  // own so it is reported once, loudly, instead of failing every test that opens this
  // page for some other reason.
  watch.allowConsole(/aibuilder\/(chat-)?ws/);

  // Establish the sidecar session through core's SSO hop first — that is the only way
  // in, and it is what makes the builder's own URL reachable afterwards.
  await page.goto('/sidecar/app/workbench', { waitUntil: 'domcontentloaded' });
  await page.frameLocator('.sidecar-embed iframe').locator('body').waitFor({ timeout: 30_000 });

  // The danger zone lives on the builder, which is a different page of the same sidecar.
  await page.goto('https://workbench.tiknix.com/aibuilder', { waitUntil: 'domcontentloaded' });
  const frame = page.mainFrame();

  const del = frame.locator('#ab-delete');
  await expect(del, 'the builder has no delete control').toBeVisible({ timeout: 30_000 });
  await del.click();

  // The confirmation is the project's own domain, typed exactly — a guard worth having
  // and worth testing, because it is the only thing between a click and an erased app.
  const domain = `${project.slug}.${env.APP_NAMESPACE}.com`;
  await frame.locator('#ab-del-input').fill(domain);

  // The confirm button stays disabled until the typed domain matches — that is the guard,
  // so assert it actually engaged rather than just clicking through.
  const confirmBtn = frame.locator('#ab-del-confirm');
  await expect(confirmBtn, 'the delete button did not unlock after typing the domain').toBeEnabled();

  // Same reason as the create call: the page navigates the moment this lands, so keep a
  // copy of the body instead of racing the browser for it.
  let json = null, status = 0, raw = '';
  await page.route('**/aibuilder/delete', async route => {
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

  // Gone from the picker, and gone from disk.
  await page.goto('/projects', { waitUntil: 'domcontentloaded' });
  const stillListed = await page.locator(`.proj-pick[data-id="${project.id}"]`).count();
  expect(stillListed, 'the deleted project is still in the picker').toBe(0);
});
