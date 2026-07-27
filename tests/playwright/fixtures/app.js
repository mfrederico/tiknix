/**
 * The small app the suite builds inside its disposable project.
 *
 * It is deliberately the smallest thing that exercises the whole contract the dashboard
 * advertises to a new user: a controller is a URL, a view renders it, storing a bean
 * creates its table, and a route is reachable only once a permission row says so. If
 * any one of those four stops being true, this fails — which is the point.
 *
 * The files are written straight into the project's working copy, because that IS the
 * developer path: a Tiknix project is a git checkout on this host, and editing it is
 * what the builder, an IDE, or a person with an editor all end up doing.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const env = require('./../lib/env');

const CONTROLLER = `<?php
/**
 * Written by the e2e suite. Everything here is disposable and goes away with the
 * project it lives in.
 */
namespace app;

use app\\Bean;

class Widget extends BaseControls\\Control {

    public function index($params = []): void {
        $widgets = Bean::findAll('e2ewidget', ' ORDER BY id ');
        $this->render('widget/index', [
            'title'   => 'Widgets',
            'widgets' => $widgets,
        ]);
    }
}
`;

const VIEW = `<div class="container py-4">
  <h1>Widgets</h1>
  <p id="e2e-marker">E2E_WIDGET_PAGE_OK</p>
  <ul>
    <?php foreach ($widgets as $w): ?>
      <li class="e2e-widget"><?= htmlspecialchars((string) $w->name) ?> — <?= (int) $w->qty ?></li>
    <?php endforeach; ?>
  </ul>
  <p id="e2e-count"><?= count($widgets) ?> widgets</p>
</div>
`;

/**
 * Seed: two rows and the permission that makes the page public.
 *
 * Both halves matter. RedBean creates the table on first store, so there is no
 * migration; and without the authcontrol row the route inherits the ADMIN default and a
 * signed-out visitor is bounced to a login page.
 */
const SEED = `<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("cli only\\n"); }

require_once __DIR__ . '/../bootstrap.php';
new app\\Bootstrap('conf/config.ini');

use app\\Bean;
use RedBeanPHP\\R;

foreach ([['Sprocket', 3], ['Flange', 7]] as [$name, $qty]) {
    $w = Bean::findOne('e2ewidget', 'name = ?', [$name]) ?: Bean::dispense('e2ewidget');
    $w->name = $name;
    $w->qty  = $qty;
    Bean::store($w);
}

$row = Bean::findOne('authcontrol', 'LOWER(control) = ? AND LOWER(method) = ?', ['widget', '*']);
if (!$row) {
    $row = Bean::dispense('authcontrol');
    $row->control     = 'widget';
    $row->method      = '*';
    $row->description = 'e2e suite: public widget page';
    $row->validcount  = 0;
    $row->createdAt   = date('Y-m-d H:i:s');
}
$row->level = LEVELS['PUBLIC'];
Bean::store($row);

echo "seeded " . R::count('e2ewidget') . " widgets\\n";
`;

function projectDir(slug) {
  return path.join(env.INSTANCE_ROOT, `${slug}.${env.APP_NAMESPACE}`);
}

/** Write the app into the project. Returns the files created, newest-first. */
function buildApp(slug) {
  const dir = projectDir(slug);
  if (!fs.existsSync(path.join(dir, 'public', 'index.php'))) {
    throw new Error(`${dir} does not look like a provisioned project`);
  }

  const files = {
    'controls/Widget.php': CONTROLLER,
    'views/widget/index.php': VIEW,
    'scripts/e2e-seed.php': SEED,
  };
  for (const [rel, body] of Object.entries(files)) {
    const target = path.join(dir, rel);
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.writeFileSync(target, body);
  }
  return Object.keys(files);
}

/**
 * Run the seed, then refresh the permission cache — the same two commands the project's
 * own docs tell a developer to run after touching authcontrol.
 */
function seedApp(slug) {
  const dir = projectDir(slug);
  const run = (script) =>
    execFileSync('php', [script], { cwd: dir, encoding: 'utf8', timeout: 60_000 });
  const seeded = run('scripts/e2e-seed.php');
  const cache = run('scripts/resetcache.php');
  return { seeded: seeded.trim(), cache: cache.trim().split('\n').slice(-1)[0] };
}

module.exports = { buildApp, seedApp, projectDir };
