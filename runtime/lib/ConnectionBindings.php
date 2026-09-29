<?php
/**
 * ConnectionBindings — a concept asks for a connection by ROLE; the install has bound each role
 * to one of its connections, per site. CONNECTOR-CATALOG-PLAN.md §4.
 *
 *   ConnectionBindings::for('storefront', 'payments')        → the connection bean (read-only)
 *   ConnectionBindings::token('storefront', 'payments')      → its decrypted secret, this install only
 *   ConnectionBindings::candidates('storefront', 'payments') → what a picker offers for the role
 *   ConnectionBindings::bind('storefront', 'payments', $connectionId, 'member:3')
 *   ConnectionBindings::unbound('storefront')                → the roles nothing usable answers
 *
 * (Not `Connections`: that name is the connections HUB controller, app\Connections.)
 *
 * Resolution order in for(), and no other: the current site's entity binding → the current
 * site's binding → the install-wide binding IF the role inherits (payments never does: money
 * must not fall through to another franchise's account) → exactly one live candidate on this
 * install (bound automatically, and said so in the log) → UnboundRoleException naming the
 * concept, the role, the candidates and the page that binds them → MissingConnectorException
 * naming what to connect or install. Nothing is picked "for now".
 *
 * Concept code never names a provider: `for('payments')`, not ConnectionStore::for('stripe').
 */

namespace app;

class UnboundRoleException extends \RuntimeException {}
class MissingConnectorException extends \RuntimeException {}

class ConnectionBindings {

    /**
     * The role declaration from the concept's manifest, normalised:
     * [role, types[], label, scope, entity, optional, inherit].
     *
     * @throws \InvalidArgumentException when the concept is not installed or has no such role
     */
    /** @var callable|null test seam: (string $concept) => ConceptManifest; null = the runtime's installed concepts */
    private static $manifests = null;

    public static function useManifests(?callable $lookup): void { self::$manifests = $lookup; }

    /**
     * Core's own roles — core is code, not a concept, so its needs are declared here, in the
     * same shape a manifest's requires.connectors parses to. `mail` is what lib/Mailer, the
     * comms inbox (NotifyService) and /webhook/mailgun send through (CONNECTOR-CATALOG-PLAN.md
     * decision 9). An app's own roles go in its root concept.json and resolve as 'root'.
     */
    public const CORE = 'core';
    public const CORE_ROLES = [
        ['role' => 'mail', 'types' => ['mailgun'], 'label' => 'Sends email', 'scope' => 'install', 'entity' => '', 'optional' => true, 'inherit' => true],
    ];

    public static function role(string $concept, string $role): array {
        if ($concept === self::CORE) {
            $roles = self::CORE_ROLES;
        } elseif ($concept === ConceptManifest::ROOT) {
            $rm = Concepts::instance()->rootManifest();
            if ($rm === null) throw new \InvalidArgumentException("This install has no root concept.json, so it declares no connector role '{$role}'.");
            $roles = $rm->connectorRoles;
        } else {
            $m = self::$manifests ? (self::$manifests)($concept) : Concepts::instance()->manifest($concept);
            $roles = $m->connectorRoles;
        }
        foreach ($roles as $r) if ($r['role'] === $role) return $r;
        throw new \InvalidArgumentException("Concept '{$concept}' declares no connector role '{$role}' (requires.connectors).");
    }

