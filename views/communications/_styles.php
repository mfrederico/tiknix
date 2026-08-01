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
/* Row actions: hidden until the row is hovered or something in it has focus, so the
   rail stays a list of conversations rather than a grid of buttons. Above the
   stretched-link, or the anchor would swallow the clicks. */
.comms-hub .comms-thread-actions {
    position: absolute;
    top: .35rem;
    right: .5rem;
    z-index: 2;
    display: flex;
    gap: .5rem;
    opacity: 0;
    transition: opacity .12s ease-in-out;
}
.comms-hub .comms-thread-row:hover .comms-thread-actions,
.comms-hub .comms-thread-row:focus-within .comms-thread-actions { opacity: 1; }
/* Touch has no hover — never leave the actions unreachable there. */
@media (hover: none) {
    .comms-hub .comms-thread-actions { opacity: 1; }
}

/* ---- @mentions ---- */
.comms-hub .comms-mention,
.comms-mention {
    background: rgba(var(--bs-primary-rgb), .12);
    color: var(--bs-primary-text-emphasis, var(--bs-primary));
    border-radius: .25rem;
    padding: 0 .2rem;
    font-weight: 600;
}
/* Being mentioned yourself should not look the same as watching someone else be. */
.comms-hub .comms-mention-me,
.comms-mention-me {
    background: rgba(var(--bs-warning-rgb), .28);
    color: var(--bs-warning-text-emphasis, inherit);
}

/* ---- rooms rail ---- */
.comms-hub .comms-section-head {
    padding: .5rem .75rem .25rem;
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--bs-secondary-color);
}
/* Capped and scrollable on its own: a long room list must not push conversations off
   the screen, which is the failure mode of putting one list above another. */
.comms-hub .comms-rooms { max-height: 40%; overflow-y: auto; }

.comms-hub .comms-room-row {
    display: flex;
    align-items: center;
    gap: .4rem;
    padding: .3rem .75rem;
    text-decoration: none;
    color: var(--bs-body-color);
    font-size: .875rem;
}
.comms-hub .comms-room-row:hover { background: var(--bs-tertiary-bg); }
.comms-hub .comms-room-row.active {
    background: var(--bs-tertiary-bg);
    box-shadow: inset 3px 0 0 var(--bs-primary);
}
.comms-hub .comms-room-name { font-weight: 500; }
.comms-hub .comms-room-row.unread .comms-room-name { font-weight: 700; }
.comms-hub .comms-room-team {
    color: var(--bs-secondary-color);
    font-size: .75rem;
    margin-left: auto;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 45%;
}
.comms-hub .comms-unread-dot-room {
    width: .5rem; height: .5rem; border-radius: 50%;
    background: var(--bs-danger);
    flex-shrink: 0;
}
</style>
