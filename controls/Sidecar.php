<?php
/**
 * Sidecar — core-side launcher for any registered sidecar plugin.
 *
 * /sidecar/launch/<name> gates the member (login + the plugin's Feature grant),
 * mints a signed handoff token via lib/Sidecar/Registry, and redirects into the
 * plugin's /sso/consume. Adding a plugin is a [sidecar.<name>] config section — no
 * new controller. authcontrol: sidecar::* = 100 (MEMBER); the per-plugin feature
 * grant + the plugin's own ownership scoping decide actual access.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Sidecar\Registry;

class Sidecar extends Control {

    /** GET /sidecar/launch/<name> — gate, mint, redirect into the plugin. */
    public function launch($params = []) {
        if (!$this->requireLogin()) return;

        $name = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($this->opId() ?? '')));
        $plugin = $name !== '' ? Registry::get($name) : null;
        if (!$plugin) { $this->flash('error', 'Unknown plugin.'); Flight::redirect('/dashboard'); return; }

        $memberId = (int) ($this->member->id ?? 0);
        $level    = (int) ($this->member->level ?? 101);
        if (!Feature::isEnabled($plugin['feature'], $memberId, $level)) {
            $this->flash('error', $plugin['label'] . ' is not enabled for your account.');
            Flight::redirect('/dashboard');
            return;
        }

        $url = Registry::launchUrl($name, ['id' => $memberId, 'level' => $level, 'email' => (string) ($this->member->email ?? '')]);
        if (!$url) { $this->flash('error', $plugin['label'] . ' is not configured on this server yet.'); Flight::redirect('/dashboard'); return; }

        Flight::redirect($url);
    }

    /**
     * GET /sidecar/app/<name> — the same plugin, but EMBEDDED in the tiknix shell so
     * you keep the left-nav. Renders a full-height iframe pointing at /sidecar/launch
     * (which mints the token + SSO's into the plugin inside the frame). Works because
     * *.tiknix.com is same-site — the plugin's SameSite=Lax session cookie is sent in
     * the frame, and no plugin sets X-Frame-Options, so framing is allowed.
     */
    public function app($params = []) {
        if (!$this->requireLogin()) return;

        $name = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($this->opId() ?? '')));
        $plugin = $name !== '' ? Registry::get($name) : null;
        if (!$plugin) { $this->flash('error', 'Unknown plugin.'); Flight::redirect('/dashboard'); return; }

        $memberId = (int) ($this->member->id ?? 0);
        $level    = (int) ($this->member->level ?? 101);
        if (!Feature::isEnabled($plugin['feature'], $memberId, $level)) {
            $this->flash('error', $plugin['label'] . ' is not enabled for your account.');
            Flight::redirect('/dashboard');
            return;
        }

        $this->render('sidecar/app', ['title' => $plugin['label'], 'plugin' => $name, 'label' => $plugin['label']]);
    }
}
