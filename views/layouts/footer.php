      <!-- slim app footer (inside content column) -->
      <footer class="d-flex justify-content-between flex-wrap gap-2 pt-4 mt-4 small text-secondary"
              style="border-top:1px solid var(--bs-border-color)">
        <span>&copy; <?= date('Y') ?> <?= htmlspecialchars($site_name ?? 'Tiknix') ?></span>
        <span>Built with <i class="bi bi-heart-fill text-danger"></i> using
          <a href="https://flightphp.com" class="link-secondary text-decoration-none" target="_blank" rel="noopener">Flight</a> &amp;
          <a href="https://redbeanphp.com" class="link-secondary text-decoration-none" target="_blank" rel="noopener">RedBean</a>
        </span>
      </footer>
    </div><!-- /.ui-content -->
  </div><!-- /.ui-main -->
</div><!-- /.ui-shell -->

<!-- Toast Container for Notifications -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100">
    <div id="liveToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="toast-header">
            <i class="bi bi-info-circle me-2"></i>
            <strong class="me-auto">Notification</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body"></div>
    </div>
</div>

<!-- Back to Top Button -->
<button type="button" class="btn btn-primary btn-lg" id="btn-back-to-top"
        style="position: fixed; bottom: 20px; right: 20px; display: none; z-index: 1090; border-radius: 12px;">
    <i class="bi bi-arrow-up"></i>
</button>

<script>
// ── theme toggle (persisted; initial theme applied inline in <head>) ──────
(function () {
    var b = document.getElementById('uiThemeToggle');
    function icon() {
        var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        if (b) b.innerHTML = dark ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
    }
    icon();
    if (b) b.addEventListener('click', function () {
        var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        var next = dark ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        try { localStorage.setItem('ui-theme', next); } catch (e) {}
        icon();
    });
})();

// ── mobile sidebar ────────────────────────────────────────────────────────
function uiToggleSidebar(show) {
    var s = document.getElementById('uiSidebar'), b = document.getElementById('uiSidebarBackdrop');
    if (!s) return;
    s.classList.toggle('show', show);
    if (b) b.classList.toggle('show', show);
}

// ── back to top ───────────────────────────────────────────────────────────
window.addEventListener('scroll', function () {
    var el = document.getElementById('btn-back-to-top');
    if (el) el.style.display = (window.scrollY > 200) ? 'block' : 'none';
});
document.getElementById('btn-back-to-top').addEventListener('click', function () {
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

// ── toast notifications (localStorage-deduplicated) ───────────────────────
function showToast(type, message, options = {}) {
    const toastEl = document.getElementById('liveToast');
    if (!toastEl) return;

    const messageKey = 'toast_' + btoa(unescape(encodeURIComponent(type + ':' + message))).slice(0, 32);
    const now = Date.now();
    const dedupWindow = options.dedupMs || 60000;

    const lastShown = localStorage.getItem(messageKey);
    if (lastShown && (now - parseInt(lastShown)) < dedupWindow) return;
    localStorage.setItem(messageKey, now.toString());

    const keysToRemove = [];
    for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (key && key.startsWith('toast_')) {
            if (now - parseInt(localStorage.getItem(key)) > 300000) keysToRemove.push(key);
        }
    }
    keysToRemove.forEach(k => localStorage.removeItem(k));

    const autohide = options.autohide !== undefined ? options.autohide : (type !== 'error');
    const toast = new bootstrap.Toast(toastEl, { autohide: autohide, delay: options.delay || 5000 });
    const toastBody = toastEl.querySelector('.toast-body');
    const toastHeader = toastEl.querySelector('.toast-header');

    toastBody.textContent = message;
    toastHeader.className = 'toast-header';
    if (type === 'success') {
        toastHeader.classList.add('bg-success', 'text-white');
        toastHeader.querySelector('i').className = 'bi bi-check-circle me-2';
    } else if (type === 'error') {
        toastHeader.classList.add('bg-danger', 'text-white');
        toastHeader.querySelector('i').className = 'bi bi-x-circle me-2';
    } else if (type === 'warning') {
        toastHeader.classList.add('bg-warning');
        toastHeader.querySelector('i').className = 'bi bi-exclamation-triangle me-2';
    } else {
        toastHeader.classList.add('bg-info', 'text-white');
        toastHeader.querySelector('i').className = 'bi bi-info-circle me-2';
    }
    toast.show();
}

function clearToastHistory() {
    const keysToRemove = [];
    for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (key && key.startsWith('toast_')) keysToRemove.push(key);
    }
    keysToRemove.forEach(k => localStorage.removeItem(k));
}
</script>
