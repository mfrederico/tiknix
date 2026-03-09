<style>
    .case-study-hero {
        background: linear-gradient(135deg, var(--dark-color) 0%, var(--dark-surface) 100%);
        color: white;
        padding: 120px 0 80px;
        position: relative;
        overflow: hidden;
    }

    .case-study-hero::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><polygon fill="%23ff6b35" fill-opacity="0.05" points="0,0 1000,300 1000,1000 0,700"/></svg>');
        background-size: cover;
    }

    .case-study-content {
        position: relative;
        z-index: 2;
    }

    .hero-title {
        font-size: 3.5rem;
        font-weight: 800;
        margin-bottom: 1rem;
        background: linear-gradient(45deg, #ff6b35, #f7931e);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        line-height: 1.2;
    }

    .hero-subtitle {
        font-size: 1.3rem;
        margin-bottom: 2rem;
        color: var(--text-secondary);
    }

    .stats-highlight {
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid var(--dark-border);
        backdrop-filter: blur(10px);
        border-radius: 16px;
        padding: 30px;
        margin: 30px 0;
        text-align: center;
    }

    .stat-number {
        font-size: 2.5rem;
        font-weight: 800;
        color: var(--primary-color);
        margin-bottom: 5px;
    }

    .stat-label {
        font-size: 1.1rem;
        color: var(--text-secondary);
    }

    .timeline-item {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 16px;
        padding: 30px;
        margin-bottom: 24px;
        position: relative;
        border-left: 4px solid var(--primary-color);
        transition: border-color 0.3s ease;
    }

    .timeline-item:hover {
        border-color: var(--primary-color);
    }

    .timeline-year {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 6px 16px;
        border-radius: 20px;
        font-weight: 600;
        display: inline-block;
        margin-bottom: 15px;
        font-size: 0.9rem;
    }

    .timeline-title {
        font-size: 1.5rem;
        font-weight: 600;
        margin-bottom: 15px;
        color: #fff;
    }

    .timeline-item p,
    .timeline-item li {
        color: var(--text-secondary);
    }

    .quote-section {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 80px 0;
        text-align: center;
    }

    .quote-text {
        font-size: 2rem;
        font-style: italic;
        margin-bottom: 30px;
        line-height: 1.4;
    }

    .quote-author {
        font-size: 1.2rem;
        font-weight: 600;
    }

    .results-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 24px;
        margin: 50px 0;
    }

    .result-card {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 16px;
        padding: 30px;
        text-align: center;
        transition: transform 0.3s ease, border-color 0.3s ease;
    }

    .result-card:hover {
        transform: translateY(-5px);
        border-color: var(--primary-color);
    }

    .result-icon {
        font-size: 3rem;
        color: var(--primary-color);
        margin-bottom: 20px;
    }

    .result-number {
        font-size: 2.5rem;
        font-weight: 800;
        color: var(--primary-color);
        margin-bottom: 10px;
    }

    .result-label {
        font-size: 1.1rem;
        color: var(--text-secondary);
        font-weight: 500;
    }

    .feature-item {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 16px;
        padding: 25px;
        margin-bottom: 20px;
        border-left: 4px solid var(--primary-color);
    }

    .feature-title {
        font-size: 1.3rem;
        font-weight: 600;
        margin-bottom: 10px;
        color: #fff;
    }

    .feature-item li {
        color: var(--text-secondary);
    }

    .company-profile {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 24px;
    }

    .company-profile h4 {
        color: #fff;
    }

    .company-profile li {
        color: var(--text-secondary);
        padding: 4px 0;
    }

    .company-profile strong {
        color: #fff;
    }

    .improvements-box {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 16px;
        padding: 24px;
    }

    .improvements-box h4 {
        color: #fff;
    }

    .improvements-box li {
        color: var(--text-secondary);
        padding: 4px 0;
    }

    @media (max-width: 768px) {
        .hero-title {
            font-size: 2.5rem;
        }

        .quote-text {
            font-size: 1.5rem;
        }

        .results-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<!-- Hero Section -->
<section class="case-study-hero">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="case-study-content text-center">
                    <h1 class="hero-title">From 100 Employees to 35: How Linentablecloth.com Saved $180,000 Annually</h1>
                    <p class="hero-subtitle">Internet Retailer 500 company transforms warehouse operations with CannonWMS after years of WMS failures</p>

                    <div class="stats-highlight">
                        <div class="row g-4">
                            <div class="col-md-3">
                                <div class="stat-item">
                                    <div class="stat-number">65%</div>
                                    <div class="stat-label">Staff Reduction</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-item">
                                    <div class="stat-number">1,500</div>
                                    <div class="stat-label">Daily Orders</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-item">
                                    <div class="stat-number">$180K</div>
                                    <div class="stat-label">Annual Savings</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-item">
                                    <div class="stat-number">99.9%</div>
                                    <div class="stat-label">Accuracy Rate</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Company Overview -->
<section class="section-dark">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="text-center mb-5">
                    <h2 style="color: #fff; font-weight: 800; font-size: 2.2rem;">The Challenge</h2>
                    <p style="color: var(--text-secondary); font-size: 1.1rem;">A 12-year struggle with warehouse management systems that promised everything but delivered nothing</p>
                </div>

                <div class="company-profile">
                    <h4><i class="fas fa-building me-2" style="color: var(--primary-color);"></i>Company Profile</h4>
                    <ul class="list-unstyled mb-0">
                        <li><strong>Company:</strong> Linentablecloth.com</li>
                        <li><strong>Industry:</strong> E-commerce Linens & Textiles</li>
                        <li><strong>Ranking:</strong> Internet Retailer 500</li>
                        <li><strong>Daily Orders:</strong> 1,500+ orders per day</li>
                        <li><strong>Channels:</strong> Website, Amazon, eBay</li>
                    </ul>
                </div>

                <p style="color: var(--text-secondary); font-size: 1.15rem; line-height: 1.7;">Internet Retailer 500 Linentablecloth.com faced a 12-year history of growth challenges, with finding the right warehouse management system (WMS) being their biggest obstacle. What started as a simple fulfillment operation had become a nightmare of failed software implementations, broken promises, and skyrocketing costs.</p>
            </div>
        </div>
    </div>
</section>

<!-- Timeline Section -->
<section class="section-surface">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <h2 class="text-center mb-5" style="color: #fff; font-weight: 800; font-size: 2.2rem;">The Journey: From Failure to Success</h2>

                <div class="timeline-item">
                    <div class="timeline-year">2008</div>
                    <h3 class="timeline-title">First WMS Attempt</h3>
                    <p>When daily orders exceeded 50, Linentablecloth implemented their first "Stone Age" WMS. Limited success, but the company quickly outgrew its capabilities. The search for a better solution began.</p>
                </div>

                <div class="timeline-item">
                    <div class="timeline-year">2008-2017</div>
                    <h3 class="timeline-title">Years of Broken Promises</h3>
                    <p>Years of uncertainty trying different WMS software. Vendor promises were plentiful, but the promises always proved empty and undelivered. Each system failed to meet basic requirements, forcing constant switching and adaptation.</p>
                </div>

                <div class="timeline-item">
                    <div class="timeline-year">2017</div>
                    <h3 class="timeline-title">The Disaster</h3>
                    <p>Linentablecloth paid <strong style="color: #fff;">tens of thousands of dollars</strong> to implement a new "glitzy" WMS, plus thousands more monthly in service fees. The software proved disastrous:</p>
                    <ul>
                        <li>Employee count doubled, then tripled to <strong style="color: #fff;">over 100 employees</strong></li>
                        <li>Orders were delayed and inaccurate</li>
                        <li>Costs spiraled out of control</li>
                        <li>Customer satisfaction plummeted</li>
                    </ul>
                </div>

                <div class="timeline-item">
                    <div class="timeline-year">2018</div>
                    <h3 class="timeline-title">Back to Basics</h3>
                    <p>In desperation, Linentablecloth <strong style="color: #fff;">scrapped the expensive WMS</strong> and reverted to a simple paper picking system with inventory tracked by Google Docs. Surprisingly, this rudimentary system worked better than the expensive software.</p>
                </div>

                <div class="timeline-item">
                    <div class="timeline-year">2019</div>
                    <h3 class="timeline-title">Taking Control</h3>
                    <p>Owner Ron Berrett decided to take matters into his own hands. Partnering with business and software friends, they began building a real WMS that would actually deliver on its promises - a system that worked without a million-dollar price tag.</p>
                </div>

                <div class="timeline-item">
                    <div class="timeline-year">2020</div>
                    <h3 class="timeline-title">CannonWMS is Born</h3>
                    <p>After a year of careful planning, coding, testing, and retesting, CannonWMS was launched. Built by warehouse operators for warehouse operators, it finally delivered what other systems had promised.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Quote Section -->
<section class="quote-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="quote-text">"Finally, things just work the way they're supposed to, thanks to CannonWMS. I couldn't be happier."</div>
                <div class="quote-author">&mdash; Ron Berrett, Owner of Linentablecloth.com</div>
            </div>
        </div>
    </div>
</section>

<!-- Results Section -->
<section class="section-dark">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <h2 class="text-center mb-5" style="color: #fff; font-weight: 800; font-size: 2.2rem;">Dramatic Results with CannonWMS</h2>

                <div class="results-grid">
                    <div class="result-card">
                        <div class="result-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="result-number">65%</div>
                        <div class="result-label">Staff Reduction<br>(100 &rarr; 35 employees)</div>
                    </div>

                    <div class="result-card">
                        <div class="result-icon">
                            <i class="fas fa-shipping-fast"></i>
                        </div>
                        <div class="result-number">1,500</div>
                        <div class="result-label">Orders Per Day<br>(Up from 50)</div>
                    </div>

                    <div class="result-card">
                        <div class="result-icon">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                        <div class="result-number">$180K</div>
                        <div class="result-label">Annual Labor Savings</div>
                    </div>

                    <div class="result-card">
                        <div class="result-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="result-number">99.9%</div>
                        <div class="result-label">Order Accuracy</div>
                    </div>
                </div>

                <div class="improvements-box">
                    <h4 class="mb-3"><i class="fas fa-rocket me-2" style="color: var(--primary-color);"></i>Key Improvements</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <ul class="list-unstyled">
                                <li><i class="fas fa-check me-2" style="color: var(--primary-color);"></i>Automatic order allocation and routing</li>
                                <li><i class="fas fa-check me-2" style="color: var(--primary-color);"></i>Real-time inventory updates across all channels</li>
                                <li><i class="fas fa-check me-2" style="color: var(--primary-color);"></i>"Supermarket Checkout" order verification</li>
                                <li><i class="fas fa-check me-2" style="color: var(--primary-color);"></i>Multi-channel integration (Website, Amazon, eBay)</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="list-unstyled">
                                <li><i class="fas fa-check me-2" style="color: var(--primary-color);"></i>Leaner, happier workforce</li>
                                <li><i class="fas fa-check me-2" style="color: var(--primary-color);"></i>Dramatically reduced operational costs</li>
                                <li><i class="fas fa-check me-2" style="color: var(--primary-color);"></i>Increased revenue through efficiency</li>
                                <li><i class="fas fa-check me-2" style="color: var(--primary-color);"></i>Seamless scalability for future growth</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Features Section -->
<section class="section-surface">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <h2 class="text-center mb-5" style="color: #fff; font-weight: 800; font-size: 2.2rem;">CannonWMS Features That Made the Difference</h2>

                <div class="feature-item">
                    <h4 class="feature-title"><i class="fas fa-chart-line me-2" style="color: var(--primary-color);"></i>Advanced Reporting</h4>
                    <ul class="mb-0">
                        <li><strong style="color: #fff;">Product Velocity:</strong> Track how fast each product moves per day/week/month for better inventory management</li>
                        <li><strong style="color: #fff;">Worker Productivity:</strong> Identify top performers and optimize staffing</li>
                        <li><strong style="color: #fff;">Warehouse Productivity:</strong> Monitor daily order and case shipments</li>
                        <li><strong style="color: #fff;">HR Tools:</strong> Track attendance, punctuality, and performance metrics</li>
                    </ul>
                </div>

                <div class="feature-item">
                    <h4 class="feature-title"><i class="fas fa-warehouse me-2" style="color: var(--primary-color);"></i>Smart Warehouse Management</h4>
                    <ul class="mb-0">
                        <li><strong style="color: #fff;">Flexible Storage Options:</strong> From bins and shelving to pallet racking and mezzanines</li>
                        <li><strong style="color: #fff;">Optimized Layout Planning:</strong> Place fastest-moving products closest to shipping areas</li>
                        <li><strong style="color: #fff;">Multiple Picking Methods:</strong> Pick by order, wave picking, zone picking, and more</li>
                        <li><strong style="color: #fff;">Real-time Inventory Tracking:</strong> Always know what you have and where it is</li>
                    </ul>
                </div>

                <div class="feature-item">
                    <h4 class="feature-title"><i class="fas fa-sync me-2" style="color: var(--primary-color);"></i>Seamless Integration</h4>
                    <ul class="mb-0">
                        <li><strong style="color: #fff;">Multi-Channel Support:</strong> Website, Amazon, eBay, and more</li>
                        <li><strong style="color: #fff;">Automatic Order Routing:</strong> Orders flow to the right fulfillment center</li>
                        <li><strong style="color: #fff;">Real-time Updates:</strong> Inventory syncs across all sales channels instantly</li>
                        <li><strong style="color: #fff;">Barcode Scanning:</strong> Eliminate picking errors with mobile scanning</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="cta-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8 text-center">
                <h2 class="cta-title">Ready to Transform Your Warehouse Like Linentablecloth.com?</h2>
                <p class="cta-subtitle">Join hundreds of businesses saving thousands annually with CannonWMS's proven warehouse management system.</p>

                <div class="row g-3 justify-content-center">
                    <div class="col-md-5">
                        <a href="/pricing" class="btn btn-primary btn-lg w-100">
                            <i class="fas fa-calculator"></i> See Pricing Calculator
                        </a>
                    </div>
                    <div class="col-md-5">
                        <a href="/get-started" class="btn btn-outline-light btn-lg w-100">
                            Start Free Trial
                        </a>
                    </div>
                </div>

                <div class="mt-4">
                    <small><i class="fas fa-lock"></i> 60-day free trial &bull; No credit card required &bull; Setup in 24 hours</small>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
if (typeof gtag !== 'undefined') {
    gtag('event', 'case_study_view', {
        event_category: 'engagement',
        event_label: 'linentablecloth_case_study'
    });
}
</script>
