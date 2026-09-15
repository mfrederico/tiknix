<?php
/*
 * Privacy Policy — canonical page. Served by Privacy::index() at /privacy (the URL the
 * signup form links to). Uses the app layout (Bootstrap card), like the rest of the site.
 *
 * ⚠ STARTER CONTENT FOR LEGAL REVIEW — not legal advice. Confirm/fill with counsel:
 *   the contact address routes (privacy@clicksimple.com), the subprocessor list is current,
 *   the "Last updated" date, and any CCPA/GDPR/state disclosures you require.
 *   Entity per the owner: ClickSimple LLC.
 */
$updated = 'September 15, 2026';
$entity  = 'ClickSimple LLC';
$email   = 'privacy@clicksimple.com';
?>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <h1 class="mb-1">Privacy Policy</h1>
            <p class="text-muted">Last updated: <?= htmlspecialchars($updated) ?> · Tiknix is operated by <?= htmlspecialchars($entity) ?>.</p>

            <div class="card mb-4">
                <div class="card-body">
                    <p>This policy explains what Tiknix collects, how we use it, and the choices you have.
                    "Tiknix", "we", and "us" mean <?= htmlspecialchars($entity) ?>, which operates the Tiknix
                    platform; "you" means a visitor or account holder.</p>

                    <h5>1. Information we collect</h5>
                    <ul>
                        <li><strong>Account information</strong> — your name, email, and login credentials (passwords are stored only as salted hashes, never in plain text).</li>
                        <li><strong>Projects and content</strong> — the code, data, and files in the projects you build and host. This is your content; we store and process it to run the service for you.</li>
                        <li><strong>Payment information</strong> — handled by our payment processor (Stripe). We keep a billing reference and plan status but never see or store your full card number.</li>
                        <li><strong>Usage and technical data</strong> — logs, IP address, and actions in the app, used for security, debugging, and improving the service.</li>
                        <li><strong>Cookies</strong> — a session cookie to keep you logged in and functional cookies for preferences. No third-party advertising cookies.</li>
                    </ul>

                    <h5>2. How we use your information</h5>
                    <ul>
                        <li>To provide, operate, and maintain the platform and your projects.</li>
                        <li>To process payments and manage your subscription.</li>
                        <li>To secure the service — detect abuse, enforce limits, and keep projects isolated from one another.</li>
                        <li>To communicate about your account, security, and service changes.</li>
                        <li>To improve the product using aggregate, non-identifying usage patterns.</li>
                    </ul>

                    <h5>3. How we protect your data</h5>
                    <p>Each project runs in its <strong>own isolated environment</strong>, separated at the
                    operating-system level so one member's project cannot read another's files or data. Traffic is
                    encrypted in transit (HTTPS), passwords are hashed, sensitive integration keys are encrypted at
                    rest, and access to production systems is restricted. No system is perfectly secure, but
                    isolation and least-privilege access are core to how Tiknix is built.</p>

                    <h5>4. When we share information</h5>
                    <p><strong>We do not sell your personal information.</strong> We share it only with service
                    providers who help us run Tiknix, and only as needed — our payment processor (Stripe), email
                    delivery, and hosting/infrastructure — or where required by law, to enforce our terms, or to
                    protect the rights and safety of Tiknix, our members, or the public.</p>

                    <h5>5. Your projects and content</h5>
                    <p>You own the projects and content you create. We access them only to operate the service
                    (for example, to run, back up, or restore your project), to provide support you request, or
                    where required for security or legal reasons. When you delete a project, it is archived briefly
                    for recovery and then permanently removed.</p>

                    <h5>6. Data retention</h5>
                    <p>We keep your information while your account is active and as needed to provide the service.
                    When you close your account, we delete or anonymize your personal information within a
                    reasonable period, except where we must retain it for legal, tax, or accounting obligations.</p>

                    <h5>7. Your rights</h5>
                    <p>Depending on where you live, you may have the right to access, correct, export, or delete
                    your personal information, and to object to or restrict certain processing. To exercise these
                    rights, email <a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a>.</p>

                    <h5>8. Children</h5>
                    <p>Tiknix is not directed to children under 16 and we do not knowingly collect their personal
                    information. If you believe a child has provided us information, contact us and we will delete it.</p>

                    <h5>9. Changes to this policy</h5>
                    <p>We may update this policy from time to time. When we make material changes, we will update
                    the "Last updated" date above and, where appropriate, notify you. Continued use of Tiknix after
                    a change means you accept the updated policy.</p>

                    <h5>10. Contact</h5>
                    <p>Questions about this policy or your data? Email
                    <a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a>
                    &mdash; <?= htmlspecialchars($entity) ?>.</p>
                </div>
            </div>

            <p class="text-center">
                <a href="/" class="btn btn-outline-secondary">Back to Home</a>
                <a href="/terms" class="btn btn-outline-secondary">Terms of Service</a>
            </p>
        </div>
    </div>
</div>
