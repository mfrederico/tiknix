<?php
/**
 * Known — outside services this plugin recognises, so most of a site's list sorts itself: the group, a name a person
 * knows, and what it is for. A pattern is a host ("www.youtube.com") or "*.domain" (any host under it).
 * Libraries and fonts from a CDN are not a group at all: the app serves its own copies (/rt/vendor), so seeing one
 * is advice, not a choice.
 */

namespace app\concepts\consent;

class Known {

    /** pattern => [group, name, purpose] */
    public const HOSTS = [
        // needed: the app's own providers and safety checks
        'challenges.cloudflare.com' => ['necessary', 'Cloudflare Turnstile', 'Checks that a person, not a robot, is signing up.'],
        'js.stripe.com'             => ['necessary', 'Stripe', 'Takes card payments.'],
        '*.stripe.com'              => ['necessary', 'Stripe', 'Takes card payments.'],
        '*.stripe.network'          => ['necessary', 'Stripe', 'Takes card payments.'],
        'www.paypal.com'            => ['necessary', 'PayPal', 'Takes payments.'],
        'iframe.mediadelivery.net'  => ['necessary', 'Bunny Stream', 'Plays the videos people upload here.'],
        'video.bunnycdn.com'        => ['necessary', 'Bunny Stream', 'Receives the videos people upload here.'],
        '*.b-cdn.net'               => ['necessary', 'Bunny CDN', 'Delivers this site\'s video thumbnails and files.'],
        'cdn.shopify.com'           => ['necessary', 'Shopify', 'Runs this app inside the store\'s Shopify admin.'],
        // statistics
        'www.google-analytics.com'  => ['analytics', 'Google Analytics', 'Counts visits and what people do here.'],
        '*.google-analytics.com'    => ['analytics', 'Google Analytics', 'Counts visits and what people do here.'],
        '*.analytics.google.com'    => ['analytics', 'Google Analytics', 'Counts visits and what people do here.'],
        'www.googletagmanager.com'  => ['analytics', 'Google Tag Manager', 'Loads Google\'s measuring and ad tags.'],
        '*.hotjar.com'              => ['analytics', 'Hotjar', 'Records how people move around pages.'],
        '*.hotjar.io'               => ['analytics', 'Hotjar', 'Records how people move around pages.'],
        '*.clarity.ms'              => ['analytics', 'Microsoft Clarity', 'Records how people move around pages.'],
        'plausible.io'              => ['analytics', 'Plausible', 'Counts visits.'],
        'cdn.usefathom.com'         => ['analytics', 'Fathom', 'Counts visits.'],
        '*.posthog.com'             => ['analytics', 'PostHog', 'Counts visits and what people do here.'],
        '*.mixpanel.com'            => ['analytics', 'Mixpanel', 'Counts what people do here.'],
        'cdn.segment.com'           => ['analytics', 'Segment', 'Collects what people do here for other tools.'],
        'api.segment.io'            => ['analytics', 'Segment', 'Collects what people do here for other tools.'],
        // ads
        'connect.facebook.net'      => ['marketing', 'Meta Pixel', 'Tells Facebook and Instagram about your visit, for ads.'],
        'www.facebook.com'          => ['marketing', 'Meta Pixel', 'Tells Facebook and Instagram about your visit, for ads.'],
        '*.doubleclick.net'         => ['marketing', 'Google Ads', 'Shows and measures Google ads.'],
        'www.googleadservices.com'  => ['marketing', 'Google Ads', 'Measures Google ads.'],
        'pagead2.googlesyndication.com' => ['marketing', 'Google AdSense', 'Shows Google ads.'],
        'snap.licdn.com'            => ['marketing', 'LinkedIn Insight', 'Tells LinkedIn about your visit, for ads.'],
        'px.ads.linkedin.com'       => ['marketing', 'LinkedIn Insight', 'Tells LinkedIn about your visit, for ads.'],
        'analytics.tiktok.com'      => ['marketing', 'TikTok Pixel', 'Tells TikTok about your visit, for ads.'],
        'static.ads-twitter.com'    => ['marketing', 'X (Twitter) ads', 'Tells X about your visit, for ads.'],
        'bat.bing.com'              => ['marketing', 'Microsoft Ads', 'Measures Microsoft ads.'],
        '*.hs-scripts.com'          => ['marketing', 'HubSpot', 'Tracks visits for HubSpot\'s marketing tools.'],
        '*.hs-analytics.net'        => ['marketing', 'HubSpot', 'Tracks visits for HubSpot\'s marketing tools.'],
        // videos, maps and widgets
        'www.youtube.com'           => ['media', 'YouTube', 'Plays YouTube videos.'],
        'www.youtube-nocookie.com'  => ['media', 'YouTube', 'Plays YouTube videos.'],
        '*.ytimg.com'               => ['media', 'YouTube', 'Shows YouTube video pictures.'],
        'player.vimeo.com'          => ['media', 'Vimeo', 'Plays Vimeo videos.'],
        '*.vimeocdn.com'            => ['media', 'Vimeo', 'Plays Vimeo videos.'],
        'tile.openstreetmap.org'    => ['media', 'OpenStreetMap', 'Draws the map.'],
        '*.tile.openstreetmap.org'  => ['media', 'OpenStreetMap', 'Draws the map.'],
        'maps.googleapis.com'       => ['media', 'Google Maps', 'Draws the map.'],
        'maps.gstatic.com'          => ['media', 'Google Maps', 'Draws the map.'],
        '*.elfsight.com'            => ['media', 'Elfsight', 'Shows a widget, like reviews or a feed.'],
        '*.elfsightcdn.com'         => ['media', 'Elfsight', 'Shows a widget, like reviews or a feed.'],
        'platform.twitter.com'      => ['media', 'X (Twitter)', 'Shows posts from X.'],
        'w.soundcloud.com'          => ['media', 'SoundCloud', 'Plays audio.'],
        'open.spotify.com'          => ['media', 'Spotify', 'Plays music.'],
        'widget.trustpilot.com'     => ['media', 'Trustpilot', 'Shows reviews.'],
    ];

    /** Seen = a library or font the app should serve itself: advice for the owner, never a visitor's choice. */
    public const SELF_HOST = ['cdn.jsdelivr.net', 'unpkg.com', 'cdnjs.cloudflare.com', 'code.jquery.com', 'fonts.googleapis.com',
                              'fonts.gstatic.com', 'ajax.googleapis.com', 'stackpath.bootstrapcdn.com', 'maxcdn.bootstrapcdn.com', 'cdn.tailwindcss.com', 'esm.sh'];

    /** What this plugin knows of $host: [group, name, purpose, pattern], or null. */
    public static function of(string $host): ?array {
        $host = strtolower($host);
        if (isset(self::HOSTS[$host])) return self::HOSTS[$host] + [3 => $host];
        foreach (self::HOSTS as $pattern => $v) {
            if (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1))) return $v + [3 => $pattern];
        }
        return null;
    }

    public static function selfHost(string $host): bool { return in_array(strtolower($host), self::SELF_HOST, true); }

    /** A host name as a browser reports it: letters, digits, dots and dashes, with a dot. */
    public static function isHost(string $host): bool {
        return strlen($host) <= 253 && (bool) preg_match('/^(?=.*\.)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $host);
    }
}
