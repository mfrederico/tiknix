<?php
/**
 * ConceptManifest — requires.connectors as ROLES (CONNECTOR-CATALOG-PLAN.md §3.3).
 *
 *   bare key     "stripe" still parses: role 'stripe', types ['stripe'] — every published manifest stays valid
 *   object       {role, types, label, scope, entity, optional, inherit} parsed and defaulted
 *   inherit      defaults true, except 'payments' (money never falls through to another site)
 *   refusals     bad role name, empty types, unknown scope, entity scope without an entity, a role twice
 *   flat list    requiresConnectors is the union of the roles' types
 */

namespace tests\unit;

require_once __DIR__ . '/ConceptsTestCase.php';

use app\ConceptException;
use app\ConceptManifest;

class ConceptManifestRolesTest extends ConceptsTestCase {

    private function load(array $connectors): ConceptManifest {
        $n = $this->uniq('r');
        $dir = $this->concept($n, ['requires' => ['connectors' => $connectors]]);
        return ConceptManifest::load($dir, $n);
    }

    public function testBareKeysAndObjectsBothParse(): void {
        $m = $this->load([
            'serpapi',
            ['role' => 'payments', 'types' => ['stripe'], 'label' => 'Takes payment'],
            ['role' => 'mail', 'types' => ['microsoft', 'mailgun'], 'scope' => 'entity', 'entity' => 'emailaccount', 'optional' => true],
        ]);
        $this->assertSame(['serpapi', 'stripe', 'microsoft', 'mailgun'], $m->requiresConnectors);
        $roles = array_column($m->connectorRoles, null, 'role');
        $this->assertSame(['serpapi'], $roles['serpapi']['types']);
        $this->assertSame('Serpapi', $roles['serpapi']['label']);
        $this->assertTrue($roles['serpapi']['inherit']);
        $this->assertSame(['install', '', false], [$roles['serpapi']['scope'], $roles['serpapi']['entity'], $roles['serpapi']['optional']]);
        $this->assertFalse($roles['payments']['inherit'], 'payments never inherits by default');
        $this->assertSame('Takes payment', $roles['payments']['label']);
        $this->assertSame(['entity', 'emailaccount', true, true], [$roles['mail']['scope'], $roles['mail']['entity'], $roles['mail']['optional'], $roles['mail']['inherit']]);
        $explicit = $this->load([['role' => 'payments', 'types' => ['stripe'], 'inherit' => true]]);
        $this->assertTrue($explicit->connectorRoles[0]['inherit'], 'a manifest may say so explicitly');
    }

    public function testRefusals(): void {
        foreach ([
            [[['role' => 'Pay', 'types' => ['stripe']]], 'role must be'],
            [[['role' => 'payments', 'types' => []]], 'at least one connector'],
            [[['role' => 'payments']], 'at least one connector'],
            [[['role' => 'payments', 'types' => ['stripe'], 'scope' => 'global']], "scope must be"],
            [[['role' => 'mail', 'types' => ['microsoft'], 'scope' => 'entity']], 'names no entity'],
            [['stripe', ['role' => 'stripe', 'types' => ['stripe']]], 'twice'],
            [[['role' => 'x', 'types' => ['Not Valid']]], 'invalid entry'],
            [['role' => 'x'], 'must be a JSON list'],
        ] as [$connectors, $msg]) {
            try { $this->load($connectors); $this->fail('accepted ' . json_encode($connectors)); }
            catch (ConceptException $e) { $this->assertStringContainsString($msg, $e->getMessage(), json_encode($connectors)); }
        }
    }
}
