<?php
/**
 * Tiknix — shared design system (neutral --ui-* tokens).
 *
 * Ported from the DealerYes design language so the two apps converge on one
 * aesthetic: Figtree body + Bricolage display + Fraunces headings, warm-paper
 * light / deep-navy dark, rounded soft-shadow surfaces, a dark sidebar + slim
 * topbar app shell.
 *
 * Loaded from layouts/layout.php AFTER Bootstrap so our :root overrides win.
 * Bootstrap's own --bs-* vars are overridden here so framework components
 * (buttons, tables, modals, forms) inherit the palette automatically.
 *
 * Drop-in component classes:
 *   .ui-shell / .ui-sidebar / .ui-main / .ui-topbar / .ui-content   app shell
 *   .ui-panel / .ui-panel-header / .ui-panel-body                   surface card
 *   .ui-page-header / .ui-eyebrow / .ui-display / .ui-mono          typography
 *   .ui-stat / .ui-chip / .ui-btn-icon / .ui-user-chip / .ui-avatar bits
 */
?>
<!-- Fonts: Figtree (body) · Bricolage Grotesque (display) · Fraunces (headings) · DM Mono -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,300..800&family=Figtree:ital,wght@0,300..900;1,300..900&family=DM+Mono:wght@400;500&family=Fraunces:ital,opsz,wght,SOFT@0,9..144,100..900,0..100;1,9..144,100..900,0..100&display=swap" rel="stylesheet">

<style id="ui-design-system">
:root, [data-bs-theme="light"] {
    --bs-body-bg:#f7f8fa; --bs-body-color:#1a2236; --bs-secondary-color:#5b667a;
    --bs-tertiary-color:#7d8ca5; --bs-border-color:#dde1e9; --bs-emphasis-color:#0f172a;
    --bs-primary:#0c41aa; --bs-primary-rgb:12,65,170;
    --bs-link-color:#0c41aa; --bs-link-color-rgb:12,65,170; --bs-link-hover-color:#093080;
    --bs-body-font-family:'Figtree',system-ui,-apple-system,sans-serif;
    --bs-body-font-size:0.98rem;
    --bs-border-radius:0.65rem; --bs-border-radius-sm:0.4rem;
    --bs-border-radius-lg:1rem; --bs-border-radius-xl:1.5rem;

    --ui-surface:#ffffff; --ui-surface-soft:#f0f3f9; --ui-surface-inset:#e8ecf4;
    --ui-shadow:0 1px 2px rgba(15,23,42,0.04);
    --ui-shadow-soft:0 1px 2px rgba(15,23,42,0.04), 0 10px 28px -22px rgba(15,23,42,0.12);
    --ui-shadow-lift:0 22px 52px -22px rgba(15,23,42,0.22), 0 6px 14px -6px rgba(15,23,42,0.08);

    --ui-primary:#0c41aa; --ui-accent-order:#0d57cb; --ui-accent-report:#9b3725;
    --ui-accent-system:#47649a; --ui-accent-mgmt:#23529a;

    --ui-ff-display:'Bricolage Grotesque','Figtree',system-ui,sans-serif;
    --ui-ff-brand:'Fraunces',Georgia,'Times New Roman',serif;
    --ui-ff-body:'Figtree',system-ui,sans-serif;
    --ui-ff-mono:'DM Mono',ui-monospace,monospace;

    --ui-sidebar-width:232px; --ui-sidebar-bg:#0a132d; --ui-sidebar-text:#b5bed0;
    --ui-sidebar-heading:#586894; --ui-sidebar-active-bg:var(--ui-primary);
    --ui-sidebar-active-text:#ffffff; --ui-sidebar-hover-bg:rgba(255,255,255,0.06);
    --ui-topbar-height:62px;
}
[data-bs-theme="dark"] {
    --bs-body-bg:#0a132d; --bs-body-color:#eaedf5; --bs-secondary-color:#9ba4bd;
    --bs-tertiary-color:#667190; --bs-border-color:#1c2948; --bs-emphasis-color:#eaedf5;
    --bs-primary:#3b76f0; --bs-primary-rgb:59,118,240;
    --bs-link-color:#6ea0ff; --bs-link-color-rgb:110,160,255; --bs-link-hover-color:#9dbcff;

    --ui-surface:#101c3d; --ui-surface-soft:#19264d; --ui-surface-inset:#070e22;
    --ui-shadow:0 1px 2px rgba(0,0,0,0.22);
    --ui-shadow-soft:0 1px 2px rgba(0,0,0,0.24), 0 10px 26px -20px rgba(0,0,0,0.55);
    --ui-shadow-lift:0 24px 48px -20px rgba(0,0,0,0.55), 0 6px 14px -6px rgba(0,0,0,0.25);

    --ui-primary:#3b76f0; --ui-accent-order:#3b76f0; --ui-accent-report:#e86a56;
    --ui-accent-system:#7094cc; --ui-accent-mgmt:#5c8ee0;
    --ui-sidebar-bg:#060d20;
}

