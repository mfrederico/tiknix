
<style>
    .about-hero {
        background: linear-gradient(135deg, var(--dark-color) 0%, #1a1f2e 50%, var(--dark-color) 100%);
        color: white;
        padding: 100px 0 60px;
        position: relative;
        overflow: hidden;
    }

    .about-hero::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -20%;
        width: 80%;
        height: 200%;
        background: radial-gradient(ellipse, rgba(255,107,53,0.06) 0%, transparent 70%);
        pointer-events: none;
    }

    .mission-card {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 16px;
        padding: 2.5rem;
        transition: all 0.3s ease;
        height: 100%;
    }

    .mission-card:hover {
        transform: translateY(-5px);
        border-color: var(--primary-color);
        box-shadow: 0 20px 40px rgba(255,107,53,0.1);
    }

    .mission-badge {
        display: inline-block;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 6px 16px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 1.25rem;
    }

    .mission-card h3 {
        font-size: 1.35rem;
        font-weight: 700;
        color: #fff;
        margin-bottom: 0.75rem;
    }

    .mission-card p {
        color: var(--text-secondary);
        font-size: 0.95rem;
        line-height: 1.7;
        margin: 0;
    }

    /* Timeline */
    .timeline {
        position: relative;
        padding: 0;
    }

    .timeline::before {
        content: '';
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        width: 2px;
        height: 100%;
        background: linear-gradient(180deg, var(--primary-color), var(--secondary-color));
    }

    .timeline-item {
        position: relative;
        padding: 30px 0;
    }

    .timeline-content {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 16px;
        padding: 2rem;
        width: 45%;
        transition: all 0.3s ease;
    }

    .timeline-content:hover {
        border-color: var(--primary-color);
    }

    .timeline-item:nth-child(odd) .timeline-content {
        margin-left: auto;
    }

    .timeline-dot {
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        width: 16px;
        height: 16px;
        background: var(--primary-color);
        border: 3px solid var(--dark-color);
        border-radius: 50%;
        box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.3);
        z-index: 1;
    }

    .timeline-year {
        font-size: 1.75rem;
        font-weight: 800;
        color: var(--primary-color);
        margin-bottom: 6px;
    }

    .timeline-content h4 {
        font-size: 1.1rem;
        font-weight: 700;
        color: #fff;
        margin-bottom: 0.5rem;
    }

    .timeline-content p {
        color: var(--text-secondary);
        font-size: 0.9rem;
        line-height: 1.7;
        margin: 0;
    }

    /* Values */
    .value-card {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 16px;
        padding: 2rem;
        text-align: center;
        transition: all 0.3s ease;
        height: 100%;
    }

    .value-card:hover {
        transform: translateY(-5px);
        border-color: var(--primary-color);
        box-shadow: 0 20px 40px rgba(255,107,53,0.1);
    }

    .value-icon {
        width: 64px;
        height: 64px;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.25rem;
    }

    .value-icon i {
        font-size: 1.5rem;
        color: white;
    }

    .value-card h4 {
        font-size: 1.1rem;
        font-weight: 700;
        color: #fff;
        margin-bottom: 0.5rem;
    }

    .value-card p {
        color: var(--text-secondary);
        font-size: 0.9rem;
        line-height: 1.6;
        margin: 0;
    }

    /* Team */
    .team-member {
        text-align: center;
    }

    .team-member-photo {
        width: 160px;
        height: 160px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        margin: 0 auto 1.25rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 3.5rem;
        color: white;
    }

    .team-member h4 {
        font-size: 1.25rem;
        font-weight: 700;
        color: #fff;
        margin-bottom: 4px;
    }

    .team-member .role {
        color: var(--primary-color);
        font-weight: 600;
        margin-bottom: 0.5rem;
        font-size: 0.95rem;
    }

    .team-member .bio {
        color: var(--text-secondary);
        font-size: 0.85rem;
    }

    /* FAQ */
    .accordion-item {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        margin-bottom: 8px;
        border-radius: 12px !important;
        overflow: hidden;
    }

    .accordion-button {
        font-weight: 600;
        font-size: 1.05rem;
        padding: 1.25rem;
        background: var(--dark-surface);
        color: #fff;
        border: none;
    }

    .accordion-button::after {
        filter: invert(1);
    }

    .accordion-button:not(.collapsed) {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        box-shadow: none;
    }

    .accordion-button:not(.collapsed)::after {
        filter: none;
    }

    .accordion-button:focus {
        box-shadow: none;
        border-color: transparent;
    }

    .accordion-body {
        padding: 1.25rem;
        font-size: 0.95rem;
        line-height: 1.7;
        color: var(--text-secondary);
        background: var(--dark-surface);
    }

    .accordion-body a {
        color: var(--primary-color);
    }

    @media (max-width: 768px) {
        .timeline::before {
            left: 24px;
        }

        .timeline-content {
            width: calc(100% - 60px);
            margin-left: 50px !important;
        }

        .timeline-dot {
            left: 24px;
        }
    }