    public static function for(string $concept, string $role, ?array $entity = null): \RedBeanPHP\OODBBean {
        $r = self::role($concept, $role);
        $site = Sites::current();

        foreach (self::bindingChain($concept, $r, (int) $site->id, $entity) as $b) {
            $conn = ConnectionStore::byId((int) $b->connectionRef);
            if ($conn && (int) $conn->enabled === 1 && (string) ($conn->revokedAt ?? '') === '' && in_array((string) $conn->connectorType, $r['types'], true)) {
                return $conn;
            }
            // Bound to something that is gone or disabled: say which, do not fall through to
            // another connection — that would be the quiet swap the whole design forbids.
            throw new UnboundRoleException(sprintf(
                "%s needs a %s connection for site '%s': it was bound to '%s' (#%d), which is no longer usable. Rebind it under Plugins → %s → %s.",
                ucfirst($concept), $role, $site->slug, (string) $b->aliasSnapshot, (int) $b->connectionRef, ucfirst($concept), $r['label']));
        }

        $cands = ConnectionStore::candidates($r['types']);
        if (count($cands) === 1) {
            // One candidate binds itself — but not across a franchise line. A role that does
            // not inherit (payments) is auto-bound only where the install has one site, or on
            // the default site: Denver with no Stripe of its own must not be handed the
            // install's only Stripe on the assumption that it is Denver's. An inheriting role
            // is bound install-wide (site 0), so every site shares it.
            $isDefault = $site->slug === Sites::DEFAULT_SLUG;
            if (!$r['inherit'] && !$isDefault && Sites::multi() && $entity === null) {
                throw new UnboundRoleException(sprintf(
                    "%s needs a %s connection for site '%s' and none is bound to it. The install's only candidate, '%s', is not assumed to be %s's — bind it under Plugins → %s → %s if it is.",
                    ucfirst($concept), $role, $site->slug, $cands[0]['alias'], $site->name, ucfirst($concept), $r['label']));
            }
            $bindSite = ($r['inherit'] && $entity === null) ? 0 : (int) $site->id;
            self::bind($concept, $role, $cands[0]['id'], 'auto', $bindSite, $entity);
            \Flight::get('log')?->info('ConnectionBindings: auto-bound', ['concept' => $concept, 'role' => $role, 'site' => $bindSite === 0 ? '(install-wide)' : $site->slug, 'alias' => $cands[0]['alias']]);
            return ConnectionStore::byId($cands[0]['id']);
        }
        if (count($cands) > 1) {
            $names = implode(', ', array_map(fn($c) => $c['alias'] . ' (' . $c['type'] . ')', $cands));
            throw new UnboundRoleException(sprintf(
                "%s needs a %s connection for site '%s' and this install has %d candidates (%s) — choose one under Plugins → %s → %s.",
                ucfirst($concept), $role, $site->slug, count($cands), $names, ucfirst($concept), $r['label']));
        }
        $types = implode(' or ', $r['types']);
        $missingDefs = array_values(array_filter($r['types'], fn($t) => \app\services\connectors\ConnectorRegistry::get($t) === null));
        if ($missingDefs) {
            throw new MissingConnectorException(sprintf(
                "%s needs a %s connection (%s), and this install has no such connector: install it with `php scripts/clitool.php --connector-install=%s`.",
                ucfirst($concept), $role, $types, $missingDefs[0]));
        }
        throw new MissingConnectorException(sprintf(
            "%s needs a %s connection (%s) and this install has none — connect one under Connections → %s.",
            ucfirst($concept), $role, $types, ucfirst($r['types'][0])));
    }

    /** The bound connection's decrypted secret. Only this install can answer (its own key). */
    public static function token(string $concept, string $role, ?array $entity = null): string {
        return ConnectionStore::ownToken(self::for($concept, $role, $entity));
    }

    /** What a picker offers for the role: the install's live connections of the role's types. */
    public static function candidates(string $concept, string $role): array {
        return ConnectionStore::candidates(self::role($concept, $role)['types']);
    }

    /**
     * Bind a role to a connection for a site (or an entity within it). Refuses a connection
     * of a type the role does not accept, and a connection that is not on this install.
     */
    public static function bind(string $concept, string $role, int $connectionId, string $by, ?int $siteId = null, ?array $entity = null): \RedBeanPHP\OODBBean {
        $r = self::role($concept, $role);
        $conn = ConnectionStore::byId($connectionId);
        if (!$conn) throw new \InvalidArgumentException("No connection #{$connectionId} on this install.");
        if (!in_array((string) $conn->connectorType, $r['types'], true)) {
            throw new \InvalidArgumentException("Connection #{$connectionId} is {$conn->connectorType}; role '{$role}' of {$concept} takes " . implode(' or ', $r['types']) . '.');
        }
        $siteId = $siteId ?? (int) Sites::current()->id;
        [$scope, $etype, $eref] = self::entityKey($r, $entity);
        $b = Bean::findOne('connectionbinding', 'concept = ? AND role = ? AND site_ref = ? AND scope = ? AND entity_type = ? AND entity_ref = ?',
            [$concept, $role, $siteId, $scope, $etype, $eref]);
        if (!$b || !$b->id) {
            $b = Bean::dispense('connectionbinding');
            $b->concept = $concept; $b->role = $role; $b->siteRef = $siteId;
            $b->scope = $scope; $b->entityType = $etype; $b->entityRef = $eref;
            $b->createdAt = date('Y-m-d H:i:s');
        }
        $b->connectionRef = (int) $conn->id;
        $b->aliasSnapshot = ConnectionStore::alias($conn);
        $b->boundBy       = $by;
        $b->updatedAt     = date('Y-m-d H:i:s');
        Bean::store($b);
        return $b;
    }

