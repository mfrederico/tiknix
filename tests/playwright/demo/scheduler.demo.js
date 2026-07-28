/**
 * THE DEMO: Tiknix builds a shift-scheduling tool, on camera, from a written spec.
 *
 * Every beat is a real action against the live control plane — a project really is
 * provisioned, the planner really decomposes the spec against that project's reuse
 * inventory, and the orchestrator really builds the subtasks. Nothing is staged, which
 * is the only reason the video is worth making.
 *
 * The arc:
 *   1. make a project called "scheduler"        — the real /projects create path
 *   2. hand the spec to the task board          — paste the goal, hit Decompose
 *   3. watch it decompose                       — the planner reads the codebase first
 *   4. read the plan                            — subtasks, each one a spec
 *   5. approve, then build                      — the orchestrator takes over
 *   6. watch it build                           — tasks move; the app appears
 *
 * It is NOT a test. It asserts only enough to fail fast and loudly if a beat does not
 * happen, because a recording that silently drifts off-script wastes the whole session.
 * The project it makes is KEPT — you just watched it get built.
 *
 * Pacing knobs, since this is footage:
 *   DEMO_SLOWMO=350     ms between actions (playwright.demo.config.js)
 *   DEMO_BEAT=2500      ms to hold on a screen so a viewer can read it
 *   DEMO_BUILD_MINUTES  how long to keep filming the build (default 30)
 */
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const env = require('../lib/env');
const { deleteInstance } = require('../lib/provision');
const { readRun } = require('../lib/global-setup');

const BEAT = Number(process.env.DEMO_BEAT || 2500);
const BUILD_MINUTES = Number(process.env.DEMO_BUILD_MINUTES || 30);
const PROJECT_NAME = process.env.DEMO_PROJECT || 'scheduler';
// Reuse an existing project instead of creating one. Needed because credentials are
// per-project (see the preflight below): a fresh project cannot plan until someone has
// logged in inside ITS jail, so re-takes point at the project already signed in.
const REUSE = process.env.DEMO_USE_PROJECT || '';
// Shoot the take in SEGMENTS. DEMO_PLAN_ID skips create+decompose and opens on an
// existing plan, so the approve→build half can be re-shot without spending another
// planner run — and two clips cut together, since both open on the same board at the
// same size. Without it the run does the whole arc.
const RESUME_PLAN = Number(process.env.DEMO_PLAN_ID || 0);
// Segment one ends the moment the planner is off and running. Nobody wants to watch a
// spinner for five minutes, and the plan landing is segment two's opening shot anyway.
// The planner is NOT stopped — it keeps working off camera, and the plan it produces is
// exactly what the next segment resumes from. Nothing filmed, nothing wasted.
const STOP_AT_DECOMPOSE = process.env.DEMO_STOP_AT_DECOMPOSE === '1';
// Which spec to hand the planner. The markdown drop reads the file and fills Title and
// Description from it, so the goal document is the only thing that decides what gets
// built — swap the file, not the script.
const GOAL_FILE = process.env.DEMO_GOAL || 'scheduler-goal.md';
const GOAL_PATH = path.join(__dirname, GOAL_FILE);

/** Narrate to the terminal so you can follow the take without watching the browser. */
const beat = (n, what) => console.log(`\n[demo ${n}] ${what}`);

/** Hold on a screen long enough for a viewer to actually read it. */
const hold = (page, ms = BEAT) => page.waitForTimeout(ms);