</style>

<!-- About Hero -->
<section class="about-hero">
    <div class="container position-relative">
        <div class="text-center">
            <h1 style="font-size: 3rem; font-weight: 900; margin-bottom: 1rem;">About ShipCannon</h1>
            <p style="font-size: 1.2rem; color: var(--text-secondary); max-width: 600px; margin: 0 auto;">The company behind CannonWMS &mdash; built to give growing eCommerce brands the warehouse tools that used to require a Fortune 500 budget.</p>
        </div>
    </div>
</section>

<!-- Mission Cards -->
<section class="section-dark" style="padding: 80px 0;">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="mission-card">
                    <span class="mission-badge">Why</span>
                    <h3>Why did we build CannonWMS?</h3>
                    <p>We watched eCommerce brands outgrow spreadsheets and basic shipping tools, only to find that real WMS platforms cost $2,000/month or $100K+ to implement. We built CannonWMS to close that gap &mdash; enterprise warehouse management at a price that makes sense.</p>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="mission-card">
                    <span class="mission-badge">What</span>
                    <h3>What is CannonWMS?</h3>
                    <p>CannonWMS is a full-featured, multi-warehouse management system with real-time inventory tracking, multi-channel sync, pick tours, carrier rate shopping, and built-in reporting. It's the WMS that ShipCannon built and operates for eCommerce brands of all sizes.</p>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="mission-card">
                    <span class="mission-badge">How</span>
                    <h3>How is it different?</h3>
                    <p>Transparent usage-based pricing you can calculate before signing up. Custom integrations built during implementation. Deployed in days, not months. And reporting that works out of the box &mdash; no SQL developers, no add-on fees, no surprises.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Company Stats -->
<section class="section-surface">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="section-title">By the Numbers</h2>
            <p class="section-subtitle">The impact CannonWMS has on eCommerce operations</p>
        </div>

        <div class="row g-4">
            <div class="col-md-4 col-6">
                <div class="stat-card">
                    <div class="stat-number">2019</div>
                    <div class="stat-label">Founded</div>
                </div>
            </div>
            <div class="col-md-4 col-6">
                <div class="stat-card">
                    <div class="stat-number">31.5M+</div>
                    <div class="stat-label">Packages Shipped</div>
                </div>
            </div>
            <div class="col-md-4 col-6">
                <div class="stat-card">
                    <div class="stat-number">99.9%</div>
                    <div class="stat-label">Platform Uptime</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Our Story Timeline -->
<section class="section-dark">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="section-title">Our Journey</h2>
            <p class="section-subtitle">From a warehouse problem to a platform that powers eCommerce operations</p>
        </div>

        <div class="timeline">
            <div class="timeline-item">
                <div class="timeline-dot"></div>
                <div class="timeline-content">
                    <div class="timeline-year">2019</div>
                    <h4>The Beginning</h4>
                    <p>Founded in Logan, Utah after seeing firsthand how eCommerce businesses struggled with expensive, overcomplicated warehouse software that took months to deploy.</p>
                </div>
            </div>

            <div class="timeline-item">
                <div class="timeline-dot"></div>
                <div class="timeline-content">
                    <div class="timeline-year">2020</div>
                    <h4>First Major Milestone</h4>
                    <p>Processed our first million packages and proved the model: real WMS functionality at a fraction of the cost, deployed in days instead of months.</p>
                </div>
            </div>

            <div class="timeline-item">
                <div class="timeline-dot"></div>
                <div class="timeline-content">
                    <div class="timeline-year">2021</div>
                    <h4>Multi-Warehouse &amp; Channels</h4>
                    <p>Launched multi-warehouse support, multi-channel sync with Shopify and WooCommerce, and the pick tour system that became a core differentiator.</p>
                </div>
            </div>

            <div class="timeline-item">
                <div class="timeline-dot"></div>
                <div class="timeline-content">
                    <div class="timeline-year">2022</div>
                    <h4>Enterprise Features, Startup Pricing</h4>
                    <p>Added carrier rate shopping via EasyPost and Shippo, full REST API with webhooks, and customizable user roles &mdash; all included in the base platform.</p>
                </div>
            </div>

            <div class="timeline-item">
                <div class="timeline-dot"></div>
                <div class="timeline-content">
                    <div class="timeline-year">2023</div>
                    <h4>Proven at Scale</h4>
                    <p>Processing 31,500+ packages monthly for customers including Internet Retailer 500 companies. Linentablecloth.com cut staff by 65% and saved $180K annually.</p>
                </div>
            </div>

            <div class="timeline-item">
                <div class="timeline-dot"></div>
                <div class="timeline-content">
                    <div class="timeline-year">2024-25</div>
                    <h4>What's Next</h4>
                    <p>Expanding ERP integrations, deeper reporting, and continuing to build custom integrations for every customer. The mission stays the same: make enterprise warehouse management accessible.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Our Values -->
