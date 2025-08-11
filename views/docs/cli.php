<div class="container">
    <div class="row">
        <div class="col-md-3">
            <!-- Sidebar Navigation -->
            <div class="sticky-top pt-3">
                <h5>Documentation</h5>
                <div class="list-group">
                    <a href="/docs" class="list-group-item list-group-item-action">
                        <i class="bi bi-book"></i> README
                    </a>
                    <a href="/docs/api" class="list-group-item list-group-item-action">
                        <i class="bi bi-code-slash"></i> API Reference
                    </a>
                    <a href="/docs/cli" class="list-group-item list-group-item-action active">
                        <i class="bi bi-terminal"></i> CLI Reference
                    </a>
                    <a href="/help" class="list-group-item list-group-item-action">
                        <i class="bi bi-question-circle"></i> Help Center
                    </a>
                </div>
            </div>
        </div>
        
        <div class="col-md-9">
            <h1>CLI Documentation</h1>
            
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> The TikNix framework includes comprehensive CLI support for running controllers from the command line, perfect for cron jobs and background tasks.
            </div>
            
            <?= $content ?>
        </div>
    </div>
</div>

<style>
.sticky-top {
    top: 20px;
}

pre {
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 15px;
    overflow-x: auto;
}

code {
    color: #e83e8c;
}

pre code {
    color: inherit;
}

h2 {
    margin-top: 30px;
    padding-bottom: 10px;
    border-bottom: 1px solid #dee2e6;
}

h3, h4 {
    margin-top: 20px;
    color: #495057;
}

.table {
    margin-top: 20px;
}
</style>