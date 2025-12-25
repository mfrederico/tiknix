<!-- Hero Section -->
<div class="bg-primary text-white py-5">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <h1 class="display-4 fw-bold">Welcome to Tiknix</h1>
                <p class="lead">A modern PHP foundation with clean architecture and pluggable components. Designed for clarity - whether you're coding solo or with AI assistance.</p>
                <div class="d-grid gap-2 d-md-flex">
                    <?php if (!$isLoggedIn): ?>
                        <a href="https://github.com/mfrederico/tiknix/" class="btn btn-light btn-lg">Get Started</a>
                        <a href="/auth/login" class="btn btn-outline-light btn-lg">Login</a>
                    <?php else: ?>
                        <a href="/dashboard" class="btn btn-light btn-lg">Go to Dashboard</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-6 text-center">
                <i class="bi bi-robot" style="font-size: 8rem; opacity: 0.8;"></i>
            </div>
        </div>
    </div>
</div>

<!-- Tutorials Callout -->
<div class="bg-success text-white py-4">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h3 class="mb-1"><i class="bi bi-mortarboard"></i> New to Tiknix?</h3>
                <p class="mb-0">Build a complete multi-user TODO app in ~90 minutes with our step-by-step tutorial.</p>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <a href="/docs/tutorials" class="btn btn-light btn-lg">
                    <i class="bi bi-play-circle"></i> Start Tutorial
                </a>
            </div>
        </div>
    </div>
</div>

<!-- AI-First Section -->
<div class="bg-dark text-white py-5">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h2><i class="bi bi-cpu"></i> AI-Friendly Architecture</h2>
                <p class="lead">Clean code organization that works well with AI coding assistants. No magic - just good architecture that helps humans and AI alike.</p>
                <ul class="list-unstyled">
                    <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Predictable file locations reduce searching</li>
                    <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Convention over configuration - URLs map to controllers</li>
                    <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Small, focused files - read only what you need</li>
                    <li class="mb-2"><i class="bi bi-check-circle text-success"></i> Explicit patterns over implicit magic</li>
                </ul>
                <p class="small text-muted mt-3"><i class="bi bi-info-circle"></i> Honest note: This won't magically reduce token counts. It's simply clean architecture that's easier for anyone to navigate - human or AI.</p>
            </div>
            <div class="col-md-6">
                <div class="card bg-secondary">
                    <div class="card-body">
                        <code class="text-light">
                            <small>
                            // Predictable structure<br>
                            /controls &nbsp;&nbsp;- Controllers<br>
                            /views &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;- Templates<br>
                            /lib &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;- Core libraries<br>
                            /lib/plugins - Pluggable modules<br>
                            /conf &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;- Configuration<br>
                            <br>
                            // URL → Controller mapping<br>
                            /auth/google → Auth::google()<br>
                            /member/profile → Member::profile()<br>
                            </small>
                        </code>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MCP Gateway Section -->
<div class="bg-info bg-gradient text-white py-5">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h2><i class="bi bi-diagram-3"></i> MCP Gateway</h2>
                <p class="lead">One endpoint to rule them all. Tiknix acts as a gateway that aggregates multiple MCP servers into a single, authenticated endpoint for AI agents.</p>

                <div class="mb-4">
                    <h5><i class="bi bi-question-circle"></i> Why a Gateway?</h5>
                    <p class="small opacity-90">AI assistants like Claude Code need to connect to MCP servers to use tools. Without a gateway, you'd need to configure each server separately, manage multiple API keys, and handle authentication for each one. Tiknix solves this.</p>
                </div>

                <ul class="list-unstyled">
                    <li class="mb-2"><i class="bi bi-check-circle"></i> <strong>Single Config</strong> - One endpoint, one API key for all your tools</li>
                    <li class="mb-2"><i class="bi bi-check-circle"></i> <strong>Tool Namespacing</strong> - No collisions (<code class="bg-dark px-1">shopify:get_products</code>, <code class="bg-dark px-1">github:list_repos</code>)</li>
                    <li class="mb-2"><i class="bi bi-check-circle"></i> <strong>Access Control</strong> - Restrict API keys to specific servers</li>
                    <li class="mb-2"><i class="bi bi-check-circle"></i> <strong>Usage Analytics</strong> - Track every tool call</li>
                </ul>
                <div class="mt-4">
                    <a href="/mcp" class="btn btn-light btn-lg me-2">
                        <i class="bi bi-gear"></i> MCP Dashboard
                    </a>
                    <a href="/docs/mcp" class="btn btn-outline-light btn-lg">
                        <i class="bi bi-book"></i> Documentation
                    </a>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card bg-dark border-0 shadow mb-3">
                    <div class="card-header bg-dark text-light border-bottom border-secondary">
                        <i class="bi bi-diagram-3"></i> How It Works
                    </div>
                    <div class="card-body py-3">
                        <pre class="text-light mb-0 small"><code>Claude Code ──► Tiknix Gateway ──► Backend Servers
                    │
                    ├── tiknix:hello
                    ├── shopify:get_products
                    ├── github:list_repos
                    └── your-server:custom_tool</code></pre>
                    </div>
                </div>
                <div class="card bg-dark border-0 shadow">
                    <div class="card-header bg-dark text-light border-bottom border-secondary">
                        <i class="bi bi-file-code"></i> .mcp.json
                    </div>
                    <div class="card-body">
                        <pre class="text-light mb-0" style="font-size: 0.8rem;"><code>{
  "mcpServers": {
    "tiknix": {
      "type": "http",
      "url": "https://tiknix.com/mcp/message",
      "headers": {
        "Authorization": "Bearer YOUR_API_KEY"
      }
    }
  }
}</code></pre>
                    </div>
                </div>
                <p class="text-center mt-2 small opacity-75">
                    <i class="bi bi-lightning-charge"></i> Get your config at <a href="/apikeys" class="text-white">/apikeys</a>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Features Section -->