<section class="section-surface">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="section-title">Our Core Values</h2>
            <p class="section-subtitle">The principles that guide how we build and support CannonWMS</p>
        </div>

        <div class="row g-4">
            <div class="col-lg-4 col-md-6">
                <div class="value-card">
                    <div class="value-icon"><i class="fas fa-eye"></i></div>
                    <h4>Transparency</h4>
                    <p>Published pricing, no hidden fees, no sales calls to get a quote. You know what you're paying before you sign up.</p>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="value-card">
                    <div class="value-icon"><i class="fas fa-shield-alt"></i></div>
                    <h4>Reliability</h4>
                    <p>99.9% uptime and architecture built for high-volume operations. Your warehouse doesn't stop, and neither does CannonWMS.</p>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="value-card">
                    <div class="value-icon"><i class="fas fa-handshake"></i></div>
                    <h4>Partnership</h4>
                    <p>We build custom integrations, handle setup, and work alongside your team. We succeed when you succeed.</p>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="value-card">
                    <div class="value-icon"><i class="fas fa-bolt"></i></div>
                    <h4>Speed</h4>
                    <p>Deployed in days, not months. Fast setup, fast support responses, fast platform performance under load.</p>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="value-card">
                    <div class="value-icon"><i class="fas fa-lightbulb"></i></div>
                    <h4>Innovation</h4>
                    <p>Constantly evolving the platform with new integrations, reporting, and features driven by what our customers actually need.</p>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="value-card">
                    <div class="value-icon"><i class="fas fa-chart-line"></i></div>
                    <h4>Efficiency</h4>
                    <p>Every feature is designed to reduce clicks, eliminate manual work, and help your team do more with less.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Team Section -->
<section class="section-dark" id="careers">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="section-title">Meet Our Leadership</h2>
            <p class="section-subtitle">The team building the next generation of warehouse management</p>
        </div>

        <div class="row justify-content-center g-5">
            <div class="col-lg-4 col-md-6">
                <div class="team-member">
                    <div class="team-member-photo">
                        <i class="fas fa-user"></i>
                    </div>
                    <h4>Ron Barrett</h4>
                    <div class="role">Co-Founder</div>
                    <p class="bio">20+ years in logistics and eCommerce operations</p>
                </div>
            </div>
            <div class="col-lg-4 col-md-6">
                <div class="team-member">
                    <div class="team-member-photo">
                        <i class="fas fa-user"></i>
                    </div>
                    <h4>Matthew Frederico</h4>
                    <div class="role">Technical Co-Founder</div>
                    <p class="bio">From fintech to fashion &mdash; building systems that scale</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Culture Section -->
