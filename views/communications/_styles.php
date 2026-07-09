<?php
/**
 * Shared styling for the communications hub. Scoped to `.comms-hub` so it only
 * affects the comms pages. Uses Bootstrap CSS variables throughout, so it
 * adapts to tiknix's light/dark theme automatically.
 */
?>
<style>
.comms-hub .comms-panel {
    height: calc(100vh - 210px);
    min-height: 420px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.comms-hub .comms-scroll { overflow-y: auto; }

/* Bootstrap 5.3 ships no min-w-0 utility, but flex-item text truncation needs
   it — define it scoped so subjects/previews ellipsize instead of shoving the
   date off the row edge. */
.comms-hub .min-w-0 { min-width: 0; }

/* ---- thread list rail ---- */
.comms-hub .comms-thread-row {
    display: block;
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--bs-border-color);
    text-decoration: none;
    color: inherit;
    max-width: 100%;
    overflow: hidden;
}
.comms-hub .comms-thread-row:hover { background: var(--bs-tertiary-bg); }
.comms-hub .comms-thread-row.active {
    background: var(--bs-primary-bg-subtle);
    border-left: 3px solid var(--bs-primary);
    padding-left: calc(1rem - 3px);
}
.comms-hub .comms-thread-row.unread .comms-thread-subject { font-weight: 700; }
.comms-hub .comms-thread-subject {
    min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.comms-hub .comms-thread-preview {
    font-size: 0.8rem; color: var(--bs-secondary-color);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100%;
}
.comms-hub .comms-unread-dot {
    display: inline-block; width: 8px; height: 8px; border-radius: 50%;
    background: var(--bs-primary); margin-right: 0.4rem; flex-shrink: 0; visibility: hidden;
}
.comms-hub .comms-thread-row.unread .comms-unread-dot { visibility: visible; }
.comms-hub .comms-unread-badge { font-size: 0.62rem; }

/* ---- avatar chip ---- */
.comms-hub .comms-avatar {
    width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 0.72rem; font-weight: 700; color: #fff;
    background: var(--bs-primary);
}
.comms-hub .comms-avatar.sm { width: 26px; height: 26px; font-size: 0.62rem; }

/* ---- message feed ---- */
.comms-hub .comms-msg-bubble-wrap { margin-top: 12px; max-width: 82%; }
.comms-hub .comms-msg-bubble {
    padding: 0.6rem 0.8rem; border-radius: 14px; word-break: break-word;
    font-size: 0.9rem; line-height: 1.5; box-shadow: 0 1px 1px rgba(0,0,0,0.06);
}
.comms-hub .comms-msg-bubble.out {
    background: var(--bs-primary-bg-subtle); border-bottom-right-radius: 4px;
}
.comms-hub .comms-msg-bubble.in {
    background: var(--bs-tertiary-bg); border-bottom-left-radius: 4px;
}
.comms-hub .comms-msg-bubble a {
    color: var(--bs-emphasis-color); text-decoration: underline;
    text-underline-offset: 0.18em; font-weight: 600;
}
.comms-hub .comms-msg-bubble p:last-child { margin-bottom: 0; }
.comms-hub .comms-msg-meta {
    font-size: 0.72rem; color: var(--bs-secondary-color); margin-bottom: 0.2rem;
}
.comms-hub .comms-msg-system {
    text-align: center; margin: 14px auto; max-width: 80%;
    font-size: 0.8rem; color: var(--bs-secondary-color);
}
.comms-hub .comms-msg-system-inner {
    display: inline-block; padding: 0.4rem 0.8rem; border-radius: 10px;
    background: var(--bs-secondary-bg); border: 1px dashed var(--bs-border-color);
}

/* ---- composer ---- */
.comms-hub .comms-composer { border-top: 1px solid var(--bs-border-color); }

/* ---- mobile: collapse the rail when a thread is open ---- */
@media (max-width: 991.98px) {
    .comms-hub .comms-panel { height: auto; min-height: 0; }
    .comms-hub .comms-scroll { overflow-y: visible; }
    .comms-hub .comms-msg-bubble-wrap { max-width: 92%; }
}
</style>
