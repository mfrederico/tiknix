<?php
/**
 * PHPUnit bootstrap — Composer's autoloader plus this install's concept (plugin) autoloader.
 *
 * The suites run on a scratch database, so the plugins this install has switched on have to
 * come from somewhere else: concepts.lock, a file in the repository, which is exactly what
 * Concepts::boot() reads. Before the lock file, every test of app code that used a plugin
 * had to require_once the plugin's files by hand, and the CLAUDE.md drift test composed
 * with no plugins and failed on every install that had one.
 */
// Git's own variables must not reach the tests, however the suite was started. Tests build
// throwaway repositories and run `git -C <tmp> …`, which obeys GIT_DIR / GIT_INDEX_FILE over -C:
// with those set (git sets them for a hook; a person may set them to imitate one) the tests'
// `git add` and `git commit` land in THIS repository. tests/run.sh unsets them for the commit
// hook, but phpunit run directly had no such guard — on 2026-10-05 that staged a bin/claude entry
// into core's index and the next commit recorded it. Unset here, where every run passes.
foreach (['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_PREFIX', 'GIT_COMMON_DIR', 'GIT_OBJECT_DIRECTORY', 'GIT_ALTERNATE_OBJECT_DIRECTORIES', 'GIT_NAMESPACE'] as $__v) {
    putenv($__v); unset($_ENV[$__v], $_SERVER[$__v]);
}
unset($__v);
require_once dirname(__DIR__) . '/vendor/autoload.php';
\app\Concepts::boot(dirname(__DIR__));