<section class="section-gradient">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <h2 style="font-size: 2.25rem; font-weight: 800; margin-bottom: 1rem;">Work With Purpose</h2>
                <p style="font-size: 1.1rem; opacity: 0.9; margin-bottom: 2rem;">At ShipCannon, we build software that helps real businesses run real warehouses. Every feature we ship, every integration we build, and every support ticket we answer has a direct impact on someone's livelihood.</p>

                <ul style="list-style: none; padding: 0; margin: 0 0 2rem;">
                    <li style="margin-bottom: 0.75rem; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-check-circle"></i> Remote-first team with members worldwide
                    </li>
                    <li style="margin-bottom: 0.75rem; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-check-circle"></i> Continuous learning and growth opportunities
                    </li>
                    <li style="margin-bottom: 0.75rem; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-check-circle"></i> Work-life balance and flexibility
                    </li>
                    <li style="display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-check-circle"></i> Direct impact on customer outcomes
                    </li>
                </ul>

                <a href="/contact-us" class="btn btn-light btn-lg">
                    <i class="fas fa-briefcase"></i> Get In Touch
                </a>
            </div>
            <div class="col-lg-6 mt-4 mt-lg-0">
                <div class="row g-3">
                    <div class="col-6">
                        <div style="background: rgba(255,255,255,0.12); border-radius: 16px; padding: 2.5rem; text-align: center;">
                            <i class="fas fa-users fa-3x" style="opacity: 0.8;"></i>
                            <div style="margin-top: 1rem; font-weight: 600;">Collaborative Team</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div style="background: rgba(255,255,255,0.12); border-radius: 16px; padding: 2.5rem; text-align: center;">
                            <i class="fas fa-rocket fa-3x" style="opacity: 0.8;"></i>
                            <div style="margin-top: 1rem; font-weight: 600;">Ship Fast</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div style="background: rgba(255,255,255,0.12); border-radius: 16px; padding: 2.5rem; text-align: center;">
                            <i class="fas fa-trophy fa-3x" style="opacity: 0.8;"></i>
                            <div style="margin-top: 1rem; font-weight: 600;">Customer Wins</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div style="background: rgba(255,255,255,0.12); border-radius: 16px; padding: 2.5rem; text-align: center;">
                            <i class="fas fa-heart fa-3x" style="opacity: 0.8;"></i>
                            <div style="margin-top: 1rem; font-weight: 600;">Love the Work</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section class="section-dark">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="text-center mb-5">
                    <h2 class="section-title">Frequently Asked Questions</h2>
                    <p class="section-subtitle">Common questions about ShipCannon and CannonWMS</p>
                </div>

                <div class="accordion" id="aboutFAQ">
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="faq1">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapse1">
                                What is the relationship between ShipCannon and CannonWMS?
                            </button>
                        </h2>
                        <div id="collapse1" class="accordion-collapse collapse show" data-bs-parent="#aboutFAQ">
                            <div class="accordion-body">
                                ShipCannon is the company. CannonWMS is the warehouse management system we built and operate. When you sign up for CannonWMS, you're working with the ShipCannon team for setup, support, and custom integrations.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="faq2">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse2">
                                How do I get started with CannonWMS?
                            </button>
                        </h2>
                        <div id="collapse2" class="accordion-collapse collapse" data-bs-parent="#aboutFAQ">
                            <div class="accordion-body">
                                Start a <a href="https://setup.cannonwms.com/signup/">60-day free trial</a> &mdash; no credit card required. Our team handles setup, connects your sales channels, and configures your warehouse. Most businesses are live within days.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="faq3">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse3">
                                What integrations does CannonWMS support?
                            </button>
                        </h2>
                        <div id="collapse3" class="accordion-collapse collapse" data-bs-parent="#aboutFAQ">
                            <div class="accordion-body">
                                Native adapters for Shopify (with multi-location), WooCommerce, and Miva. Carrier integrations via EasyPost and Shippo. We also build custom integrations for ERPs (NetSuite, SAP, Sage, QuickBooks Online, Zoho Books) and any other system with an API as part of implementation.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="faq4">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse4">
                                How much does CannonWMS cost?
                            </button>
                        </h2>
                        <div id="collapse4" class="accordion-collapse collapse" data-bs-parent="#aboutFAQ">
                            <div class="accordion-body">
                                Transparent, usage-based billing: $450/month base platform fee, plus $149 per warehouse, $2.83 per user, and $0.01 per shipment. Use our <a href="/pricing">pricing calculator</a> to see your exact monthly cost. Implementation and custom integrations are quoted separately and negotiable.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="faq5">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse5">
                                Can CannonWMS handle my order volume?
                            </button>
                        </h2>
                        <div id="collapse5" class="accordion-collapse collapse" data-bs-parent="#aboutFAQ">
                            <div class="accordion-body">
                                Yes. CannonWMS processes 1,500+ orders daily for Internet Retailer 500 companies. The platform is architected for high-volume operations without the performance issues that plague competitors like SkuVault and Fishbowl.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Final CTA -->
<section class="cta-section">
    <div class="container">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8">
                <h2 class="cta-title">Ready to Get Started?</h2>
                <p class="cta-subtitle">Start your 60-day free trial today. No credit card required. Full feature access from day one.</p>

                <div class="row g-3 justify-content-center">
                    <div class="col-md-5">
                        <a href="https://setup.cannonwms.com/signup/" class="btn btn-light btn-lg w-100">
                            <i class="fas fa-rocket"></i> Start Free Trial
                        </a>
                    </div>
                    <div class="col-md-5">
                        <a href="/pricing" class="btn btn-outline-light btn-lg w-100">
                            <i class="fas fa-calculator"></i> Pricing Calculator
                        </a>
                    </div>
                </div>

                <div class="mt-4">
                    <small><i class="fas fa-lock"></i> No credit card required &bull; Cancel anytime</small>
                </div>
            </div>
        </div>
    </div>
</section>
