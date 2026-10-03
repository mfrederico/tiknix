<?php
/**
 * 38_ConcepthubConnectors.php — concepthub::connectors and ::connector: an app installing a
 * plugin asks the catalog which connector manifests it holds, with its broker key (the method
 * authenticates). PUBLIC at the route like the rest of concepthub. The first such request made
 * the framework invent a `connectors` row at ADMIN, which answered every app a 303 to the
 * login page — "did not answer 'connectors': HTTP 303" in the install log; seedRule corrects it.
 */
foreach ([['connectors', 'Concept catalog: connector manifests it holds (broker key)'], ['connector', 'Concept catalog: one connector manifest (broker key)']] as [$m, $note]) {
    echo "  authcontrol: concepthub::{$m} => " . \app\PermissionCache::seedRule('concepthub', $m, 101, $note) . "\n";
}
