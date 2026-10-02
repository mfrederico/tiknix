<?php
/**
 * 35_NewsaasRoute.php — the manifesto moved from /neosaas to /newsaas when the term was
 * renamed (2026-10-02). The new URL is public like the old one; the old row (seed 25) stays
 * public too, because controls/Neosaas.php now redirects there.
 */
echo '  authcontrol: newsaas::* => ' . \app\PermissionCache::seedRule('newsaas', '*', 101, 'Public NewSaaS manifesto page') . "\n";
