<?php
/**
 * 56_MessagingTiknix.php — Tiknix's own messages. Until plugin 2.0 this seed registered the two kinds of message (technical,
 * marketing) and two paused nudges as rows of the messaging plugin's tables, and set the reply domain as a setting. With
 * messaging 2.x the plugin is a client of the messaging service and keeps no rows: the kinds and the nudges are declared in
 * lib/controlplane.php (Providers categories and system) and mirrored into the service by the hourly pipeline; the rows this
 * seed had made went over with scripts/messaging-import.php (October 2026). Replies come back to the SITE's reply domain,
 * set on /messaging. Nothing is left for this seed to do; the old msg* tables stay until the links in mail already sent no
 * longer matter.
 */
echo "  messaging: Tiknix's kinds and nudges live in the messaging service (lib/controlplane.php declares them)\n";
