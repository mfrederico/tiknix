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

        // Carry the member's selected project into the plugin. Sidecars are separate
        // apps with their own sessions, so without this each one has to ask again which
        // instance you meant — which is why every sidecar grew its own project picker,
        // and why crossing between them could silently change what you were editing.
        // The claim is authoritative: core has already checked access in ProjectContext.
        $project = ProjectContext::current($memberId);

        $url = Registry::launchUrl($name, [
            'id'    => $memberId,
            'level' => $level,
            'email' => (string) ($this->member->email ?? ''),
            'instance' => $project ? (int) $project->id : 0,
            'slug'     => $project ? (string) $project->slug : '',
            // Open a page inside the plugin instead of its landing (e.g. one task). Signed
            // into the handoff; the plugin re-checks it is a path on its own host.
            'to'       => self::landingPath((string) $this->getParam('to', '')),
        ]);
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

        // The plugin's ORIGIN goes into the iframe's allow= list. An allow= with no origin
        // means 'src' — the origin of the src attribute, which is tiknix.com — and the
        // browser does not follow /sidecar/launch's redirect into the plugin when it
        // computes the allowlist. So the plugin's document got clipboard-read/write
        // DENIED, and the terminal's Ctrl+Shift+C/V silently did nothing. Name it.
        $origin = $plugin['url'] !== '' ? self::origin($plugin['url']) : '';
        if ($origin === '') {
            error_log('ERROR Sidecar::app: [sidecar.' . $name . '] url is missing or not an absolute URL in conf/config.ini — cannot embed');
            $this->flash('error', $plugin['label'] . ' is not configured on this server yet.');
            Flight::redirect('/dashboard');
            return;
        }

        $this->render('sidecar/app', ['title' => $plugin['label'], 'plugin' => $name, 'label' => $plugin['label'], 'origin' => $origin,
            'to' => self::landingPath((string) $this->getParam('to', ''))]);
    }

    /**
     * A page to open inside the plugin: a path on the plugin's own host ("/workbench/view?id=12"),
     * never another host ("//…", "/\…"), a URL with a scheme, or anything with whitespace.
     * Anything else is '' — the plugin's normal landing.
     */
    public static function landingPath(string $to): string {
        $to = trim($to);
        return preg_match('#^/(?![/\\\\])[^\s]*$#D', $to) ? $to : '';
    }

    /** scheme://host[:port] of an absolute URL, or '' when it has no scheme+host. */
    private static function origin(string $url): string {
        $p = parse_url($url);
        if (empty($p['scheme']) || empty($p['host'])) return '';
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }
}
