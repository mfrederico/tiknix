/**
 * The plan pipeline: a spec goes in, a plan comes out, IN THE RIGHT DATABASE.
 *
 * This exists because that last clause failed silently for weeks. plan-ingest.php opened
 * one connection for two different databases — core's registry and the instance's own
 * data/workbench.db — and defaulted to core's. So the planner ran, succeeded, ingested
 * eleven subtasks, and the board (which reads the per-instance file) showed nothing. From
 * the UI it looked exactly like decomposition never happened.
 *
 * The assertion that would have caught it is the one nothing else makes: read the
 * INSTANCE'S OWN sqlite file and check the plan is in there. Rendering on the board is
 * necessary too, but on its own it cannot tell you which file it came from.
 *
 * OPT-IN, for two reasons that are not the usual "it is slow":
 *   1. it spends a real frontier planner run (~3-5 minutes, real tokens)
 *   2. it needs a project that is ALREADY SIGNED IN — Claude credentials live per project
 *      (<instance>/.aibuilder/state/claude/.credentials.json), so the suite's disposable
 *      project cannot plan at all. It therefore writes to a REAL project you name, and
 *      cleans up after itself.
 *
 *     E2E_PLAN=1 E2E_PLAN_PROJECT=<slug> npx playwright test specs/05-plan-pipeline.spec.js
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('../fixtures/base');
const env = require('../lib/env');

test.describe.configure({ mode: 'serial' });
test.skip(!env.PLAN, 'plan pipeline is opt-in: E2E_PLAN=1 E2E_PLAN_PROJECT=<slug> (spends a planner run)');

// Deliberately tiny. The point is the pipeline, not the plan — a two-task spec decomposes
// in a fraction of the time and proves precisely the same path.
const SPEC = `# Ping

Add one endpoint and one view, nothing else.

- \`GET /ping\` — a controller method that returns JSON \`{"ok": true, "at": "<ISO 8601 now>"}\`.
  It needs an authcontrol row, shipped as an idempotent seed in scripts/.
- \`views/ping/index.php\` — a page that shows the word PONG and the time from that endpoint.

No migrations, no new models, no beans. Two files and a seed.`;

const projectDir = slug => path.join(env.INSTANCE_ROOT, `${slug}.${env.APP_NAMESPACE}`);
const tasksDb = slug => path.join(projectDir(slug), 'data', 'workbench.db');

/** Query the instance's OWN workbench.db. The whole point is to look at THIS file. */
function queryTasksDb(slug, sql) {
  const out = execFileSync('php', ['-r', `
    $db = new PDO("sqlite:" . $argv[1]);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ($db->query($argv[2]) as $r) { echo json_encode($r) . "\\n"; }
  `, tasksDb(slug), sql], { encoding: 'utf8', timeout: 30_000 });
  return out.trim().split('\n').filter(Boolean).map(l => JSON.parse(l));
}

let planId = 0;
let slug = '';

test('a spec decomposes into a plan, and the plan lands in the project\'s own database', async ({ page, watch }) => {
  test.setTimeout(20 * 60_000);   // a real planner run

  slug = env.PLAN_PROJECT;
  expect(slug, 'E2E_PLAN_PROJECT is not set — name the signed-in project to plan in').not.toBe('');
  watch.watchInstance(slug);
  watch.allowServer(/decompose|plan/i);

  // Credentials are per project. Without them the planner dies in one second with
  // "Not logged in", and the board sits on "Decomposing…" until the poll gives up — so
  // say so now rather than after five minutes of nothing.
  const creds = path.join(projectDir(slug), '.aibuilder', 'state', 'claude', '.credentials.json');
  expect(fs.existsSync(creds),
    `${slug} has no Claude credentials — open the Advanced Builder with it selected and run /login`).toBe(true);

  // Work on it: the task board builds against the SELECTED project and offers no other way.
  await page.goto('/projects', { waitUntil: 'domcontentloaded' });
  const card = page.locator(`.proj-item:has-text("${slug}")`).first();
  await expect(card, `no project named ${slug} on the picker`).toBeVisible({ timeout: 30_000 });
  await card.locator('.proj-pick').click();
  await page.waitForURL(/\/dashboard/, { timeout: 60_000 });

  const before = queryTasksDb(slug, 'SELECT COALESCE(MAX(id),0) AS m FROM workbenchtask')[0] || { m: 0 };
  const baseline = Number(before.m || 0);

  await page.goto('/sidecar/app/workbench', { waitUntil: 'domcontentloaded' });
  await page.frameLocator('.sidecar-embed iframe').locator('body').waitFor({ timeout: 60_000 });
  await page.goto('https://workbench.tiknix.com/workbench/create', { waitUntil: 'domcontentloaded' });

  await page.locator('#title').fill(`e2e ping ${Date.now()}`);
  await page.locator('#description').fill(SPEC);
  await page.getByRole('button', { name: /decompose into plan/i }).click();
  await page.waitForLoadState('domcontentloaded');

  // Poll the same endpoint the board's banner uses.
  const deadline = Date.now() + 15 * 60_000;
  let running = null;
  while (Date.now() < deadline) {
    const res = await page.request.get('https://workbench.tiknix.com/workbench/decomposestatus',
      { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const d = (await res.json().catch(() => ({}))).data || {};
    running = d.running;
    if (d.newest_plan_id && Number(d.newest_plan_id) > baseline && d.running === false) {
      planId = Number(d.newest_plan_id);
      break;
    }
    await page.waitForTimeout(5000);
  }

  expect(planId, `no plan appeared within 15 minutes (planner running=${running}) — ` +
    `check ${path.join(projectDir(slug), '.aibuilder', 'planner.log')}`).toBeGreaterThan(baseline);

  // THE assertion. Not "a plan exists somewhere" — a plan exists in THIS file, the one
  // the board reads. Core's db holding it instead is exactly the bug this guards.
  const rows = queryTasksDb(slug,
    `SELECT id, title, parent_task_id, plan_status FROM workbenchtask WHERE id = ${planId} OR parent_task_id = ${planId}`);
  const parent = rows.find(r => !r.parent_task_id);
  const subtasks = rows.filter(r => r.parent_task_id);

  expect(parent, `plan #${planId} is not in ${tasksDb(slug)} — it was ingested into the wrong database`).toBeTruthy();
  expect(subtasks.length, 'the plan has no subtasks').toBeGreaterThan(0);
  expect(String(parent.plan_status || ''), 'a fresh plan should be a draft').toMatch(/draft/i);

  // And it renders. Necessary, but not sufficient on its own — hence the file check above.
  await page.goto('https://workbench.tiknix.com/workbench', { waitUntil: 'domcontentloaded' });
  await expect(page.locator(`.wb-plan-approve[data-plan-id="${planId}"]`),
    'the plan is in the database but the board does not offer it').toBeVisible({ timeout: 60_000 });
});

test('the plan it made is removed again', async ({ page }) => {
  test.skip(!planId, 'nothing was created to clean up');

  // Through the UI, so the delete path is covered too. This writes to a REAL project, so
  // leaving the plan behind would be leaving litter in someone's board.
  await page.goto('https://workbench.tiknix.com/workbench', { waitUntil: 'domcontentloaded' });
  page.once('dialog', d => d.accept());
  await page.locator(`.wb-plan-delete[data-plan-id="${planId}"]`).click();
  await page.waitForTimeout(3000);

  const left = queryTasksDb(slug,
    `SELECT id FROM workbenchtask WHERE id = ${planId} OR parent_task_id = ${planId}`);
  expect(left.length, `plan #${planId} and its subtasks are still in ${tasksDb(slug)}`).toBe(0);
});
