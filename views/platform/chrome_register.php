<?php /* Control-plane chrome, slot 'register' (lib/controlplane.php): above the sign-up form. What Tiknix is and what you will
   need, before an account exists; and the one way most people start. Not shown to someone finishing a plan from start.tiknix.com.
   ui: hand-made — a note inside the narrow sign-up card, where a notice with an action squeezes its text into a column. */
if (!empty($_SESSION['handoff_token'])) return; ?>
<div class="small border rounded-3 p-3 mb-3 bg-body-tertiary">
    <p class="mb-2"><strong>Tiknix is a harness utility.</strong> You bring the AI that builds (your Claude subscription or an API key).
        Tiknix gives it your app's own server, database, tests and a browser to check its work.</p>
    <p class="mb-0">New here? Most people <a href="https://start.tiknix.com/start">start with a few questions</a> about their business
        (5 to 10 minutes). We draft the plan, and the account comes at the end.</p>
</div>
