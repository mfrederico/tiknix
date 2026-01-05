<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <h1 class="mb-4"><i class="bi bi-geo-alt"></i> Map of the United States</h1>
            <p class="lead text-muted">Click on any state to view details</p>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body p-0">
                    <div id="usa-map" style="height: 600px; width: 100%;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- State Details Offcanvas -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="stateDetailsOffcanvas" aria-labelledby="stateDetailsLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="stateDetailsLabel">
            <span id="stateName">State Details</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <div id="stateLoading" class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
        <div id="stateContent" style="display: none;">
            <div class="mb-4">
                <span class="badge bg-primary fs-5 mb-3" id="stateCode"></span>
                <h3 id="stateFullName"></h3>
                <p class="text-muted"><strong>Capital:</strong> <span id="stateCapital"></span></p>
            </div>

            <hr>

            <div class="state-info">
                <h5>About This State</h5>
                <p id="stateLorem">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p>

                <h5 class="mt-4">Key Facts</h5>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Population
                        <span class="badge bg-secondary rounded-pill">Loading...</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Area (sq mi)
                        <span class="badge bg-secondary rounded-pill">Loading...</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        Time Zone
                        <span class="badge bg-secondary rounded-pill">Loading...</span>
                    </li>
                </ul>

                <h5 class="mt-4">Description</h5>
                <p>Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p>

                <p>Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo.</p>
            </div>
        </div>
        <div id="stateError" style="display: none;">
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i>
                <span id="stateErrorMessage">Failed to load state details.</span>
            </div>
        </div>
    </div>
</div>

<!-- jvectormap CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsvectormap@1.6.0/dist/css/jsvectormap.min.css">

<style>
#usa-map {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    border-radius: 0.375rem;
}

.jvm-container {
    touch-action: none;
}

.jvm-tooltip {
    background: #333 !important;
    color: #fff !important;
    border-radius: 4px;
    padding: 5px 10px;
    font-size: 12px;
}

.offcanvas {
    width: 400px !important;
}

.state-info h5 {
    color: #6c757d;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.75rem;
}

.list-group-item {
    background: transparent;
    border-color: rgba(255,255,255,0.1);
}

@media (max-width: 768px) {
    #usa-map {
        height: 400px !important;
    }

    .offcanvas {
        width: 100% !important;
    }
}
</style>

<!-- jvectormap JS -->
<script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.6.0/dist/jsvectormap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsvectormap@1.6.0/dist/maps/us-merc.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // State code mapping from jsvectormap to our format
    const stateCodeMap = {
        'US-AL': 'AL', 'US-AK': 'AK', 'US-AZ': 'AZ', 'US-AR': 'AR', 'US-CA': 'CA',
        'US-CO': 'CO', 'US-CT': 'CT', 'US-DE': 'DE', 'US-FL': 'FL', 'US-GA': 'GA',
        'US-HI': 'HI', 'US-ID': 'ID', 'US-IL': 'IL', 'US-IN': 'IN', 'US-IA': 'IA',
        'US-KS': 'KS', 'US-KY': 'KY', 'US-LA': 'LA', 'US-ME': 'ME', 'US-MD': 'MD',
        'US-MA': 'MA', 'US-MI': 'MI', 'US-MN': 'MN', 'US-MS': 'MS', 'US-MO': 'MO',
        'US-MT': 'MT', 'US-NE': 'NE', 'US-NV': 'NV', 'US-NH': 'NH', 'US-NJ': 'NJ',
        'US-NM': 'NM', 'US-NY': 'NY', 'US-NC': 'NC', 'US-ND': 'ND', 'US-OH': 'OH',
        'US-OK': 'OK', 'US-OR': 'OR', 'US-PA': 'PA', 'US-RI': 'RI', 'US-SC': 'SC',
        'US-SD': 'SD', 'US-TN': 'TN', 'US-TX': 'TX', 'US-UT': 'UT', 'US-VT': 'VT',
        'US-VA': 'VA', 'US-WA': 'WA', 'US-WV': 'WV', 'US-WI': 'WI', 'US-WY': 'WY',
        'US-DC': 'DC'
    };

    // Initialize the map
    const map = new jsVectorMap({
        selector: '#usa-map',
        map: 'us_merc',
        zoomOnScroll: true,
        zoomButtons: true,
        regionStyle: {
            initial: {
                fill: '#4a90d9',
                stroke: '#1a1a2e',
                strokeWidth: 0.5
            },
            hover: {
                fill: '#67b26f',
                cursor: 'pointer'
            },
            selected: {
                fill: '#ffd700'
            }
        },
        onRegionTooltipShow: function(event, tooltip, code) {
            const stateCode = stateCodeMap[code] || code.replace('US-', '');
            const states = <?= json_encode($states) ?>;
            const state = states[stateCode];
            if (state) {
                tooltip.text(state.name);
            }
        },
        onRegionClick: function(event, code) {
            const stateCode = stateCodeMap[code] || code.replace('US-', '');
            showStateDetails(stateCode);
        }
    });

    // Show state details in offcanvas
    function showStateDetails(stateCode) {
        const offcanvas = new bootstrap.Offcanvas(document.getElementById('stateDetailsOffcanvas'));

        // Show loading state
        document.getElementById('stateLoading').style.display = 'block';
        document.getElementById('stateContent').style.display = 'none';
        document.getElementById('stateError').style.display = 'none';
        document.getElementById('stateName').textContent = 'Loading...';

        offcanvas.show();

        // Fetch state details
        fetch('/map/statedetails?state=' + stateCode)
            .then(response => response.json())
            .then(data => {
                document.getElementById('stateLoading').style.display = 'none';

                if (data.success && data.state) {
                    document.getElementById('stateContent').style.display = 'block';
                    document.getElementById('stateName').textContent = data.state.name;
                    document.getElementById('stateFullName').textContent = data.state.name;
                    document.getElementById('stateCode').textContent = data.state.code;
                    document.getElementById('stateCapital').textContent = data.state.capital;
                } else {
                    document.getElementById('stateError').style.display = 'block';
                    document.getElementById('stateErrorMessage').textContent = data.message || 'State not found';
                }
            })
            .catch(error => {
                document.getElementById('stateLoading').style.display = 'none';
                document.getElementById('stateError').style.display = 'block';
                document.getElementById('stateErrorMessage').textContent = 'Failed to load state details: ' + error.message;
            });
    }
});
</script>
