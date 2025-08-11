<div class="container">
    <div class="row">
        <div class="col-md-3">
            <!-- Sidebar Navigation -->
            <div class="sticky-top pt-3">
                <h5>Documentation</h5>
                <div class="list-group">
                    <a href="/docs" class="list-group-item list-group-item-action active">
                        <i class="bi bi-book"></i> README
                    </a>
                    <a href="/docs/api" class="list-group-item list-group-item-action">
                        <i class="bi bi-code-slash"></i> API Reference
                    </a>
                    <a href="/docs/cli" class="list-group-item list-group-item-action">
                        <i class="bi bi-terminal"></i> CLI Reference
                    </a>
                    <a href="/help" class="list-group-item list-group-item-action">
                        <i class="bi bi-question-circle"></i> Help Center
                    </a>
                </div>
                
                <div class="mt-4">
                    <h6>Quick Links</h6>
                    <ul class="list-unstyled small">
                        <li><a href="#features">Features</a></li>
                        <li><a href="#quick-start">Quick Start</a></li>
                        <li><a href="#project-structure">Project Structure</a></li>
                        <li><a href="#creating-controllers">Creating Controllers</a></li>
                        <li><a href="#cli-support">CLI Support</a></li>
                        <li><a href="#deployment-checklist">Deployment</a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="col-md-9">
            <!-- Documentation Content -->
            <div class="documentation-content">
                <?= $content ?>
            </div>
            
            <!-- Back to top button -->
            <button id="backToTop" class="btn btn-primary btn-sm" style="position: fixed; bottom: 20px; right: 20px; display: none;">
                <i class="bi bi-arrow-up"></i> Top
            </button>
        </div>
    </div>
</div>

<style>
.documentation-content {
    font-size: 16px;
    line-height: 1.6;
    padding: 20px 0;
}

.documentation-content h1 {
    color: #333;
    border-bottom: 2px solid #007bff;
    padding-bottom: 10px;
    margin-bottom: 20px;
}

.documentation-content h2 {
    color: #444;
    margin-top: 30px;
    margin-bottom: 15px;
    padding-left: 10px;
    border-left: 4px solid #007bff;
}

.documentation-content h3 {
    color: #555;
    margin-top: 20px;
    margin-bottom: 10px;
}

.documentation-content pre {
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 15px;
    overflow-x: auto;
}

.documentation-content code {
    background-color: #f8f9fa;
    padding: 2px 6px;
    border-radius: 3px;
    color: #e83e8c;
}

.documentation-content pre code {
    background-color: transparent;
    padding: 0;
    color: inherit;
}

.documentation-content ul {
    margin-bottom: 15px;
}

.documentation-content li {
    margin-bottom: 5px;
}

.documentation-content table {
    width: 100%;
    margin-bottom: 20px;
}

.documentation-content blockquote {
    border-left: 4px solid #007bff;
    padding-left: 15px;
    margin-left: 0;
    font-style: italic;
    color: #666;
}

.sticky-top {
    top: 20px;
}

.list-group-item.active {
    background-color: #007bff;
    border-color: #007bff;
}
</style>

<script>
// Back to top button
window.onscroll = function() {
    const backToTop = document.getElementById('backToTop');
    if (document.body.scrollTop > 100 || document.documentElement.scrollTop > 100) {
        backToTop.style.display = 'block';
    } else {
        backToTop.style.display = 'none';
    }
};

document.getElementById('backToTop').onclick = function() {
    document.body.scrollTop = 0;
    document.documentElement.scrollTop = 0;
};

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});
</script>