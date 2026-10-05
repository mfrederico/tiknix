<?php
/**
 * PlanPhases — which of a project's plans the Builder's "build the next phase" builds.
 *
 * A project's plans are its phases, oldest first. One is NEXT when it is planned and not built
 * (draft, approved or stalled) and not superseded — an automatic re-plan whose original then
 * finished without it is work already done, and is never offered. Nothing is next while a phase
 * is building: phases run one at a time.
 *
 * Pure, so the rule is testable here; the Builder (workbench.tiknix Workbench::phaseList) loads
 * the plans and marks the superseded ones.
 */
namespace app;

final class PlanPhases {
    public const WAITING = ['draft', 'approved', 'stalled'];

    /**
     * @param array<int,array{id:int,plan_status:string,superseded:bool}> $phases oldest first
     * @return array|null the phase to build, or null (one is building, or none is waiting)
     */
    public static function next(array $phases): ?array {
        foreach ($phases as $ph) if ($ph['plan_status'] === 'building') return null;
        foreach ($phases as $ph) {
            if (empty($ph['superseded']) && in_array($ph['plan_status'], self::WAITING, true)) return $ph;
        }
        return null;
    }
}