test('Tiknix builds a shift scheduler from a spec', async ({ page }) => {
  let slug = '';
  let planId = 0;

  // ---- 1. the project ---------------------------------------------------------
  let created = null;

  if (REUSE) {
    beat(1, `working on the existing project "${REUSE}"`);
    await page.goto('/projects', { waitUntil: 'domcontentloaded' });
    await hold(page);
    const card = page.locator(`.proj-item:has-text("${REUSE}")`).first();
    await expect(card, `no project on the picker matches "${REUSE}"`).toBeVisible({ timeout: 30_000 });
    await card.locator('.proj-pick').click();
    slug = REUSE;
  } else {
    beat(1, `creating the project "${PROJECT_NAME}"`);
    await page.goto('/projects', { waitUntil: 'domcontentloaded' });
    await hold(page);

    await page.route('**/projects/create', async route => {
      const response = await route.fetch({ timeout: 300_000 });
      created = await response.json().catch(() => null);
      await route.fulfill({ response });
    });

    await page.getByRole('button', { name: 'New project' }).click();
    await page.locator('#proj-new-slug').fill(PROJECT_NAME);
    await hold(page, 1200);
    await page.getByRole('button', { name: /create/i }).click();

    await expect.poll(() => created !== null, { timeout: 300_000, message: 'the project was never created' }).toBe(true);
    expect(created.success, `creating the project failed: ${created && created.message}`).toBe(true);
    slug = String(created.data.slug);
    console.log(`        -> ${slug} (id ${created.data.id}), provisioning`);
  }

  await page.waitForURL(/\/dashboard/, { timeout: 120_000 });
  await hold(page);

  // The working copy has to exist before the planner can read it — that inventory is
  // what the plan is grounded on, so this wait is load-bearing, not cosmetic.
  const dir = path.join(env.INSTANCE_ROOT, `${slug}.${env.APP_NAMESPACE}`);
  const deadline = Date.now() + 300_000;
  while (Date.now() < deadline && !fs.existsSync(`${dir}/public/index.php`)) {
    await page.waitForTimeout(3000);
  }
  expect(fs.existsSync(`${dir}/public/index.php`), `${dir} was never provisioned`).toBe(true);
  console.log('        -> provisioned');

  // PREFLIGHT. Claude credentials are stored PER PROJECT
  // (<instance>/.aibuilder/state/claude/.credentials.json — jail-run.sh binds that dir as
  // the agent's ~/.claude), so a brand-new project cannot plan until someone has logged
  // in inside ITS jail. Without this check the planner starts, dies in one second with
  // "Not logged in · Please run /login", and the board sits on "Decomposing…" until the
  // poll gives up twenty minutes later — which is exactly how this was found. Fail here
  // instead, and say what to do.
  const creds = path.join(dir, '.aibuilder', 'state', 'claude', '.credentials.json');
  expect(fs.existsSync(creds),
    `${slug} has no Claude credentials, so the planner cannot run.\n` +
    `  Open the Advanced Builder with this project selected and run /login in its terminal,\n` +
    `  then re-run with DEMO_USE_PROJECT=${slug} to reuse it.\n` +
    `  (expected: ${creds})`).toBe(true);
  console.log('        -> credentials present');

  if (RESUME_PLAN) {
    // Segment two: the plan already exists. Open the board and carry on from there.
    beat(2, `resuming at plan #${RESUME_PLAN} — approve and build`);
    planId = RESUME_PLAN;
    await page.goto('https://workbench.tiknix.com/workbench', { waitUntil: 'domcontentloaded' });
    await hold(page, BEAT * 2);
    await expect(page.locator(`.wb-plan-approve[data-plan-id="${planId}"], .wb-plan-build[data-plan-id="${planId}"]`).first(),
      `plan #${planId} is not on the board — is it in this project, and already approved?`)
      .toBeVisible({ timeout: 60_000 });
  } else {

  // ---- 2. hand it the spec --------------------------------------------------
  beat(2, 'opening the task board and pasting the spec');
  await page.goto('/sidecar/app/workbench', { waitUntil: 'domcontentloaded' });
  await page.frameLocator('.sidecar-embed iframe').locator('body').waitFor({ timeout: 60_000 });
  await hold(page);

  await page.goto('https://workbench.tiknix.com/workbench/create', { waitUntil: 'domcontentloaded' });

  // The form does not ask which project — it builds against the one you selected, and
  // creating it selected it. Worth a beat on camera: that is the whole affinity story.
  const body = await page.locator('body').innerText();
  expect(body, 'the create form is not pointed at the project we just made').toContain(slug);
  await hold(page);

  // DROP THE SPEC IN, rather than typing it: the form has a markdown drop zone that reads
  // the file and fills Title and Description itself. It films better than a textarea
  // filling by magic, and it exercises the feature a customer would actually use.
  await page.locator('#mdFile').setInputFiles(GOAL_PATH);
  await expect(page.locator('#description'), 'the dropped markdown did not load into the form')
    .not.toBeEmpty({ timeout: 15_000 });
  await hold(page, BEAT * 2);

  if (process.env.DEMO_REHEARSE && REUSE) {
    console.log('\n[demo] REHEARSAL on a REUSED project — stopping before Decompose, deleting nothing.');
    return;
  }

  if (process.env.DEMO_REHEARSE) {
    // Everything up to here is what a real take repeats; stopping now costs a project
    // and a minute instead of a planner run and half an hour.
    console.log('\n[demo] REHEARSAL — stopping before Decompose and cleaning up.');
    const gone = await deleteInstance({ id: Number(created.data.id), slug });
    console.log(`[demo] rehearsal project removed: ${gone.ok ? 'yes' : 'NO — ' + gone.error}`);
    try {
      const tomb = path.join(env.INSTANCE_ROOT, `${slug}.${env.APP_NAMESPACE}`);
      if (fs.existsSync(tomb)) fs.rmSync(tomb, { recursive: true, force: true });
    } catch { /* leave it; the message above says what to clean */ }

    // Put the selection back. Creating a project selects it, so deleting it leaves the
    // account on NOTHING — and the sidecars vanish from the nav, since a plugin acts on
    // the selected project and hides until there is one. Looks exactly like the plugins
    // got switched off. A real take does not need this (it keeps its project, selected);
    // a rehearsal must clean up after itself.
    const prior = readRun().priorProjectId;
    if (prior) {
      await page.goto('/projects', { waitUntil: 'domcontentloaded' });
      const back = await page.evaluate(async (id) => {
        const btn = document.querySelector(`.proj-pick[data-id="${id}"]`);
        if (!btn) return 'that project is gone';
        if (/continue/i.test(btn.innerText)) return 'already selected';
        const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
        const r = await fetch('/projects/select', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
          body: new URLSearchParams({ csrf_token: csrf, id: String(id) }).toString(),
        });
        const j = await r.json().catch(() => null);
        return j && j.success ? 'restored' : `failed: ${(j && j.message) || r.status}`;
      }, prior);
      console.log(`[demo] project selection -> ${back} (id ${prior})`);
    }
    return;
  }

  // ---- 3. decompose ---------------------------------------------------------
  beat(3, 'decomposing the spec into a plan (the planner reads the codebase first)');
  await page.getByRole('button', { name: /decompose into plan/i }).click();
  await page.waitForLoadState('domcontentloaded');
  await hold(page);

  if (STOP_AT_DECOMPOSE) {
    // The banner is the shot: the planner is away, grounding itself in the codebase.
    await expect(page.locator('#wbDecomposeBanner'), 'the decomposing banner never appeared')
      .toBeVisible({ timeout: 30_000 });
    await hold(page, BEAT * 2);
    console.log('\n[demo] segment one ends here. The planner keeps running off camera —');
    console.log('[demo] when it lands, film the rest with DEMO_PLAN_ID=<its id>.');
    return;
  }

  // The board shows a live "decomposing…" banner for the selected project. Filming it is
  // the point: this is a frontier model reading the instance's reuse inventory and
  // deciding what to REUSE, EXTEND or build NEW.
  const decomposeDeadline = Date.now() + 20 * 60_000;
  while (Date.now() < decomposeDeadline) {
    const res = await page.request.get('https://workbench.tiknix.com/workbench/decomposestatus',
      { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const j = await res.json().catch(() => null);
    const d = (j && j.data) || {};
    if (d.newest_plan_id) planId = Number(d.newest_plan_id);
    if (planId && d.running === false) break;
    process.stdout.write(d.running ? '.' : '·');
    await page.waitForTimeout(5000);
  }
  console.log('');
  expect(planId, 'the planner produced no plan within 20 minutes').toBeGreaterThan(0);
  console.log(`        -> plan #${planId}`);
  }

  // ---- 4. read the plan -----------------------------------------------------
  beat(4, 'the plan: each subtask is a spec, not a suggestion');
  await page.goto('https://workbench.tiknix.com/workbench', { waitUntil: 'domcontentloaded' });
  await hold(page, BEAT * 2);

  const approve = page.locator(`.wb-plan-approve[data-plan-id="${planId}"]`);
  await expect(approve, 'the plan did not offer an Approve button').toBeVisible({ timeout: 60_000 });

  // ---- 5. approve, then build ----------------------------------------------
  beat(5, 'approving the plan, then building it');
  await approve.click();
  await page.waitForTimeout(3000);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await hold(page);

  const build = page.locator(`.wb-plan-build[data-plan-id="${planId}"]`);
  await expect(build, 'the approved plan did not offer a Build button').toBeVisible({ timeout: 60_000 });
  await build.click();
  await page.waitForTimeout(3000);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await hold(page);

  // ---- 6. watch it build ----------------------------------------------------
  beat(6, `filming the build for up to ${BUILD_MINUTES} minutes`);
  const buildDeadline = Date.now() + BUILD_MINUTES * 60_000;
  let lastSeen = '';
  while (Date.now() < buildDeadline) {
    const res = await page.request.get(
      `https://workbench.tiknix.com/workbench/planprogress?plan_id=${planId}`,
      { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).catch(() => null);
    const j = res ? await res.json().catch(() => null) : null;
    const d = (j && j.data) || {};

    const done = (d.tasks || []).filter(t => /done|complete|merged/i.test(t.status || '')).length;
    const line = `${d.plan_status || '?'} — ${done}/${(d.tasks || []).length} subtasks`;
    if (line !== lastSeen) { console.log(`        ${line}`); lastSeen = line; }

    if (/complete|done|finished/i.test(String(d.plan_status || ''))) break;

    // Keep the board on screen and refreshed — this is the footage.
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(15_000);
  }

  // ---- the payoff -----------------------------------------------------------
  beat(7, 'the app it built, live on its own domain');
  await page.goto(`https://${slug}.${env.APP_NAMESPACE}.com/`, { waitUntil: 'domcontentloaded' });
  await hold(page, BEAT * 2);

  console.log(`\n[demo] project kept: ${slug} — https://${slug}.${env.APP_NAMESPACE}.com`);
  console.log('[demo] video: tests/playwright/test-results/demo/**/video.webm\n');
});
