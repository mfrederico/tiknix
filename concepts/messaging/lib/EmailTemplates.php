<?php
namespace app\concepts\messaging;

/**
 * EmailTemplates — HTML an admin starts a message from, on the new-message page: email-safe markup (tables, inline
 * styles, 600px wide) with the message's {variables} already in place. The words in them are placeholders in square
 * brackets, to be replaced; the variables stay. An app may add its own: Providers::register(['templates' => [...]])
 * is not a thing yet — these are the plugin's.
 */
class EmailTemplates {

    /** @return array<int,array{id:string,name:string,blurb:string,html:string}> */
    public static function all(): array {
        $shell = fn(string $inner, string $accent = '#2f6fed') => '<!doctype html>
<html><body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2933">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f5f7"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden">
' . $inner . '
<tr><td style="padding:20px 32px 28px;font-size:12px;line-height:1.5;color:#6b7280;border-top:1px solid #e5e7eb">
You get this because you are a member. <a href="{prefs_url}" style="color:#6b7280">Choose what reaches you</a> · <a href="{unsubscribe_url}" style="color:#6b7280">Unsubscribe</a>
</td></tr>
</table></td></tr></table></body></html>';

        $button = fn(string $label, string $href, string $accent = '#2f6fed') => '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:20px 0 4px"><tr><td style="background:' . $accent . ';border-radius:8px">
<a href="' . $href . '" style="display:inline-block;padding:12px 22px;color:#ffffff;text-decoration:none;font-weight:600">' . $label . '</a></td></tr></table>';

        return [
            [
                'id' => 'recognition', 'name' => 'Community recognition',
                'blurb' => 'A banner, a warm headline naming the member, what they did, a certificate or photo can be attached, and a button to the community.',
                'html' => $shell('<tr><td style="background:#2f6fed;padding:28px 32px;color:#ffffff">
<div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;opacity:.85">[Community name]</div>
<div style="font-size:26px;font-weight:700;line-height:1.25;margin-top:6px">Thank you, {first_name|friend}</div>
</td></tr>
<tr><td style="padding:28px 32px;font-size:16px;line-height:1.6">
<p style="margin:0 0 14px">[One sentence on what they did and why it mattered to the community.]</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:18px 0"><tr><td style="background:#f0f5ff;border-left:4px solid #2f6fed;padding:14px 16px;border-radius:6px">
<div style="font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:#2f6fed">Recognised for</div>
<div style="font-size:18px;font-weight:600;margin-top:4px">[The thing, in a few words]</div>
</td></tr></table>
<p style="margin:0 0 14px">[A second paragraph: what comes next, or a personal note from the organiser. The attached certificate, if there is one, is theirs to keep.]</p>
' . $button('See the community', '[https://your-app/communities/view?id=1]') . '
<p style="margin:18px 0 0;color:#6b7280;font-size:14px">With thanks,<br>[Your name], [Community name]</p>
</td></tr>'),
            ],
            [
                'id' => 'announcement', 'name' => 'Announcement',
                'blurb' => 'One thing to say and one thing to do: a headline, two short paragraphs, a button.',
                'html' => $shell('<tr><td style="padding:32px 32px 8px">
<div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#6b7280">[Your app]</div>
<h1 style="font-size:24px;line-height:1.3;margin:8px 0 0">[The headline, in one line]</h1>
</td></tr>
<tr><td style="padding:16px 32px 28px;font-size:16px;line-height:1.6">
<p style="margin:0 0 14px">Hi {first_name|there},</p>
<p style="margin:0 0 14px">[What is new, in two or three sentences.]</p>
<p style="margin:0 0 14px">[What it means for them, and when.]</p>
' . $button('[Do the thing]', '[https://your-app/…]') . '
</td></tr>'),
            ],
            [
                'id' => 'newsletter', 'name' => 'Newsletter',
                'blurb' => 'Three short sections with their own headings, each with a link, and a closing line.',
                'html' => $shell('<tr><td style="padding:32px 32px 8px">
<div style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#6b7280">[Your app] · [Month]</div>
<h1 style="font-size:24px;line-height:1.3;margin:8px 0 0">[This month, in one line]</h1>
</td></tr>
<tr><td style="padding:8px 32px 0;font-size:16px;line-height:1.6"><p style="margin:0 0 6px">Hi {first_name|there},</p></td></tr>
<tr><td style="padding:12px 32px 0;font-size:16px;line-height:1.6;border-top:1px solid #e5e7eb">
<h2 style="font-size:18px;margin:16px 0 6px">[First section]</h2><p style="margin:0 0 10px">[Two sentences.] <a href="[https://…]" style="color:#2f6fed">Read more</a></p>
</td></tr>
<tr><td style="padding:12px 32px 0;font-size:16px;line-height:1.6;border-top:1px solid #e5e7eb">
<h2 style="font-size:18px;margin:16px 0 6px">[Second section]</h2><p style="margin:0 0 10px">[Two sentences.] <a href="[https://…]" style="color:#2f6fed">Read more</a></p>
</td></tr>
<tr><td style="padding:12px 32px 24px;font-size:16px;line-height:1.6;border-top:1px solid #e5e7eb">
<h2 style="font-size:18px;margin:16px 0 6px">[Third section]</h2><p style="margin:0 0 10px">[Two sentences.] <a href="[https://…]" style="color:#2f6fed">Read more</a></p>
<p style="margin:18px 0 0;color:#6b7280;font-size:14px">Until next month,<br>[Your name]</p>
</td></tr>'),
            ],
        ];
    }

    public static function get(string $id): ?array {
        foreach (self::all() as $t) if ($t['id'] === $id) return $t;
        return null;
    }
}
