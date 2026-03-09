
<style>
    .contact-hero {
        background: linear-gradient(135deg, var(--dark-color) 0%, #1a1f2e 50%, var(--dark-color) 100%);
        color: white;
        padding: 100px 0 60px;
        position: relative;
        overflow: hidden;
    }

    .contact-hero::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -20%;
        width: 80%;
        height: 200%;
        background: radial-gradient(ellipse, rgba(255,107,53,0.06) 0%, transparent 70%);
        pointer-events: none;
    }

    .contact-content {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 20px;
        overflow: hidden;
        margin-top: -30px;
        position: relative;
        z-index: 10;
    }

    .contact-info {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 40px;
        height: 100%;
    }

    .contact-info h3 {
        font-size: 1.75rem;
        font-weight: 700;
        margin-bottom: 1rem;
    }

    .contact-info-item {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
        padding: 14px;
        background: rgba(255,255,255,0.12);
        border-radius: 10px;
        transition: background 0.3s ease;
    }

    .contact-info-item:hover {
        background: rgba(255,255,255,0.2);
    }

    .contact-info-item i {
        font-size: 1.25rem;
        width: 36px;
        text-align: center;
        flex-shrink: 0;
    }

    .contact-info-item a {
        color: white;
        text-decoration: none;
    }

    .contact-form {
        padding: 40px;
    }

    .contact-form h3 {
        color: #fff;
        font-weight: 700;
        margin-bottom: 1.5rem;
    }

    .form-control, .form-select {
        background: var(--dark-color);
        border: 1px solid var(--dark-border);
        border-radius: 8px;
        padding: 12px 15px;
        font-size: 1rem;
        color: #e6edf3;
        transition: border-color 0.3s ease;
    }

    .form-control::placeholder {
        color: var(--text-secondary);
    }

    .form-control:focus, .form-select:focus {
        background: var(--dark-color);
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.15);
        color: #e6edf3;
    }

    .form-select {
        color: var(--text-secondary);
    }

    .form-select option {
        background: var(--dark-color);
        color: #e6edf3;
    }

    .form-label {
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 6px;
        font-size: 0.9rem;
    }

    .form-check-input {
        background-color: var(--dark-color);
        border-color: var(--dark-border);
    }

    .form-check-input:checked {
        background-color: var(--primary-color);
        border-color: var(--primary-color);
    }

    .form-check-label {
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    .quick-contact-card {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 16px;
        padding: 2rem;
        text-align: center;
        transition: all 0.3s ease;
    }

    .quick-contact-card:hover {
        transform: translateY(-5px);
        border-color: var(--primary-color);
        box-shadow: 0 20px 40px rgba(255,107,53,0.1);
    }

    .quick-contact-card i.card-icon {
        font-size: 2.5rem;
        color: var(--primary-color);
        margin-bottom: 1rem;
    }

    .quick-contact-card h4 {
        font-size: 1.2rem;
        font-weight: 700;
        color: #fff;
        margin-bottom: 0.5rem;
    }

    .quick-contact-card p {
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    .location-card {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 16px;
        padding: 2rem;
        height: 100%;
        transition: all 0.3s ease;
    }

    .location-card:hover {
        border-color: var(--primary-color);
        transform: translateY(-3px);
    }

    .location-card h4 {
        color: var(--primary-color);
        font-weight: 700;
        margin-bottom: 1rem;
        font-size: 1.1rem;
    }

    .location-card p {
        color: var(--text-secondary);
    }

    .location-card a {
        color: var(--primary-color);
        text-decoration: none;
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

    /* Support Options */
    .support-option {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 12px;
        padding: 1.5rem;
        text-align: center;
        transition: all 0.3s ease;
    }

    .support-option:hover {
        border-color: var(--primary-color);
        transform: translateY(-3px);
    }

    .support-option i {
        font-size: 2rem;
        color: var(--primary-color);
        margin-bottom: 0.75rem;
        display: block;
    }

    .support-option h5 {
        color: #fff;
        font-weight: 700;
        font-size: 1rem;
        margin-bottom: 0.25rem;
    }

    .support-option p {
        color: var(--text-secondary);
        font-size: 0.8rem;
        margin-bottom: 0.75rem;
    }
</style>

<!-- Contact Hero -->
<section class="contact-hero">
    <div class="container position-relative">
        <div class="text-center">
            <h1 style="font-size: 3rem; font-weight: 900; margin-bottom: 1rem;">Get In Touch</h1>
            <p style="font-size: 1.2rem; color: var(--text-secondary); max-width: 600px; margin: 0 auto;">Whether you need a demo, pricing details, or want to talk about custom integrations, our team is here to help.</p>
        </div>
    </div>
</section>

<!-- Quick Contact Cards -->
<section class="section-dark" style="padding: 80px 0 40px;">
    <div class="container">
        <div class="row g-4 justify-content-center">
            <div class="col-md-4">
                <div class="quick-contact-card">
                    <i class="fas fa-phone-volume card-icon"></i>
                    <h4>Call Us</h4>
                    <p class="mb-3">Speak with our team directly</p>
                    <a href="tel:+18668457447" class="btn btn-primary">
                        <i class="fas fa-phone"></i> (866) 845-7447
                    </a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="quick-contact-card">
                    <i class="fas fa-calendar-check card-icon"></i>
                    <h4>Schedule a Demo</h4>
                    <p class="mb-3">See CannonWMS in action</p>
                    <a href="https://cal.com/clicksimple-shipcannon/15min" class="btn btn-primary">
                        <i class="fas fa-calendar"></i> Book Demo
                    </a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="quick-contact-card">
                    <i class="fas fa-envelope card-icon"></i>
                    <h4>Email Us</h4>
                    <p class="mb-3">We respond within 24 hours</p>
                    <a href="mailto:info@shipcannon.com" class="btn btn-outline-primary">
                        <i class="fas fa-envelope"></i> info@shipcannon.com
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Main Contact Section -->
<section class="section-dark" style="padding: 40px 0 80px;">
    <div class="container">
        <div class="contact-content">
            <div class="row g-0">
                <!-- Contact Information -->
                <div class="col-lg-5">
                    <div class="contact-info">
                        <h3>Let's Talk About Your Warehouse</h3>
                        <p style="opacity: 0.9; margin-bottom: 2rem;">Whether you're evaluating WMS platforms, need a custom integration, or want to see what CannonWMS can do for your operation, we're here to help.</p>

                        <div class="contact-info-item">
                            <i class="fas fa-phone"></i>
                            <div>
                                <div class="fw-bold">Phone</div>
                                <a href="tel:+18668457447">+1 (866) 845-7447</a>
                            </div>
                        </div>

                        <div class="contact-info-item">
                            <i class="fas fa-envelope"></i>
                            <div>
                                <div class="fw-bold">Email</div>
                                <a href="mailto:info@shipcannon.com">info@shipcannon.com</a>
                            </div>
                        </div>

                        <div class="contact-info-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <div>
                                <div class="fw-bold">Headquarters</div>
                                <div>1163 E 50 S<br>Logan, UT 84321</div>
                            </div>
                        </div>

                        <div class="contact-info-item">
                            <i class="fas fa-clock"></i>
                            <div>
                                <div class="fw-bold">Business Hours</div>
                                <div>Mon-Fri: 8:00 AM - 6:00 PM MST</div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <div style="font-weight: 600; margin-bottom: 0.75rem;">Follow Us</div>
                            <div class="d-flex gap-3">
                                <a href="https://www.linkedin.com/company/shipcannon/" class="text-white fs-4"><i class="fab fa-linkedin"></i></a>
                                <a href="https://www.facebook.com/profile.php?id=100088177518045" class="text-white fs-4"><i class="fab fa-facebook"></i></a>
                                <a href="https://twitter.com/ShipCannon" class="text-white fs-4"><i class="fab fa-twitter"></i></a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contact Form -->
                <div class="col-lg-7">
                    <div class="contact-form">
                        <h3>Send Us a Message</h3>
                        <form id="contactForm" method="post" action="">
                            <input type="hidden" name="contact_form_submit" value="1">
                            <input type="hidden" name="_bscore" id="_bscore" value="0">
                            <input type="hidden" name="_btime" id="_btime" value="0">
                            <!-- Honeypot — hidden from humans, bots fill it -->
                            <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">
                                <input type="text" name="website_url" tabindex="-1" autocomplete="off" value="">
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="name" class="form-label">Your Name *</label>
                                    <input type="text" class="form-control" id="name" name="name" placeholder="Full name" required>
                                </div>

                                <div class="col-md-6">
                                    <label for="email" class="form-label">Email Address *</label>
                                    <input type="email" class="form-control" id="email" name="email" placeholder="you@company.com" required>
                                </div>

                                <div class="col-md-6">
                                    <label for="phone" class="form-label">Phone Number *</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" placeholder="(555) 123-4567" required>
                                </div>

                                <div class="col-md-6">
                                    <label for="company" class="form-label">Company Name</label>
                                    <input type="text" class="form-control" id="company" name="company" placeholder="Your company">
                                </div>

                                <div class="col-md-6">
                                    <label for="orders" class="form-label">Monthly Order Volume</label>
                                    <select class="form-select" id="orders" name="orders">
                                        <option value="">Select range...</option>
                                        <option value="0-100">0-100 orders</option>
                                        <option value="101-500">101-500 orders</option>
                                        <option value="501-1000">501-1,000 orders</option>
                                        <option value="1001-5000">1,001-5,000 orders</option>
                                        <option value="5001+">5,001+ orders</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label for="interest" class="form-label">I'm Interested In</label>
                                    <select class="form-select" id="interest" name="interest">
                                        <option value="">Select option...</option>
                                        <option value="demo">Product Demo</option>
                                        <option value="pricing">Pricing / Implementation Quote</option>
                                        <option value="integration">Custom Integration</option>
                                        <option value="trial">Free Trial</option>
                                        <option value="support">Technical Support</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>

                                <div class="col-12">
                                    <label for="message" class="form-label">Your Message *</label>
                                    <textarea class="form-control" id="message" name="message" rows="4" required placeholder="Tell us about your warehouse needs and how we can help..."></textarea>
                                </div>

                                <div class="col-12">
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" id="newsletter" name="newsletter" checked>
                                        <label class="form-check-label" for="newsletter">
                                            Send me tips on warehouse automation and inventory management
                                        </label>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary btn-lg w-100">
                                        <i class="fas fa-paper-plane"></i> Send Message
                                    </button>
                                    <small style="color: var(--text-secondary); display: block; margin-top: 0.5rem;">
                                        <i class="fas fa-lock"></i> Your information is secure and will never be shared.
                                    </small>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Office Locations -->
<section class="section-surface">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="section-title">Our Locations</h2>
            <p class="section-subtitle">Visit us or reach out to your nearest office</p>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-lg-4">
                <div class="location-card">
                    <h4><i class="fas fa-building"></i> Headquarters</h4>
                    <p><strong style="color: var(--text-primary);">Logan, Utah</strong><br>
                    1163 E 50 S<br>
                    Logan, UT 84321<br>
                    <a href="tel:+18668457447">(866) 845-7447</a></p>
                    <a href="https://maps.google.com/?q=1163+E+50+S+Logan+UT+84321" target="_blank" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-map"></i> Get Directions
                    </a>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="location-card">
                    <h4><i class="fas fa-envelope"></i> Email Support</h4>
                    <p><strong style="color: var(--text-primary);">General Inquiries</strong><br>
                    <a href="mailto:info@shipcannon.com">info@shipcannon.com</a><br><br>
                    <strong style="color: var(--text-primary);">Technical Support</strong><br>
                    <a href="mailto:support@shipcannon.com">support@shipcannon.com</a></p>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="location-card">
                    <h4><i class="fas fa-clock"></i> Business Hours</h4>
                    <p><strong style="color: var(--text-primary);">Monday - Friday</strong><br>
                    8:00 AM - 6:00 PM MST<br><br>
                    <strong style="color: var(--text-primary);">Weekends</strong><br>
                    Emergency support available</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section class="section-dark">
    <div class="container">
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <div class="text-center mb-5">
                    <h2 class="section-title">Frequently Asked Questions</h2>
                    <p class="section-subtitle">Quick answers to common questions</p>
                </div>

                <div class="accordion" id="faqAccordion">
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="faq1">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapse1">
                                How quickly can I get started with CannonWMS?
                            </button>
                        </h2>
                        <div id="collapse1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                Most businesses are live within days. Our team handles setup, configuration, and channel connections. You get a dedicated specialist who guides you through inventory import, warehouse mapping, and user setup so your staff can focus on shipping orders.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="faq2">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse2">
                                Can you integrate with my ERP or sales channels?
                            </button>
                        </h2>
                        <div id="collapse2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                CannonWMS ships with native adapters for Shopify, WooCommerce, and Miva. We also build custom integrations for ERPs like NetSuite, SAP, Sage, QuickBooks Online, and Zoho Books as part of implementation. If your system has an API, we connect it.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="faq3">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse3">
                                How does pricing work?
                            </button>
                        </h2>
                        <div id="collapse3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                CannonWMS uses transparent, usage-based billing: a $450/month base platform fee plus metered rates for warehouses ($149/ea), users ($2.83/ea), and shipments ($0.01/ea). Use our <a href="/pricing" style="color: var(--primary-color);">pricing calculator</a> to see your exact cost. Implementation and custom integrations are quoted separately and are negotiable.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="faq4">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse4">
                                What if I need to cancel?
                            </button>
                        </h2>
                        <div id="collapse4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                We offer a 60-day free trial with no credit card required, so you can test everything risk-free. After that, there are no long-term contracts &mdash; you can cancel anytime with 30 days notice. We'll help you export all your data if needed.
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <h2 class="accordion-header" id="faq5">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse5">
                                Do you offer training for my warehouse team?
                            </button>
                        </h2>
                        <div id="collapse5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                Yes. We provide onboarding training for your entire team, role-specific documentation, and ongoing support. The system is designed with a low training threshold &mdash; pickers and packers can be productive within hours, not weeks.
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


<script>

// Behavior scoring — tracks real human interaction signals
(function() {
    const pageLoad = Date.now();
    let score = 0;
    let hasMoved = false, hasScrolled = false, hasTyped = false, hasClicked = false, hasFocused = false;

    document.addEventListener('mousemove', function() {
        if (!hasMoved) { score += 20; hasMoved = true; }
    }, { passive: true });

    document.addEventListener('scroll', function() {
        if (!hasScrolled) { score += 20; hasScrolled = true; }
    }, { passive: true });

    document.addEventListener('keydown', function() {
        if (!hasTyped) { score += 20; hasTyped = true; }
    }, { passive: true });

    document.addEventListener('click', function() {
        if (!hasClicked) { score += 10; hasClicked = true; }
    }, { passive: true });

    // Track focus on any form field
    document.querySelectorAll('#contactForm input, #contactForm textarea, #contactForm select').forEach(function(el) {
        el.addEventListener('focus', function() {
            if (!hasFocused) { score += 15; hasFocused = true; }
        }, { once: true });
    });

    // Touch events for mobile
    document.addEventListener('touchstart', function() {
        if (!hasMoved) { score += 20; hasMoved = true; }
        if (!hasClicked) { score += 10; hasClicked = true; }
    }, { passive: true, once: true });

    // Inject score + time-on-page into hidden fields on submit
    var form = document.getElementById('contactForm');
    if (form) {
        form.addEventListener('submit', function() {
            var elapsed = Math.round((Date.now() - pageLoad) / 1000);
            // Bonus for spending at least 5 seconds on page
            if (elapsed >= 5) score += 15;
            document.getElementById('_bscore').value = score;
            document.getElementById('_btime').value = elapsed;
        });
    }
})();
</script>
