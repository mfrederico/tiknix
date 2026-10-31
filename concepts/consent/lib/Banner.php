<?php
/**
 * Banner — the question, as a string put before </body> on any page, whichever layout drew it. Colours come from the
 * design system where a page has it, with plain ones where it does not. Accept, Reject and Choose carry equal weight:
 * saying no must be as easy as saying yes.
 */

namespace app\concepts\consent;

class Banner {

    /** The groups in use on this site, with the names of what is in them. @return array<string,string[]> */
    public static function inUse(array $services): array {
        $out = [];
        foreach (Groups::asked() as $g) {
            $names = array_values(array_unique(array_column(array_filter($services, fn($s) => $s['group'] === $g), 'name')));
            if ($names) $out[$g] = $names;
        }
        return $out;
    }

    public static function html(array $services, string $site, array $last): string {
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $boxes = '';
        foreach (self::inUse($services) as $g => $names) {
            $boxes .= '<label class="tc-row"><input type="checkbox" data-consent-group="' . $e($g) . '"' . (in_array($g, $last, true) ? ' checked' : '') . '><span><strong>' . $e(Groups::name($g)) . '</strong><small>'
                    . $e(Groups::line($g)) . ' ' . $e(implode(', ', $names)) . '.</small></span></label>';
        }
        return <<<HTML
<div id="tcConsent" role="dialog" aria-label="Cookie choices" aria-live="polite">
<style>
#tcConsent{position:fixed;left:1rem;right:1rem;bottom:1rem;z-index:2147483000;max-width:34rem;margin:0 auto;max-height:calc(100vh - 2rem);overflow:auto;padding:1rem 1.1rem;border-radius:.9rem;border:1px solid var(--bs-border-color,#ccc);background:var(--ui-surface,#fff);color:var(--bs-body-color,#222);box-shadow:0 10px 40px rgba(0,0,0,.25);font:15px/1.4 system-ui,sans-serif}
#tcConsent h2{font-size:1.05rem;font-weight:700;margin:0 0 .25rem;color:inherit}
#tcConsent p{margin:0 0 .75rem}
#tcConsent a{color:inherit;text-decoration:underline}
#tcConsent .tc-btns{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem}
#tcConsent button{padding:.55rem .4rem;border-radius:.5rem;border:1px solid currentColor;background:transparent;color:inherit;font:inherit;font-weight:600;cursor:pointer}
#tcConsent button:hover{background:rgba(127,127,127,.15)}
#tcConsent .tc-pick{margin-bottom:.75rem}
#tcConsent .tc-row{display:flex;gap:.6rem;align-items:flex-start;padding:.4rem 0;cursor:pointer}
#tcConsent .tc-row input{margin-top:.25rem;flex:none}
#tcConsent .tc-row small{display:block;opacity:.8}
#tcConsent .tc-err{color:#b02a37;margin-top:.5rem}
</style>
<h2>Cookies on {$e($site)}</h2>
<p>We use a few outside services. They only run if you say yes. <a href="/consent/cookies">See what they are</a>.</p>
<div class="tc-pick" hidden>{$boxes}</div>
<div class="tc-btns" data-consent-main><button type="button" data-consent-do="all">Accept all</button><button type="button" data-consent-do="none">Reject all</button><button type="button" data-consent-do="choose">Choose</button></div>
<div class="tc-btns" data-consent-save hidden style="grid-template-columns:1fr"><button type="button" data-consent-do="pick">Save my choices</button></div>
<div class="tc-err" role="alert" hidden></div>
</div>
HTML;
    }
}