<div class="py-5">
    <div class="container">
        <h2 class="text-center mb-5">Core Features</h2>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-google text-danger" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">Google OAuth</h4>
                        <p class="card-text">One-click sign in with Google. Secure OAuth 2.0 authentication out of the box.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-plug text-primary" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">Pluggable Architecture</h4>
                        <p class="card-text">Drop-in plugins for authentication, payments, notifications, and more. Extend without modifying core.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-shield-check text-success" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">Role-Based Permissions</h4>
                        <p class="card-text">Granular permission system with auto-discovery. Protect routes with zero configuration.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-lightning-charge text-warning" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">Auto-Routing</h4>
                        <p class="card-text">URLs automatically map to controllers. No route files to maintain.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-database text-info" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">Zero-Config ORM</h4>
                        <p class="card-text">RedBeanPHP creates tables and columns on the fly. Just write code.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-speedometer2 text-primary" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">APCu Caching</h4>
                        <p class="card-text">High-performance permission caching with automatic invalidation.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 shadow-sm border-info">
                    <div class="card-body text-center">
                        <i class="bi bi-diagram-3 text-info" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">MCP Gateway</h4>
                        <p class="card-text">Aggregate multiple MCP servers into one endpoint. Single config, centralized auth.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-key text-secondary" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">Scoped API Keys</h4>
                        <p class="card-text">Generate API keys with granular permissions. Control exactly what each key can access.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Plugin Architecture -->
<div class="bg-light py-5">
    <div class="container">
        <h2 class="text-center mb-5">Pluggable by Design</h2>
        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="card border-success">
                            <div class="card-header bg-success text-white">
                                <i class="bi bi-check-circle"></i> Included Plugins
                            </div>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item d-flex justify-content-between">
                                    <span><i class="bi bi-diagram-3"></i> MCP Gateway</span>
                                    <span class="badge bg-success">Active</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span><i class="bi bi-google"></i> GoogleAuth</span>
                                    <span class="badge bg-success">Active</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span><i class="bi bi-shield-lock"></i> PermissionCache</span>
                                    <span class="badge bg-success">Active</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between">
                                    <span><i class="bi bi-key"></i> Scoped API Keys</span>
                                    <span class="badge bg-success">Active</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-secondary">
                            <div class="card-header bg-secondary text-white">
                                <i class="bi bi-puzzle"></i> Easy to Add
                            </div>
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item text-muted"><i class="bi bi-github"></i> GitHubAuth</li>
                                <li class="list-group-item text-muted"><i class="bi bi-stripe"></i> StripePayments</li>
                                <li class="list-group-item text-muted"><i class="bi bi-envelope"></i> MagicLinkAuth</li>
                                <li class="list-group-item text-muted"><i class="bi bi-bell"></i> PushNotifications</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tech Stack -->
<div class="py-5">
    <div class="container">
        <h2 class="text-center mb-5">Modern Tech Stack</h2>
        <div class="row justify-content-center">
            <div class="col-md-8">
                <ul class="list-group">
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><strong>FlightPHP</strong> - Extensible micro-framework</span>
                        <span class="badge bg-primary rounded-pill">v3.0+</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><strong>RedBeanPHP</strong> - Zero-config ORM</span>
                        <span class="badge bg-primary rounded-pill">v5.7+</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><strong>Bootstrap</strong> - Responsive UI framework</span>
                        <span class="badge bg-primary rounded-pill">v5.3</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><strong>SQLite/MySQL</strong> - Flexible database support</span>
                        <span class="badge bg-primary rounded-pill">Your choice</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><strong>APCu</strong> - Shared memory caching</span>
                        <span class="badge bg-primary rounded-pill">Optional</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- CTA Section -->
<div class="bg-primary text-white py-5">
    <div class="container text-center">
        <h2 class="mb-4">Ready to Build?</h2>
        <p class="lead mb-4">A clean PHP foundation for your next project. Works great with AI assistants or without them.</p>
        <div class="d-grid gap-2 d-md-flex justify-content-md-center">
            <a href="https://github.com/mfrederico/tiknix" class="btn btn-light btn-lg">
                <i class="bi bi-github"></i> View on GitHub
            </a>
            <a href="/docs" class="btn btn-outline-light btn-lg">
                <i class="bi bi-book"></i> Documentation
            </a>
        </div>
    </div>
</div>
