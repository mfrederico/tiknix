<?php
/**
 * Model_Instance::isProvisionedInstance — a directory is an instance by structure (a clone
 * of core on an instance/ branch), and an ALIAS of one is not one.
 *
 *   instance   a clone whose origin is core and whose branch is instance/<slug> → true
 *   alias      a symlink to that clone → false, even though everything behind it is real
 *              (start.tiknix → start-201e11.tiknix made every *.tiknix sweep visit the same
 *              project twice under two slugs; the reaper marked a building plan stalled)
 *   not one    a plain git repo on main, or no .git at all → false
 *
 * Throwaway git repositories under the system temp dir; origin points at this core.
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

class InstanceDirAliasTest extends ConceptsTestCase {

    private function repo(string $name, string $branch, bool $originIsCore = true): string {
        $d = $this->root . '/' . $name;
        mkdir($d, 0700, true);
        // "Core" is the control plane's checkout, not whichever tree runs this test: an
        // instance clone runs the same suite, and its own path is not what origin must be.
        $core = \Model_Instance::ROOT . '/tiknix';
        if (!is_dir($core . '/.git')) $this->markTestSkipped("no control plane at {$core} on this host");
        $origin = $originIsCore ? $core : $this->root;
        $git = 'git -C ' . escapeshellarg($d);
        exec("$git init -q && $git remote add origin " . escapeshellarg($origin)
           . " && $git checkout -q -b " . escapeshellarg($branch)
           . " && $git -c user.name=t -c user.email=t@example.com commit -q --allow-empty -m init");
        return $d;
    }

    public function testACloneOnAnInstanceBranchIsAnInstanceButItsAliasIsNot(): void {
        $inst = $this->repo('demo-ab12cd.tiknix', 'instance/demo-ab12cd');
        $this->assertTrue(\Model_Instance::isProvisionedInstance($inst));
        $alias = $this->root . '/demo.tiknix';
        symlink($inst, $alias);
        $this->assertFalse(\Model_Instance::isProvisionedInstance($alias), 'an alias is not the instance');
        $this->assertFalse(\Model_Instance::isProvisionedInstance($alias . '/'), 'trailing slash makes no difference');
    }

    public function testOtherRepositoriesAreNotInstances(): void {
        $this->assertFalse(\Model_Instance::isProvisionedInstance($this->repo('main.tiknix', 'main')), 'not an instance branch');
        $this->assertFalse(\Model_Instance::isProvisionedInstance($this->repo('elsewhere.tiknix', 'instance/x', false)), 'origin is not core');
        mkdir($this->root . '/plain.tiknix');
        $this->assertFalse(\Model_Instance::isProvisionedInstance($this->root . '/plain.tiknix'), 'no .git');
    }
}
