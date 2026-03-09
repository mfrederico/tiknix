<?php
/**
 * ShipCannon Routes
 * Custom routes for clean marketing URLs + default controller routing
 */

use \Flight as Flight;

// Clean URL aliases: maps clean marketing URLs to controller/method
Flight::set('url_aliases', [
    'pricing'       => ['index', 'pricing'],
    'all-about-us'  => ['index', 'about'],
    'contact-us'    => ['index', 'contact'],
    'blog'          => ['index', 'blog'],
    'privacy'       => ['index', 'privacy'],
    'terms'         => ['index', 'terms'],
    'thank-you'     => ['index', 'thankyou'],
    'get-started'   => ['index', 'getstarted'],
    'login'         => ['auth', 'login'],
    'case-study-linentablecloth' => ['index', 'casestudyLinentablecloth'],
]);

// Default controller routing (handles aliases + standard auto-routing)
Flight::defaultRoute();
