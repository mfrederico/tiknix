
<style>
    .pricing-hero {
        background: linear-gradient(135deg, var(--dark-color) 0%, #1a1f2e 50%, var(--dark-color) 100%);
        color: white;
        padding: 100px 0 60px;
        position: relative;
        overflow: hidden;
    }

    .pricing-hero::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -20%;
        width: 80%;
        height: 200%;
        background: radial-gradient(ellipse, rgba(255,107,53,0.06) 0%, transparent 70%);
        pointer-events: none;
    }

    /* Calculator */
    .calc-container {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 20px;
        padding: 3rem;
        max-width: 900px;
        margin: 0 auto;
    }

    .calc-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1.25rem 0;
        border-bottom: 1px solid var(--dark-border);
    }

    .calc-row:last-child {
        border-bottom: none;
    }

    .calc-label {
        flex: 1;
    }

    .calc-label h4 {
        font-size: 1.05rem;
        font-weight: 600;
        color: #fff;
        margin-bottom: 4px;
    }

    .calc-label p {
        font-size: 0.85rem;
        color: var(--text-secondary);
        margin: 0;
    }

    .calc-input {
        width: 140px;
        text-align: center;
    }

    .calc-input input[type="range"] {
        width: 100%;
        accent-color: var(--primary-color);
    }

    .calc-input .value-display {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--primary-color);
        text-align: center;
    }

    .calc-price {
        width: 120px;
        text-align: right;
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-primary);
    }

    .calc-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1.5rem 0 0;
        margin-top: 0.5rem;
        border-top: 2px solid var(--primary-color);
    }

    .calc-total-label {
        font-size: 1.25rem;
        font-weight: 700;
        color: #fff;
    }

    .calc-total-price {
        font-size: 2.5rem;
        font-weight: 900;
        color: var(--primary-color);
    }

    .calc-total-price sub {
        font-size: 1rem;
        font-weight: 500;
        color: var(--text-secondary);
    }

    /* Rate cards */
    .rate-card {
        background: var(--dark-surface);
        border: 1px solid var(--dark-border);
        border-radius: 12px;
        padding: 1.5rem;
        text-align: center;
        transition: all 0.3s ease;
    }

    .rate-card:hover {
        border-color: var(--primary-color);
    }

    .rate-value {
        font-size: 1.75rem;
        font-weight: 800;
        color: var(--primary-color);
        margin-bottom: 4px;
    }

    .rate-label {
        font-size: 0.85rem;
        color: var(--text-secondary);
        font-weight: 500;
    }

    .rate-unit {
        font-size: 0.75rem;
        color: var(--text-secondary);
    }

    /* Number input styling */
    .num-input {
        background: var(--dark-color);
        border: 1px solid var(--dark-border);
        color: var(--primary-color);
        font-size: 1.25rem;
        font-weight: 700;
        text-align: center;
        border-radius: 8px;
        padding: 8px 12px;
        width: 100%;
        outline: none;
        transition: border-color 0.3s ease;
        -moz-appearance: textfield;
    }

    .num-input::-webkit-outer-spin-button,
    .num-input::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }

    .num-input:focus {
        border-color: var(--primary-color);
    }

    /* Implementation note */
    .impl-note {
        background: rgba(255,107,53,0.08);
        border: 1px solid rgba(255,107,53,0.2);
        border-radius: 12px;
        padding: 1.5rem;
        margin-top: 2rem;
    }

    @media (max-width: 768px) {
        .calc-container {
            padding: 1.5rem;
        }

        .calc-row {
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .calc-label {
            flex: 1 0 100%;
        }

        .calc-input, .calc-price {
            width: auto;
            flex: 1;
        }
    }
</style>

<!-- Pricing Hero -->
<section class="pricing-hero">
    <div class="container position-relative">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8">
                <h1 style="font-size: 3rem; font-weight: 900; margin-bottom: 1rem;">Pricing Calculator</h1>
                <p style="font-size: 1.2rem; color: var(--text-secondary); max-width: 600px; margin: 0 auto;">Transparent, usage-based billing. Adjust the sliders to match your operation and see your exact monthly cost &mdash; no sales call required.</p>
            </div>
        </div>
    </div>
</section>

<!-- Rate Schedule -->
<section class="section-dark" style="padding: 60px 0 40px;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <h3 style="text-align: center; font-weight: 700; color: #fff; margin-bottom: 2rem;">Rate Schedule</h3>
                <div class="row g-3">
                    <div class="col-md col-6">
                        <div class="rate-card">
                            <div class="rate-value">$450</div>
                            <div class="rate-label">Base Platform Fee</div>
                            <div class="rate-unit">per month</div>
                        </div>
                    </div>
                    <div class="col-md col-6">
                        <div class="rate-card">
                            <div class="rate-value">$149</div>
                            <div class="rate-label">Warehouse Instance</div>
                            <div class="rate-unit">per warehouse / month</div>
                        </div>
                    </div>
                    <div class="col-md col-6">
                        <div class="rate-card">
                            <div class="rate-value">$2.83</div>
                            <div class="rate-label">Access Account</div>
                            <div class="rate-unit">per user / month</div>
                        </div>
                    </div>
                    <div class="col-md col-6">
                        <div class="rate-card">
                            <div class="rate-value">$0.01</div>
                            <div class="rate-label">Shipment Processed</div>
                            <div class="rate-unit">per shipment</div>
                        </div>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-12">
                        <div class="rate-card" style="text-align: left; display: flex; align-items: center; justify-content: center; gap: 2rem; flex-wrap: wrap;">
                            <div style="text-align: center;">
                                <div class="rate-label" style="margin-bottom: 4px;">Data Storage</div>
                            </div>
                            <div style="font-size: 0.9rem; color: var(--text-secondary);">
                                <span style="color: var(--accent-green); font-weight: 600;">First 100 MB free</span>
                                &nbsp;&bull;&nbsp; 100 MB &ndash; 1 GB: $0.50/MB
                                &nbsp;&bull;&nbsp; Above 1 GB: $0.25/MB
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Calculator -->
<section class="section-dark" style="padding: 20px 0 80px;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="calc-container">
                    <h3 style="text-align: center; font-weight: 700; color: #fff; margin-bottom: 0.5rem;">Build Your Monthly Price</h3>
                    <p style="text-align: center; color: var(--text-secondary); margin-bottom: 2rem; font-size: 0.95rem;">Adjust the values below to match your warehouse operation</p>

                    <!-- Base Fee (always included) -->
                    <div class="calc-row">
                        <div class="calc-label">
                            <h4><i class="fas fa-server" style="color: var(--primary-color); margin-right: 8px;"></i>Base Platform Fee</h4>
                            <p>Core platform, managed hosting, updates, and support</p>
                        </div>
                        <div class="calc-input">
                            <div class="value-display">1</div>
                        </div>
                        <div class="calc-price">$450.00</div>
                    </div>

                    <!-- Warehouses -->
                    <div class="calc-row">
                        <div class="calc-label">
                            <h4><i class="fas fa-warehouse" style="color: var(--primary-color); margin-right: 8px;"></i>Warehouse Instances</h4>
                            <p>$149.00 per warehouse / month</p>
                        </div>
                        <div class="calc-input">
                            <input type="number" class="num-input" id="warehouses" value="1" min="1" max="20">
                        </div>
                        <div class="calc-price" id="warehouse-cost">$149.00</div>
                    </div>

                    <!-- Users -->
                    <div class="calc-row">
                        <div class="calc-label">
                            <h4><i class="fas fa-users" style="color: var(--primary-color); margin-right: 8px;"></i>Active Users</h4>
                            <p>$2.83 per user / month</p>
                        </div>
                        <div class="calc-input">
                            <input type="number" class="num-input" id="users" value="5" min="1" max="500">
                        </div>
                        <div class="calc-price" id="user-cost">$14.15</div>
                    </div>

                    <!-- Shipments -->
                    <div class="calc-row">
                        <div class="calc-label">
                            <h4><i class="fas fa-truck-fast" style="color: var(--primary-color); margin-right: 8px;"></i>Monthly Shipments</h4>
                            <p>$0.01 per shipment processed</p>
                        </div>
                        <div class="calc-input">
                            <input type="number" class="num-input" id="shipments" value="1000" min="0" max="999999" step="100">
                        </div>
                        <div class="calc-price" id="shipment-cost">$10.00</div>
                    </div>

                    <!-- Storage -->
                    <div class="calc-row">
                        <div class="calc-label">
                            <h4><i class="fas fa-database" style="color: var(--primary-color); margin-right: 8px;"></i>Data Storage (MB)</h4>
                            <p>First 100 MB free, then tiered pricing</p>
                        </div>
                        <div class="calc-input">
                            <input type="number" class="num-input" id="storage" value="50" min="0" max="10000" step="10">
                        </div>
                        <div class="calc-price" id="storage-cost">$0.00</div>
                    </div>

                    <!-- Total -->
                    <div class="calc-total">
                        <div class="calc-total-label">Estimated Monthly Total</div>
                        <div class="calc-total-price" id="total-price">$623.15<sub>/mo</sub></div>
                    </div>

                    <!-- Implementation Note -->
                    <div class="impl-note">
                        <div class="d-flex align-items-start gap-3">
                            <div style="flex-shrink: 0; color: var(--primary-color); font-size: 1.25rem;"><i class="fas fa-info-circle"></i></div>
                            <div>
                                <strong style="color: #fff;">Implementation &amp; Custom Integrations</strong>
                                <p style="color: var(--text-secondary); margin: 0.5rem 0 0; font-size: 0.9rem;">One-time implementation costs cover setup, configuration, data migration, and any custom integrations your business needs (ERP, marketplace, proprietary systems). Implementation pricing is negotiable and scoped to your specific requirements. <a href="/contact-us" style="color: var(--primary-color); text-decoration: underline;">Contact us for a custom quote.</a></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CTA -->
                <div class="text-center mt-5">
                    <div class="d-flex justify-content-center gap-3 flex-wrap">
                        <a href="/get-started" class="btn btn-primary btn-lg">
                            <i class="fas fa-rocket"></i> Start 60-Day Free Trial
                        </a>
                        <a href="/contact-us" class="btn btn-outline-primary btn-lg">
                            <i class="fas fa-comments"></i> Talk to Our Team
                        </a>
                    </div>
                    <div class="mt-3">
                        <small style="color: var(--text-secondary);"><i class="fas fa-lock"></i> No credit card required &bull; 60-day free trial &bull; Cancel anytime</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- What's Included -->
<section class="section-surface">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8 text-center">
                <h2 class="section-title">Everything Included, No Add-Ons</h2>
                <p class="section-subtitle">Unlike competitors that charge extra for reporting, integrations, or support tiers, every CannonWMS plan includes the full feature set.</p>
            </div>
        </div>

        <div class="row g-4 justify-content-center">
            <div class="col-lg-3 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-boxes-stacked"></i></div>
                    <h3 class="feature-title">Inventory Management</h3>
                    <p class="feature-description">Real-time tracking, cycle counts, bin locations, adjustments, and reorder alerts.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-route"></i></div>
                    <h3 class="feature-title">Pick Tours</h3>
                    <p class="feature-description">Batch picking with optimized tour paths, barcode scanning, and verification.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                    <h3 class="feature-title">Full Reporting</h3>
                    <p class="feature-description">Dashboard, inventory, orders, shipping, performance, and balancing reports.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-shipping-fast"></i></div>
                    <h3 class="feature-title">Multi-Carrier Shipping</h3>
                    <p class="feature-description">Rate shop across carriers via EasyPost and Shippo. Print labels from the platform.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-store"></i></div>
                    <h3 class="feature-title">Channel Integrations</h3>
                    <p class="feature-description">Shopify, WooCommerce, Miva native. Custom channels built during implementation.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-code"></i></div>
                    <h3 class="feature-title">REST API &amp; Webhooks</h3>
                    <p class="feature-description">Full API access with Bearer auth, rate limiting, and event webhooks.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-user-shield"></i></div>
                    <h3 class="feature-title">Custom User Roles</h3>
                    <p class="feature-description">Admin, manager, picker, viewer, or custom permission sets per user.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-undo"></i></div>
                    <h3 class="feature-title">Returns Management</h3>
                    <p class="feature-description">Full return order processing with inventory restock workflows.</p>
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
                        <a href="/get-started" class="btn btn-light btn-lg w-100">
                            <i class="fas fa-rocket"></i> Start Free Trial
                        </a>
                    </div>
                    <div class="col-md-5">
                        <a href="/contact-us" class="btn btn-outline-light btn-lg w-100">
                            <i class="fas fa-phone"></i> Schedule a Demo
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

<!-- Calculator JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const RATES = {
        base: 450.00,
        warehouse: 149.00,
        user: 2.83,
        shipment: 0.01,
        storage: {
            tiers: [
                { up_to_mb: 100, price_per_mb: 0.00 },
                { up_to_mb: 1024, price_per_mb: 0.50 },
                { up_to_mb: null, price_per_mb: 0.25 }
            ]
        }
    };

    function calculateStorageCost(mb) {
        let cost = 0;
        let remaining = mb;

        for (const tier of RATES.storage.tiers) {
            if (remaining <= 0) break;

            if (tier.up_to_mb === null) {
                cost += remaining * tier.price_per_mb;
                remaining = 0;
            } else {
                const billable = Math.min(remaining, tier.up_to_mb);
                cost += billable * tier.price_per_mb;
                remaining -= billable;
            }
        }

        return Math.round(cost * 100) / 100;
    }

    function formatCurrency(amount) {
        return '$' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function calculate() {
        const warehouses = Math.max(1, parseInt(document.getElementById('warehouses').value) || 1);
        const users = Math.max(1, parseInt(document.getElementById('users').value) || 1);
        const shipments = Math.max(0, parseInt(document.getElementById('shipments').value) || 0);
        const storage = Math.max(0, parseInt(document.getElementById('storage').value) || 0);

        const warehouseCost = warehouses * RATES.warehouse;
        const userCost = Math.round(users * RATES.user * 100) / 100;
        const shipmentCost = Math.round(shipments * RATES.shipment * 100) / 100;
        const storageCost = calculateStorageCost(storage);

        const total = RATES.base + warehouseCost + userCost + shipmentCost + storageCost;

        document.getElementById('warehouse-cost').textContent = formatCurrency(warehouseCost);
        document.getElementById('user-cost').textContent = formatCurrency(userCost);
        document.getElementById('shipment-cost').textContent = formatCurrency(shipmentCost);
        document.getElementById('storage-cost').textContent = formatCurrency(storageCost);
        document.getElementById('total-price').innerHTML = formatCurrency(total) + '<sub>/mo</sub>';
    }

    // Attach event listeners
    ['warehouses', 'users', 'shipments', 'storage'].forEach(function(id) {
        document.getElementById(id).addEventListener('input', calculate);
        document.getElementById(id).addEventListener('change', calculate);
    });

    // Initial calculation
    calculate();
});
</script>
