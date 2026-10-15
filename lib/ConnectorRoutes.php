<?php
/**
 * ConnectorRoutes — where core sent each company's connection, for a provider whose app is the
 * platform's (QuickBooks). Core keeps no token: only "company 4620… of QuickBooks went to project
 * 137" (seed 52, connectorroute). It answers two questions the provider cannot:
 *
 *   which project?   a webhook names the company only (Intuit's realmId) — forCompany()
 *   may it renew?    a project renews only a company it connected through core — allows(),
 *                    and only so often — countRenewal()
 *
 * Recorded when core pushes a connection into a project (Connections::upsertConnection); forgotten
 * when the project, asked about a company's changes, says it no longer has it (QuickbooksWebhook).
 */

namespace app;

class ConnectorRoutes {
    /** The providers whose connections core routes (their app, and so their secret, is the platform's). */
    public const ROUTED = ['quickbooks'];
    /** Renewals one project may ask for one company in an hour. A connection needs one an hour. */
    public const RENEWALS_PER_HOUR = 20;

    public static function routed(string $connector): bool { return in_array(strtolower($connector), self::ROUTED, true); }

    /** A connection was pushed into a project: remember which company went where (no token). */
    public static function record(int $instanceId, string $connector, string $env, string $company, int $remoteRef): void {
        if (!self::routed($connector)) return;
        if ($instanceId <= 0 || trim($company) === '') throw new \InvalidArgumentException("ConnectorRoutes: a {$connector} connection needs its project and its company to be routed");
        $r = Bean::findOne('connectorroute', 'instance_ref = ? AND connector = ? AND company_eid = ?', [$instanceId, $connector, $company]) ?? Bean::dispense('connectorroute');
        if (!$r->id) { $r->instanceRef = $instanceId; $r->connector = $connector; $r->companyEid = $company; $r->createdAt = date('Y-m-d H:i:s'); $r->renewCount = 0; $r->renewWindow = ''; }
        $r->environment = $env; $r->remoteRef = $remoteRef;
        Bean::store($r);
    }

    /** Did this project connect this company through core? */
    public static function allows(int $instanceId, string $connector, string $company): bool {
        return $company !== '' && (bool) Bean::findOne('connectorroute', 'instance_ref = ? AND connector = ? AND company_eid = ?', [$instanceId, $connector, $company]);
    }

    /** Count one renewal; false when this project has asked too often this hour for this company. */
    public static function countRenewal(int $instanceId, string $connector, string $company): bool {
        $r = Bean::findOne('connectorroute', 'instance_ref = ? AND connector = ? AND company_eid = ?', [$instanceId, $connector, $company]);
        if (!$r) return false;
        $hour = date('Y-m-d H:00:00');
        if ((string) $r->renewWindow !== $hour) { $r->renewWindow = $hour; $r->renewCount = 0; }
        if ((int) $r->renewCount >= self::RENEWALS_PER_HOUR) return false;
        $r->renewCount = (int) $r->renewCount + 1;
        Bean::store($r);
        return true;
    }

    /** The projects that connected this company: [instance_ref, …]. */
    public static function forCompany(string $connector, string $company): array {
        return array_map('intval', Bean::getCol('SELECT instance_ref FROM connectorroute WHERE connector = ? AND company_eid = ?', [$connector, $company]));
    }

    /** The project no longer has this company: stop sending it its changes, and stop renewing it. */
    public static function forget(int $instanceId, string $connector, string $company): void {
        foreach (Bean::find('connectorroute', 'instance_ref = ? AND connector = ? AND company_eid = ?', [$instanceId, $connector, $company]) as $r) Bean::trash($r);
    }
}