    public static function unbind(string $concept, string $role, ?int $siteId = null, ?array $entity = null): bool {
        $r = self::role($concept, $role);
        $siteId = $siteId ?? (int) Sites::current()->id;
        [$scope, $etype, $eref] = self::entityKey($r, $entity);
        $b = Bean::findOne('connectionbinding', 'concept = ? AND role = ? AND site_ref = ? AND scope = ? AND entity_type = ? AND entity_ref = ?',
            [$concept, $role, $siteId, $scope, $etype, $eref]);
        if (!$b || !$b->id) return false;
        Bean::trash($b);
        return true;
    }

    /** Every binding of a concept (for the Plugins page), as plain rows. */
    public static function bindings(string $concept): array {
        return array_values(array_map(fn($b) => $b->export(), Bean::find('connectionbinding', 'concept = ? ORDER BY role, site_ref', [$concept])));
    }

    /**
     * Roles of a concept that nothing usable answers for the current site — required ones as
     * 'error', optional ones as 'notice' — with the sentence the page shows. Empty = all set.
     */
    public static function unbound(string $concept): array {
        $out = [];
        $m = self::$manifests ? (self::$manifests)($concept) : Concepts::instance()->manifest($concept);
        foreach ($m->connectorRoles as $r) {
            if ($r['scope'] === 'entity') continue;      // entity roles are judged per entity, on the entity's page
            try {
                self::for($concept, $r['role']);
            } catch (UnboundRoleException | MissingConnectorException $e) {
                $out[] = ['role' => $r['role'], 'label' => $r['label'], 'level' => $r['optional'] ? 'notice' : 'error', 'message' => $e->getMessage()];
            }
        }
        return $out;
    }

    /** Which connections a connection id is bound by — for the hub's "used by" and its delete refusal. */
    public static function usedBy(int $connectionId): array {
        return array_values(array_map(fn($b) => $b->export(), Bean::find('connectionbinding', 'connection_ref = ? ORDER BY concept, role', [$connectionId])));
    }

    // ---- internals ----------------------------------------------------------------------

    /** The bindings to consult, in order: entity on this site → this site → install-wide if the role inherits. */
    private static function bindingChain(string $concept, array $r, int $siteId, ?array $entity): array {
        $chain = [];
        if ($entity !== null) {
            [$scope, $etype, $eref] = self::entityKey($r, $entity);
            $b = Bean::findOne('connectionbinding', 'concept = ? AND role = ? AND site_ref = ? AND scope = ? AND entity_type = ? AND entity_ref = ?',
                [$concept, $r['role'], $siteId, $scope, $etype, $eref]);
            if ($b && $b->id) $chain[] = $b;
        }
        $b = Bean::findOne('connectionbinding', "concept = ? AND role = ? AND site_ref = ? AND scope = 'install'", [$concept, $r['role'], $siteId]);
        if ($b && $b->id) $chain[] = $b;
        if ($r['inherit'] && $siteId !== 0) {
            $b = Bean::findOne('connectionbinding', "concept = ? AND role = ? AND site_ref = 0 AND scope = 'install'", [$concept, $r['role']]);
            if ($b && $b->id) $chain[] = $b;
        }
        return $chain;
    }

    private static function entityKey(array $r, ?array $entity): array {
        if ($entity === null) return ['install', '', 0];
        if ($r['scope'] !== 'entity') throw new \InvalidArgumentException("Role '{$r['role']}' is bound per install, not per entity.");
        $type = (string) ($entity['type'] ?? $r['entity']);
        $ref  = (int) ($entity['id'] ?? 0);
        if ($type === '' || $ref <= 0) throw new \InvalidArgumentException("An entity binding needs ['type' => bean, 'id' => id].");
        return ['entity', $type, $ref];
    }
}
