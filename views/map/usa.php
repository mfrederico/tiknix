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
                    <div id="usa-map-container">
                        <div id="usa-map"></div>
                    </div>
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

<style>
#usa-map-container {
    position: relative;
    width: 100%;
    aspect-ratio: 1.6 / 1;
    min-height: 300px;
    max-height: 80vh;
    overflow: hidden;
}

#usa-map {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
    border-radius: 0.375rem;
}

#usa-map svg {
    width: 100% !important;
    height: 100% !important;
}

.datamaps-hoverover {
    position: absolute;
    background: #333;
    color: #fff;
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 12px;
    z-index: 1000;
    pointer-events: none;
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

@media (max-width: 992px) {
    #usa-map-container {
        aspect-ratio: 1.4 / 1;
    }
}

@media (max-width: 768px) {
    #usa-map-container {
        aspect-ratio: 1.2 / 1;
        min-height: 250px;
    }

    .offcanvas {
        width: 100% !important;
    }
}

@media (max-width: 576px) {
    #usa-map-container {
        aspect-ratio: 1 / 1;
        min-height: 200px;
    }
}
</style>

<!-- D3.js and DataMaps -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/d3/3.5.17/d3.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/topojson/1.6.20/topojson.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/datamaps/0.5.9/datamaps.usa.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const states = <?= json_encode($states) ?>;
    const mapContainer = document.getElementById('usa-map');
    let map = null;
    let resizeTimeout = null;

    // Debounce function to limit resize calls
    function debounce(func, wait) {
        return function executedFunction(...args) {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    // Initialize or reinitialize the map
    function initMap() {
        // Clear existing map if present
        if (map) {
            mapContainer.innerHTML = '';
        }

        map = new Datamap({
            element: mapContainer,
            scope: 'usa',
            responsive: true,
            fills: {
                defaultFill: '#4a90d9'
            },
            geographyConfig: {
                highlightOnHover: true,
                highlightFillColor: '#67b26f',
                highlightBorderColor: '#1a1a2e',
                highlightBorderWidth: 1,
                popupOnHover: true,
                popupTemplate: function(geo) {
                    return '<div class="datamaps-hoverover">' + geo.properties.name + '</div>';
                }
            },
            done: function(datamap) {
                datamap.svg.selectAll('.datamaps-subunit').on('click', function(geo) {
                    const stateCode = geo.id;
                    showStateDetails(stateCode);
                });
            }
        });
    }

    // Handle window resize with debouncing
    const handleResize = debounce(function() {
        if (map) {
            map.resize();
        }
    }, 150);

    window.addEventListener('resize', handleResize);

    // Initialize map
    initMap();

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
