<?php
/*
 * Terms of Service — canonical page. Served by Terms::index() at /terms (the URL the signup
 * form links to). Uses the app layout (Bootstrap card).
 *
 * ⚠ STARTER CONTENT FOR LEGAL REVIEW — not legal advice. Confirm with counsel:
 *   that the North Carolina governing-law/venue clause is what you want, the contact address
 *   routes, that refund/cancellation terms match what billing actually does, and any
 *   arbitration/consumer-law clauses you want. Entity per the owner: ClickSimple LLC (NC).
 */
$updated = 'September 15, 2026';
$entity  = 'ClickSimple LLC';
$email   = 'legal@clicksimple.com';
?>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <h1 class="mb-1">Terms of Service</h1>
            <p class="text-muted">Last updated: <?= htmlspecialchars($updated) ?> · Tiknix is operated by <?= htmlspecialchars($entity) ?>.</p>

            <div class="card mb-4">
                <div class="card-body">
                    <p>These terms govern your use of Tiknix. By creating an account or using the service, you
                    agree to them. "Tiknix", "we", and "us" mean <?= htmlspecialchars($entity) ?>; "you" means the
                    person or organization using the service. If you do not agree, do not use Tiknix.</p>

                    <h5>1. The service</h5>
                    <p>Tiknix is a platform for building, running, and hosting software projects, including
                    AI-assisted building tools. We may add, change, or remove features over time. Parts of the
                    service may be labeled beta or preview and can change or be discontinued.</p>

                    <h5>2. Your account</h5>
                    <ul>
                        <li>Provide accurate information and keep it current.</li>
                        <li>You are responsible for safeguarding your login and for all activity under your account.</li>
                        <li>You must be at least 16 (or the age of majority where you live) to use Tiknix.</li>
                        <li>Notify us promptly of any unauthorized use of your account.</li>
                    </ul>

                    <h5>3. Acceptable use</h5>
                    <p>You agree not to:</p>
                    <ul>
                        <li>Use Tiknix for anything illegal, or to store or distribute unlawful, infringing, or harmful content.</li>
                        <li>Access, disrupt, or interfere with other members' projects, data, or the underlying infrastructure.</li>
                        <li>Probe, scan, or circumvent the platform's security or isolation boundaries.</li>
                        <li>Consume resources abusively, or use the service to attack, spam, or defraud others.</li>
                        <li>Resell or misrepresent the service.</li>
                    </ul>
                    <p>We may suspend or terminate accounts that violate these rules and remove content that does.</p>

                    <h5>4. Your content and intellectual property</h5>
                    <p>You retain ownership of the projects and content you create. You grant us a limited license
                    to host, store, process, back up, and display that content solely to operate the service for
                    you. You are responsible for your content and for having the rights to use it. Tiknix's own
                    software, branding, and platform remain our property.</p>

                    <h5>5. Third-party services and credentials</h5>
                    <p>Tiknix lets you connect third-party services using your own keys and credentials. You are
                    responsible for those credentials and for complying with each third party's terms. We are not
                    responsible for third-party services.</p>

                    <h5>6. Plans, billing, and refunds</h5>
                    <ul>
                        <li>Tiknix offers a free tier and paid projects; current pricing is shown on the site.</li>
                        <li>Paid plans require a valid payment method on file and renew automatically each billing period until canceled.</li>
                        <li>You can cancel or remove a project at any time; cancellation stops future charges for that project.</li>
                        <li>Except where required by law, fees already paid are non-refundable; we may issue discretionary refunds.</li>
                        <li>We may change pricing with reasonable notice; changes apply to the next billing period.</li>
                    </ul>

                    <h5>7. Availability and disclaimer</h5>
                    <p>We work to keep Tiknix available and reliable, but the service is provided
                    <strong>"as is" and "as available"</strong>, without warranties of any kind, express or
                    implied, including merchantability, fitness for a particular purpose, and non-infringement. We
                    do not warrant that the service will be uninterrupted or error-free — keep your own backups of
                    anything important.</p>

                    <h5>8. Limitation of liability</h5>
                    <p>To the maximum extent permitted by law, <?= htmlspecialchars($entity) ?> will not be liable
                    for any indirect, incidental, special, consequential, or punitive damages, or for lost profits,
                    revenue, or data. Our total liability for any claim relating to the service will not exceed the
                    amount you paid us for the service in the twelve months before the claim.</p>

                    <h5>9. Termination</h5>
                    <p>You may stop using Tiknix and delete your account at any time. We may suspend or terminate
                    your access if you breach these terms, fail to pay, or where required to protect the service or
                    others. On termination, your right to use the service ends; we will make your content available
                    for a reasonable period where practical, then delete it.</p>

                    <h5>10. Indemnification</h5>
                    <p>You agree to indemnify and hold <?= htmlspecialchars($entity) ?> harmless from claims arising
                    out of your content, your use of the service, or your violation of these terms or the rights of
                    others.</p>

                    <h5>11. Governing law</h5>
                    <p>These terms are governed by the laws of the State of North Carolina, without regard to
                    conflict-of-law rules, and any dispute will be resolved in the state or federal courts located
                    in North Carolina, unless applicable law provides otherwise.</p>

                    <h5>12. Changes to these terms</h5>
                    <p>We may update these terms from time to time. When we make material changes, we will update
                    the "Last updated" date and, where appropriate, notify you. Continued use of Tiknix after a
                    change means you accept the updated terms.</p>

                    <h5>13. Contact</h5>
                    <p>Questions about these terms? Email
                    <a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a>
                    &mdash; <?= htmlspecialchars($entity) ?>.</p>
                </div>
            </div>

            <p class="text-center">
                <a href="/" class="btn btn-outline-secondary">Back to Home</a>
                <a href="/privacy" class="btn btn-outline-secondary">Privacy Policy</a>
            </p>
        </div>
    </div>
</div>
