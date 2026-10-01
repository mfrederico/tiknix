<?php
/**
 * The platform's billing panel on the member edit screen (project quota, plan tier, free
 * grants). Rendered by the runtime's admin/edit_member through the name
 * PlatformMemberAdmin::editView() returns — RUNTIME-SPLIT-MAP.md step 2.
 * Vars: $projectQuota, $freeGrants, $editMember (+ the edit screen's own).
 */
?>
                        <div class="row">
                            <?php $__q = $projectQuota; $__tier = strtolower((string) ($editMember->planTier ?: 'free')); ?>
                            <div class="col-sm-6 mb-3">
                                <label for="plan_tier" class="form-label">Plan tier</label>
                                <select class="form-select" id="plan_tier" name="plan_tier">
                                    <?php $__opts = ['free' => 'Free — first project only, solo', 'pro' => 'Per project — $' . number_format(\app\ProjectQuota::PRICE_PER_PROJECT, 0) . ' each past the free allowance'];
                                          if (\app\ProjectQuota::AGENCY_OFFERED || $__tier === 'agency') $__opts['agency'] = 'Agency — $' . number_format(\app\ProjectQuota::PRICE_AGENCY, 0) . ' for ' . (int) \app\ProjectQuota::AGENCY_POOL . ', then $' . number_format(\app\ProjectQuota::PRICE_AGENCY_EXTRA, 0) . ' each';
                                          $__opts['legacy'] = 'Legacy — grandfathered, never billed'; ?>
                                    <?php foreach ($__opts as $__v => $__l): ?>
                                    <option value="<?= $__v ?>" <?= $__tier === $__v ? 'selected' : '' ?>><?= htmlspecialchars($__l) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">
                                    Free and per-project follow the card automatically. The invoice prices whatever
                                    the usage callback reports, so a change here takes effect on the next billing run.
                                </small>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label for="free_projects" class="form-label">Free projects</label>
                                <input type="number" class="form-control" id="free_projects" name="free_projects"
                                       min="0" step="1" value="<?= (int)($editMember->freeProjects ?? 0) ?>">
                                <small class="form-text text-muted">
                                    Projects this member gets free. <strong>0 = the default (<?= (int)\app\ProjectQuota::FREE_CAP ?>)</strong>;
                                    raise it to grant more — e.g. 99 for effectively unlimited. Anything past this is billed.
                                    Does not unlock Teams (that stays a paid-plan perk).
                                </small>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label for="free_projects_note" class="form-label">Why <span class="text-muted small">(kept with the change)</span></label>
                                <input type="text" class="form-control" id="free_projects_note" name="free_projects_note" maxlength="500"
                                       placeholder="e.g. beta tester, partner, making up for downtime">
                                <small class="form-text text-muted">
                                    Now: holds <strong><?= (int)$__q['count'] ?></strong> ·
                                    <?php if ($__q['tier'] === 'legacy'): ?>
                                    free <strong>all</strong> (legacy: never billed; raising this above <?= (int)$__q['cap'] ?> raises the cap) ·
                                    <?php else: ?>
                                    free <strong><?= (int)$__q['free'] ?></strong> ·
                                    <?php endif; ?>
                                    billed <strong><?= (int)$__q['billable'] ?></strong> ·
                                    may hold <strong><?= $__q['cap'] >= PHP_INT_MAX ? 'any number' : (int)$__q['cap'] ?></strong>
                                    <span class="text-muted">(<?= htmlspecialchars((string)$__q['tier']) ?> plan)</span>
                                </small>
                            </div>
                            <?php if (!empty($freeGrants)): ?>
                            <div class="col-12 mb-3">
                                <div class="small text-muted mb-1">Free-project history</div>
                                <ul class="list-unstyled small mb-0">
                                <?php foreach ($freeGrants as $g): $r = $g['row']; ?>
                                    <li><?= htmlspecialchars(substr((string)$r->createdAt, 0, 16)) ?> —
                                        <?= (int)$r->oldValue ?> → <strong><?= (int)$r->newValue ?></strong>
                                        by <?= htmlspecialchars($g['by']) ?><?= (string)$r->note !== '' ? ': ' . htmlspecialchars((string)$r->note) : '' ?></li>
                                <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                        </div>