/* baseline */
html, body { background:var(--bs-body-bg); }
body {
    color:var(--bs-body-color); font-family:var(--ui-ff-body);
    font-feature-settings:'ss01','ss03'; -webkit-font-smoothing:antialiased;
}

/* typography */
.ui-display{font-family:var(--ui-ff-display);letter-spacing:-0.025em;}
.ui-mono{font-family:var(--ui-ff-mono);font-feature-settings:'tnum';}
.ui-eyebrow{font-family:var(--ui-ff-mono);font-size:.68rem;letter-spacing:.22em;text-transform:uppercase;color:var(--bs-tertiary-color);}
h1,.h1{font-family:var(--ui-ff-brand);font-variation-settings:'opsz' 96,'SOFT' 20;letter-spacing:-0.02em;font-weight:700;}
h2,h3,h4,.h2,.h3,.h4{font-family:var(--ui-ff-display);letter-spacing:-0.02em;font-weight:600;}

/* app shell */
.ui-shell{display:flex;min-height:100vh;}
.ui-sidebar{width:var(--ui-sidebar-width);flex:0 0 var(--ui-sidebar-width);background:var(--ui-sidebar-bg);color:var(--ui-sidebar-text);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;}
.ui-sidebar-brand{display:flex;align-items:center;gap:.6rem;padding:1.1rem 1.25rem;color:#fff;text-decoration:none;font-family:var(--ui-ff-brand);font-weight:700;font-size:1.35rem;font-variation-settings:'opsz' 72,'SOFT' 20;letter-spacing:-0.02em;}
.ui-brand-mark{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,var(--ui-primary),#2f7bf6);display:grid;place-items:center;color:#fff;font-size:1rem;box-shadow:0 6px 16px -6px rgba(59,118,240,.7);}
<?php $__logoV = @filemtime(dirname(__DIR__, 2) . '/public/img/tiknix.svg') ?: '1'; ?>
.ui-brand-logo{width:32px;height:32px;flex:0 0 auto;background:currentColor;-webkit-mask:url(/img/tiknix.svg?v=<?= $__logoV ?>) center/contain no-repeat;mask:url(/img/tiknix.svg?v=<?= $__logoV ?>) center/contain no-repeat;}
.ui-brand-word{font-family:Georgia,'Times New Roman',Times,serif;font-weight:400;font-size:1.5rem;letter-spacing:.005em;}
.ui-nav{padding:.5rem .75rem;overflow-y:auto;flex:1;}
.ui-nav-heading{font-family:var(--ui-ff-mono);font-size:.62rem;letter-spacing:.2em;text-transform:uppercase;color:var(--ui-sidebar-heading);padding:1rem .75rem .35rem;}
.ui-nav-link{display:flex;align-items:center;gap:.7rem;padding:.55rem .75rem;margin-bottom:2px;border-radius:.55rem;color:var(--ui-sidebar-text);text-decoration:none;font-size:.92rem;transition:background .15s,color .15s;}
.ui-nav-link i{font-size:1.05rem;width:1.2rem;text-align:center;opacity:.85;}
.ui-nav-link:hover{background:var(--ui-sidebar-hover-bg);color:#fff;}
.ui-nav-link.active{background:var(--ui-sidebar-active-bg);color:var(--ui-sidebar-active-text);box-shadow:0 8px 18px -10px rgba(59,118,240,.8);}
.ui-nav-link.active i{opacity:1;}
.ui-sidebar-foot{border-top:1px solid rgba(255,255,255,.07);padding:.75rem 1rem;}

/* main + topbar */
.ui-main{flex:1;min-width:0;display:flex;flex-direction:column;}
.ui-topbar{height:var(--ui-topbar-height);position:sticky;top:0;z-index:20;background:color-mix(in srgb,var(--bs-body-bg) 82%,transparent);backdrop-filter:saturate(150%) blur(10px);border-bottom:1px solid var(--bs-border-color);display:flex;align-items:center;gap:1rem;padding:0 1.5rem;}
.ui-topbar-title{line-height:1.1;}
.ui-topbar-title .ui-eyebrow{display:block;margin-bottom:1px;}
.ui-topbar-title strong{font-family:var(--ui-ff-display);font-weight:600;font-size:1.02rem;letter-spacing:-.01em;}
.ui-content{padding:1.75rem;width:100%;}
/* Opt-in readable width for text-heavy pages: <div class="ui-content"><div class="ui-narrow">…</div></div> */
.ui-narrow{max-width:1180px;margin-inline:auto;}

.ui-btn-icon{width:38px;height:38px;border-radius:11px;border:1px solid var(--bs-border-color);background:var(--ui-surface);color:var(--bs-secondary-color);display:grid;place-items:center;cursor:pointer;transition:.15s;}
.ui-btn-icon:hover{color:var(--ui-primary);border-color:var(--ui-primary);}
.ui-user-chip{display:flex;align-items:center;gap:.55rem;padding:.3rem .3rem .3rem .75rem;border:1px solid var(--bs-border-color);border-radius:999px;background:var(--ui-surface);cursor:pointer;}
.ui-avatar{width:30px;height:30px;border-radius:50%;display:grid;place-items:center;color:#fff;font-size:.78rem;font-weight:600;font-family:var(--ui-ff-mono);}

/* surfaces */
.ui-panel{background:var(--ui-surface);border:1px solid var(--bs-border-color);border-radius:1.15rem;box-shadow:var(--ui-shadow);overflow:hidden;}
.ui-panel-header{padding:1rem 1.25rem;border-bottom:1px solid var(--bs-border-color);display:flex;align-items:center;justify-content:space-between;gap:1rem;}
.ui-panel-header h3{margin:0;font-size:1.02rem;}
.ui-panel-body{padding:1.25rem;}
.ui-page-header{margin-bottom:1.5rem;}
.ui-page-header h1{font-size:clamp(1.7rem,3.4vw,2.3rem);line-height:1.05;margin:0 0 .25rem;}
.ui-page-header .ui-sub{color:var(--bs-secondary-color);}

.ui-stat{display:flex;flex-direction:column;gap:.35rem;}
.ui-stat-label{font-family:var(--ui-ff-mono);font-size:.66rem;letter-spacing:.16em;text-transform:uppercase;color:var(--bs-tertiary-color);}
.ui-stat-value{font-family:var(--ui-ff-display);font-size:1.9rem;font-weight:700;letter-spacing:-.02em;}
.ui-chip{display:inline-flex;align-items:center;gap:.4rem;padding:.28rem .7rem;border-radius:999px;font-size:.8rem;font-weight:500;background:var(--ui-surface-soft);color:var(--bs-secondary-color);}

/* responsive: collapse sidebar to an offcanvas-ish hidden state on small screens */
.ui-sidebar-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1049;display:none;}
@media (max-width: 991.98px){
    .ui-sidebar{position:fixed;z-index:1050;transform:translateX(-100%);transition:transform .2s;box-shadow:var(--ui-shadow-lift);}
    .ui-sidebar.show{transform:translateX(0);}
    .ui-sidebar-backdrop.show{display:block;}
    .ui-content{padding:1.1rem;}
}
</style>
