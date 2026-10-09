<?php
/**
 * NewSaaS manifesto — the canonical definition of the term. Standalone like the landing
 * (no app layout), rendered by Newsaas::index() on the flagship host only.
 *
 * Carries its own JSON-LD (Article + DefinedTerm + FAQPage) because search engines and AI
 * assistants are the second audience: the page exists so "what is NewSaaS" resolves here.
 *
 * The page is a scroll story: two pinned scenes (the suit, the spiral) whose drawing changes
 * as each paragraph scrolls past, then the definition. Every word is in the HTML and the page
 * reads top to bottom without script; the "motion" class (set in <head> unless the visitor
 * asked for reduced motion) is what pins the scenes and hides things until they scroll in.
 */
$logoV = @filemtime(\app\Paths::runtime() . '/public/img/tiknix.svg') ?: '1';
$desc  = 'NewSaaS is first-party software with SaaS upkeep: built to fit one business exactly, owned by that business like first-party data is, hosted and maintained like SaaS, and never rented. Here is the argument, with a suit in it.';
$faqs  = [
    ['How is it different from custom development?', 'Custom development usually means a long project, a big invoice, and then silence. This means the software is built fast, kept running by someone, priced per project rather than per seat, and changed the same week your business changes. tiknix is one way to get there; a small shop like ClickSimple is another.'],
    ['Isn\'t this just "build versus buy"?', 'Partly. Build-versus-buy assumes building is slow and expensive, which was true. AI planners and builders changed the cost, so the question now is whether you own what you use. The answer here is yes, always.'],
    ['Do I have to host it myself?', 'No. Someone hosts it, the way SaaS is hosted. The difference is that you can take it with you. On tiknix each project runs in its own isolated environment and can be published to your GitHub or your client\'s domain whenever you like.'],
    ['Who is it for?', 'Any business whose way of working is part of its advantage. Agencies building for clients, founders replacing a stack of subscriptions, operators with a process the big platforms never quite fit. If you have ever hired consultants to change your company so a tool would work, this is for you.'],
    ['What does it cost?', 'Whatever building costs, once, plus someone keeping it running. On tiknix that is a free first project, then $49 a month per project, with no per-seat fees and no credits, because you bring your own model.'],
    ['Who coined NewSaaS?', 'Matthew Frederico of ClickSimple, the company behind tiknix, ShipCannon and DealerYes. The phrase came out of watching companies spend years tailoring themselves to fit software they bought.'],
];
$site = 'https://tiknix.com';
$faqNodes = array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faqs);
$jsonld = [
    '@context' => 'https://schema.org',
    '@graph' => [
        ['@type' => 'Organization', '@id' => $site . '/#org', 'name' => 'tiknix', 'url' => $site . '/', 'logo' => $site . '/rt/img/tiknix.svg', 'parentOrganization' => ['@type' => 'Organization', 'name' => 'ClickSimple', 'url' => 'https://clicksimple.com/'], 'sameAs' => ['https://clicksimple.com/', 'https://github.com/mfrederico']],
        ['@type' => 'Person', '@id' => 'https://clicksimple.com/#matt', 'name' => 'Matthew Frederico', 'url' => 'https://clicksimple.com/about.php', 'sameAs' => ['https://github.com/mfrederico', 'https://linkedin.com/in/mattfred']],
        ['@type' => 'DefinedTerm', '@id' => $site . '/newsaas#term', 'name' => 'NewSaaS', 'url' => $site . '/newsaas', 'description' => 'First-party software with SaaS upkeep: built to fit one business exactly, owned by that business, and run wherever it chooses, with the hosting and maintenance of SaaS and none of the renting.', 'inDefinedTermSet' => ['@type' => 'DefinedTermSet', 'name' => 'tiknix glossary', 'url' => $site . '/newsaas']],
        ['@type' => 'Article', '@id' => $site . '/newsaas#article', 'headline' => 'NewSaaS: you can\'t grow into a suit that wasn\'t cut for you', 'description' => $desc, 'url' => $site . '/newsaas', 'mainEntityOfPage' => $site . '/newsaas', 'author' => ['@id' => 'https://clicksimple.com/#matt'], 'publisher' => ['@id' => $site . '/#org'], 'datePublished' => '2026-09-25', 'dateModified' => date('Y-m-d'), 'about' => ['@id' => $site . '/newsaas#term']],
        ['@type' => 'FAQPage', '@id' => $site . '/newsaas#faq', 'mainEntity' => $faqNodes],
        ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'tiknix', 'item' => $site . '/'], ['@type' => 'ListItem', 'position' => 2, 'name' => 'NewSaaS', 'item' => $site . '/newsaas']]],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($desc) ?>">
    <link rel="canonical" href="<?= $site ?>/newsaas">
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="tiknix">
    <meta property="og:title" content="NewSaaS — software that fits you, that you own">
    <meta property="og:description" content="<?= htmlspecialchars($desc) ?>">
    <meta property="og:url" content="<?= $site ?>/newsaas">
    <meta name="twitter:card" content="summary">
    <script>
        /* Before first paint, so nothing flashes: motion only for visitors who have not asked for less of it. */
        if (window.matchMedia && 'IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
            document.documentElement.className += ' motion';
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/_marketing-style.php'; ?>
    <style>
        :root{ --tape:#f4c542; --ink:#1b2440; --ease:cubic-bezier(.2,.8,.2,1); }
        html, body{ overflow-x:clip; }
        /* A flat page colour below the first screen, so a pinned stage can paint the same colour over the text scrolling under it. */
        body{ background:
                radial-gradient(1200px 640px at 50% -200px, rgba(59,118,240,0.20), transparent 60%) no-repeat,
                linear-gradient(180deg, var(--bg1) 0, var(--bg2) 420px) no-repeat,
                var(--bg2); }

        /* ---------- hero ---------- */
        .ns-hero{ text-align:center; padding:48px 0 56px; }
        .ns-hero h1{ font-size:clamp(36px,5vw,62px); margin:16px auto 0; max-width:900px; text-wrap:balance; }
        .ns-hero h1 em, .ns-hero h2.second em{ font-style:italic; color:var(--accent2); }
        .ns-hero h2.second{ font-size:clamp(22px,2.9vw,36px); font-weight:600; line-height:1.2; margin:14px auto 0; max-width:760px; text-wrap:balance; }
        .ns-hero .w{ display:inline-block; }
        .ns-hero .sub{ font-size:clamp(16px,1.6vw,19px); color:var(--soft); line-height:1.6; max-width:680px; margin:22px auto 0; }
        .tape{ position:relative; left:50%; width:100vw; margin:38px 0 0 -50vw; height:30px; overflow:hidden; transform:rotate(-1.5deg);
               background:var(--tape); box-shadow:0 10px 30px rgba(0,0,0,0.35); }
        .tape i{ display:block; height:100%; width:calc(100% + 700px);
                 background:
                    repeating-linear-gradient(90deg, var(--ink) 0 2px, transparent 2px 60px) 0 100% / 100% 62% no-repeat,
                    repeating-linear-gradient(90deg, var(--ink) 0 1px, transparent 1px 12px) 0 100% / 100% 34% no-repeat;
                 transform:translate3d(calc(var(--sy, 0) * -0.3px), 0, 0); }
        .cue{ display:none; }

        /* ---------- pinned scenes ---------- */
        .scene{ --sh:max(40vh, 220px); --bh:56vh; padding:44px 0; border-top:1px solid var(--line); }
        @supports (height:1svh){ .scene{ --sh:max(40svh, 220px); --bh:56svh; } }
        .stage{ display:flex; flex-direction:column; align-items:center; justify-content:center; padding:6px 0 10px; }
        .fig{ position:relative; width:min(100%, 360px); aspect-ratio:400 / 380; }
        .fig svg.draw{ width:100%; height:100%; display:block; overflow:visible; }
        .dots{ display:none; }
        .beat{ max-width:560px; padding:12px 0; }
        .beat h2{ font-size:clamp(28px,3.4vw,42px); margin-top:10px; text-wrap:balance; }
        .beat h2.long{ font-size:clamp(21px,2.3vw,29px); line-height:1.3; }   /* a whole sentence, not a title */
        .beat p{ font-size:clamp(16.5px,1.5vw,19px); line-height:1.65; color:var(--soft); margin-top:14px; }
        .beat p strong{ color:var(--text); }
        .beat .n{ font-family:var(--serif); font-size:15px; letter-spacing:.14em; color:var(--tape); }
        .beat h3{ font-size:clamp(24px,2.8vw,34px); margin-top:8px; }
        .scene-head{ text-align:center; padding:64px 0 12px; border-top:1px solid var(--line); }
        .scene-head h2{ font-size:clamp(28px,3.6vw,44px); margin-top:14px; text-wrap:balance; }
        .scene-head p{ font-size:clamp(16px,1.5vw,18px); color:var(--soft); margin:14px auto 0; max-width:560px; line-height:1.6; }
        .scene-head + .scene{ border-top:0; }

        /* scene 1: the suit. The drawing's resting state is the last one (the man, in the suit). */
        .sfig g.man, .sfig g.ad, .sfig g.suit{ transform-origin:200px 350px; transition:transform .8s var(--ease), opacity .45s ease; }
        .sfig g.ad{ opacity:0; transform:translate(88px,0) scale(.8); }
        .sfig g.bubble{ opacity:0; transform:scale(.4); transform-origin:245px 95px; transition:transform .5s var(--ease), opacity .3s ease; }
        .stage[data-beat="0"] g.bubble{ opacity:1; transform:none; transition-delay:.25s; }
        .stage[data-beat="0"] g.suit{ opacity:0; transform:translate(150px,0) scale(.8); }
        .stage[data-beat="0"] g.ad{ transform:translate(150px,0) scale(.8); }
        .stage[data-beat="1"] g.man{ transform:translate(-105px,0) scale(.8); }
        .stage[data-beat="1"] g.ad{ opacity:1; }
        .stage[data-beat="1"] g.suit{ transform:translate(88px,0) scale(.8); }
        .motion .stage[data-beat="2"] g.suit-in{ animation:land .7s var(--ease); }
        @keyframes land{ 0%{ transform:translateY(-16px); } 55%{ transform:translateY(4px); } 100%{ transform:none; } }
        .motion .star{ animation:twinkle 1.1s ease-in-out infinite alternate; }
        .star:nth-of-type(2n){ animation-delay:.4s; } .star:nth-of-type(3n){ animation-delay:.8s; }
        @keyframes twinkle{ from{ opacity:.15; } to{ opacity:1; } }
        .chip-m{ position:absolute; font:700 11px/1 var(--mono); letter-spacing:.02em; white-space:nowrap; color:var(--ink); background:var(--tape);
                 padding:5px 7px; border-radius:4px; box-shadow:0 4px 14px rgba(0,0,0,0.4);
                 opacity:0; transform:scale(.5); transition:opacity .3s ease, transform .45s var(--ease); }
        .chip-m.c1{ right:74%; top:72%; } .chip-m.c2{ left:70%; top:46%; } .chip-m.c3{ left:66%; top:86%; }
        .stage[data-beat="3"] .chip-m{ opacity:1; transform:none; }
        .stage[data-beat="3"] .chip-m.c2{ transition-delay:.15s; } .stage[data-beat="3"] .chip-m.c3{ transition-delay:.3s; }
        .tagx{ position:absolute; left:66%; top:40%; width:max-content; max-width:min(170px, 34vw); transform-origin:0 0; padding:8px 10px 8px 16px; border-radius:5px; background:#f6f1e4; color:var(--ink);
               font:700 10.5px/1.25 var(--mono); letter-spacing:.06em; text-transform:uppercase; box-shadow:0 8px 22px rgba(0,0,0,0.45);
               opacity:0; transform:rotate(-50deg); transition:opacity .25s ease; }
        .tagx::before{ content:''; position:absolute; left:6px; top:50%; width:5px; height:5px; margin-top:-2.5px; border-radius:9px; background:var(--bg2); }
        .tagx small{ display:block; font-weight:400; font-size:9.5px; letter-spacing:.02em; text-transform:none; color:#5a6074; }
        .stage[data-beat="4"] .tagx{ opacity:1; transform:rotate(7deg); animation:swing 1.5s ease-out; }
        @keyframes swing{ 0%{ transform:rotate(-50deg); } 30%{ transform:rotate(18deg); } 55%{ transform:rotate(0deg); } 78%{ transform:rotate(10deg); } 100%{ transform:rotate(7deg); } }

        /* scene 2: the spiral. A company-shaped blob is trimmed until it fits the software's square. Resting state: trimmed. */
        .fitsq{ position:absolute; top:50%; left:50%; height:86%; aspect-ratio:1; transform:translate(-50%,-50%); }
        .co{ position:absolute; inset:17%; border-radius:6px; background:#343d56; transition:transform .9s var(--ease), border-radius .9s var(--ease); }
        .co::before{ content:''; position:absolute; inset:0; border-radius:inherit; background:linear-gradient(135deg, #3ddc97, #3b76f0 75%); opacity:0; transition:opacity .9s ease; }
        .co b{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; text-align:center; padding:0 14% 12%;
               font:italic 500 clamp(13px,3.6vw,18px)/1.2 var(--serif); transition:opacity .5s ease; }
        .co b.was{ color:#06122b; opacity:0; } .co b.now{ color:var(--soft); }
        .co i{ position:absolute; bottom:15%; width:7%; aspect-ratio:1; border-radius:50%; background:rgba(255,255,255,0.92); transition:transform .9s ease-in, opacity .7s ease; }
        .co i:nth-of-type(1){ left:22%; } .co i:nth-of-type(2){ left:34.5%; } .co i:nth-of-type(3){ left:47%; } .co i:nth-of-type(4){ left:59.500%; } .co i:nth-of-type(5){ left:72%; }
        .stage[data-beat="4"] .co i:nth-of-type(2), .stage[data-beat="4"] .co i:nth-of-type(4), .stage[data-beat="4"] .co i:nth-of-type(5){ transform:translateY(520%); opacity:0; }
        .stage[data-beat="4"] .co i:nth-of-type(4){ transition-delay:.2s; } .stage[data-beat="4"] .co i:nth-of-type(5){ transition-delay:.4s; }
        .swf{ position:absolute; inset:17%; border:2px dashed var(--accent2); border-radius:6px; transition:border-color .5s ease; }
        .swf span{ position:absolute; left:-2px; bottom:100%; margin-bottom:5px; padding:3px 6px; border-radius:4px; background:var(--bg2); font:700 10px/1 var(--mono); letter-spacing:.14em; color:var(--accent2); white-space:nowrap; }
        .snip{ position:absolute; top:7%; right:5%; width:14%; color:var(--tape); opacity:0; transition:opacity .4s ease; }
        .count{ display:none; position:absolute; top:0; left:0; font:700 clamp(26px,7vw,42px)/1 var(--serif); color:var(--accent2); }
        .count small{ font:600 11px/1 var(--mono); color:var(--dim); letter-spacing:.08em; }
        .stage[data-beat="0"] .co{ transform:scale(1.28) rotate(-8deg); border-radius:58% 42% 63% 37% / 45% 55% 45% 55%; }
        .stage[data-beat="1"] .co{ transform:scale(1.2) rotate(-5deg); border-radius:54% 46% 58% 42% / 48% 52% 48% 52%; }
        .stage[data-beat="2"] .co{ transform:scale(1.1) rotate(-2deg); border-radius:34%; }
        .stage[data-beat="3"] .co{ transform:scale(1.03); border-radius:14%; }
        .stage[data-beat="0"] .co::before, .stage[data-beat="1"] .co::before{ opacity:1; }
        .stage[data-beat="2"] .co::before{ opacity:.8; } .stage[data-beat="3"] .co::before{ opacity:.3; }
        .stage[data-beat="0"] .co b.was, .stage[data-beat="1"] .co b.was, .stage[data-beat="2"] .co b.was{ opacity:1; }
        .stage[data-beat="0"] .co b.now, .stage[data-beat="1"] .co b.now, .stage[data-beat="2"] .co b.now{ opacity:0; }
        .stage[data-beat="1"] .swf{ border-color:#ef6f6f; }
        .motion .stage[data-beat="1"] .swf{ animation:strain .35s ease-in-out 4; }
        @keyframes strain{ 50%{ transform:scale(1.025); } }
        .stage[data-beat="2"] .snip, .stage[data-beat="3"] .snip{ opacity:1; }
        .motion .snip{ animation:snip .45s ease-in-out infinite alternate; }
        @keyframes snip{ from{ transform:rotate(-14deg); } to{ transform:rotate(10deg); } }

        /* ---------- definition ---------- */
        .fitfig{ display:block; height:clamp(180px,30vh,250px); margin:0 auto 6px; overflow:visible; --f:1; }
        .fitfig .big{ transform-origin:200px 150px; transform:scale(calc(1 - .4 * var(--f)), calc(1 - .12 * var(--f))); opacity:calc(1 - var(--f) * 1.5); }
        .fitfig .fitted{ opacity:calc(var(--f) * 2.5 - 1.5); }
        .fitfig .spark{ opacity:calc(var(--f) * 6 - 5); }
        .fit-cap{ font:600 12px/1 var(--mono); letter-spacing:.12em; text-transform:uppercase; color:var(--tape); margin-bottom:22px; }
        .word{ font-size:clamp(52px,11vw,120px) !important; letter-spacing:-0.03em; margin-top:10px !important; }
        .word em{ font-style:italic; color:var(--accent2); }
        .ns-lede{ font-size:clamp(18px,2vw,23px); line-height:1.5; color:var(--soft); text-align:center; max-width:720px; margin:0 auto; text-wrap:balance; }
        .ns-lede strong{ color:var(--text); }
        .two{ display:grid; grid-template-columns:repeat(auto-fit,minmax(min(320px,100%),1fr)); gap:22px; margin-top:36px; }
        .two .card{ padding:clamp(22px,4vw,28px); }
        .two h3{ font-family:var(--sans); font-size:17px; font-weight:700; }
        .two ul{ margin-top:14px; padding-left:0; list-style:none; display:grid; gap:9px; }
        .two li{ font-size:15px; color:var(--soft); padding-left:22px; position:relative; line-height:1.5; }
        .two .bad li::before{ content:'–'; color:#ef6f6f; position:absolute; left:0; }
        .two .good li::before{ content:'+'; color:var(--good); position:absolute; left:0; }

        /* ---------- quote, faq ---------- */
        .ns-quote{ font-family:var(--serif); font-style:italic; font-size:clamp(26px,4.4vw,48px); line-height:1.22; color:var(--text);
                    text-align:center; max-width:900px; margin:0 auto; text-wrap:balance; padding:4vh 0; }
        .ns-quote small{ display:block; margin-top:22px; font-family:var(--sans); font-style:normal; font-size:13px; letter-spacing:.14em; text-transform:uppercase; color:var(--accent2); font-weight:600; }
        .ns-go{ text-align:center; padding:8px 0 4vh; }
        .ns-go .btn{ font-size:18px; padding:17px 34px; }
        .ns-quote .q span{ transition:opacity .35s ease; }
        .motion .ns-quote .q span{ opacity:.16; } .motion .ns-quote .q span.lit{ opacity:1; }
        .faq-list{ max-width:820px; margin:0 auto; display:grid; gap:12px; }
        .faq-list summary{ list-style:none; cursor:pointer; position:relative; padding:20px 54px 20px 22px; font-size:17px; font-weight:700; line-height:1.35; }
        .faq-list summary::-webkit-details-marker{ display:none; }
        .faq-list summary::after{ content:'+'; position:absolute; right:20px; top:50%; transform:translateY(-50%); font-size:24px; font-weight:400; color:var(--accent2); transition:transform .25s ease; }
        .faq-list details[open] summary::after{ transform:translateY(-50%) rotate(45deg); }
        .faq-list details p{ padding:0 22px 22px; font-size:15.5px; line-height:1.65; color:var(--soft); }

        /* ---------- motion: only what the "motion" class adds ---------- */
        .progress{ display:none; }
        .motion .progress{ display:block; position:fixed; z-index:20; top:0; left:0; right:0; height:4px; background:var(--tape); transform-origin:0 50%; transform:scaleX(0); }
        .motion [data-rv]{ opacity:0; transform:translateY(24px); transition:opacity .7s ease var(--d,0s), transform .8s var(--ease) var(--d,0s); }
        .motion [data-rv].in{ opacity:1; transform:none; }
        .motion .ns-hero{ min-height:calc(100vh - 86px); display:flex; flex-direction:column; justify-content:center; padding:20px 0 28px; }
        @supports (height:1svh){ .motion .ns-hero{ min-height:calc(100svh - 86px); } }
        .motion .ns-hero .w{ opacity:0; transform:translateY(.55em) rotate(4deg); animation:wordin .75s var(--ease) forwards; animation-delay:calc(var(--i) * 75ms + .1s); }
        @keyframes wordin{ to{ opacity:1; transform:none; } }
        .motion .ns-hero .eyebrow, .motion .ns-hero .sub, .motion .ns-hero .tape, .motion .cue{ opacity:0; animation:fadeup .8s var(--ease) forwards; }
        .motion .ns-hero .sub{ animation-delay:1.5s; } .motion .ns-hero .tape{ animation-name:tapein; animation-delay:1.75s; } .motion .cue{ animation-delay:2.3s; }
        @keyframes fadeup{ from{ opacity:0; transform:translateY(14px); } to{ opacity:1; transform:none; } }
        @keyframes tapein{ from{ opacity:0; transform:rotate(-1.5deg) translateX(-30%); } to{ opacity:1; transform:rotate(-1.5deg); } }
        .motion .cue{ display:block; margin-top:34px; font:600 12px/1 var(--mono); letter-spacing:.14em; text-transform:uppercase; color:var(--dim); }
        .cue b{ display:block; width:22px; height:22px; margin:12px auto 0; border-right:2px solid var(--tape); border-bottom:2px solid var(--tape); transform:rotate(45deg); animation:bob 1.4s ease-in-out infinite; }
        @keyframes bob{ 50%{ transform:translateY(7px) rotate(45deg); } }

        .motion .scene{ padding:0; }
        .motion .stage{ position:sticky; top:0; z-index:3; height:var(--sh); padding:8px 0 6px; background:var(--bg2); border-bottom:1px solid var(--line); }
        .motion .stage::after{ content:''; position:absolute; left:0; right:0; top:100%; margin-top:1px; height:26px; background:linear-gradient(var(--bg2), transparent); pointer-events:none; }
        .motion .fig{ width:min(100%, calc((var(--sh) - 34px) * 400 / 380)); }
        .motion .dots{ display:flex; gap:6px; margin-top:8px; height:6px; }
        .dots i{ width:6px; height:6px; border-radius:9px; background:var(--line2); transition:width .4s var(--ease), background .3s ease; }
        .dots i.on{ width:20px; background:var(--tape); }
        .motion .beat{ min-height:var(--bh); padding:30px 0 0; opacity:.22; transition:opacity .45s ease; }
        .motion .beat.on{ opacity:1; }
        .motion .beat:last-child{ padding-bottom:12vh; }
        .motion .count{ display:block; }

        /* side by side: wide screens, and phones on their side (too short to stack a stage above the text) */
        @media (min-width:820px), (orientation:landscape) and (max-height:520px){
            .scene{ display:grid; grid-template-columns:1fr 1fr; gap:clamp(24px,5vw,72px); align-items:start; }
            .scene.flip .stage{ order:2; }
            .stage{ position:sticky; top:24px; }
            .beat{ padding:18px 0; }
            .motion .stage{ top:0; height:100vh; background:none; border-bottom:0; }
            .motion .stage::after{ display:none; }
            .motion .fig{ width:min(100%, 470px, calc((100vh - 70px) * 400 / 380)); }
            .motion .beats{ padding:8vh 0; }
            .motion .beat{ min-height:78vh; padding:0; display:flex; flex-direction:column; justify-content:center; }
            .motion .beat:last-child{ padding-bottom:0; }
            .chip-m{ font-size:13px; padding:6px 9px; }
            .tagx{ font-size:12px; } .tagx small{ font-size:11px; }
        }
    </style>
    <script type="application/ld+json"><?= json_encode($jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body>
<div class="progress" aria-hidden="true"></div>

<div class="container">

  <?php include __DIR__ . '/_marketing-nav.php'; ?>

  <!-- HERO -->
  <section class="ns-hero">
    <div class="eyebrow">NewSaaS · an analogy, with a suit in it</div>
    <h1>
      <span class="w" style="--i:0">Stop</span> <span class="w" style="--i:1">software</span>
      <em><span class="w" style="--i:2">overfitting!</span></em>
    </h1>
    <h2 class="second">
      <span class="w" style="--i:5">You</span> <span class="w" style="--i:6">can&rsquo;t</span> <span class="w" style="--i:7">grow</span>
      <span class="w" style="--i:8">into</span> <span class="w" style="--i:9">a</span> <span class="w" style="--i:10">suit</span>
      <em><span class="w" style="--i:11">that</span> <span class="w" style="--i:12">wasn&rsquo;t</span> <span class="w" style="--i:13">cut</span>
      <span class="w" style="--i:14">for</span> <span class="w" style="--i:15">you.</span></em>
    </h2>
    <p class="sub">
      The story below is an analogy. It&rsquo;s about a man who bought a suit off a magazine ad, and it&rsquo;s how
      a lot of companies buy the SaaS software and systems they think they need. Then: what that costs them
      every year, and the word we use for the alternative.
    </p>
    <div class="tape" aria-hidden="true"><i></i></div>
    <div class="cue" aria-hidden="true">Scroll. It&rsquo;s a short story.<b></b></div>
  </section>

  <!-- THE STORY: a pinned drawing, one state per paragraph -->
  <section class="scene" id="suit">
    <div class="stage" data-beat="3" aria-hidden="true">
      <div class="fig sfig">
        <svg class="draw" viewBox="0 0 400 380" role="presentation" focusable="false">
          <defs>
            <radialGradient id="ns-glow"><stop offset="0" stop-color="#6b97f6" stop-opacity=".26"/><stop offset="1" stop-color="#6b97f6" stop-opacity="0"/></radialGradient>
            <linearGradient id="ns-ad" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#27407c"/><stop offset="1" stop-color="#101d40"/></linearGradient>
            <path id="ns-star" d="M0-15 3.500-3.500 15 0 3.500 3.500 0 15-3.500 3.500-15 0-3.500-3.500Z"/>
            <g id="ns-man">
              <ellipse cx="200" cy="347" rx="48" ry="7" fill="#000" opacity=".35"/>
              <ellipse cx="188" cy="340" rx="13" ry="6.500" fill="#8a5a3c"/><ellipse cx="212" cy="340" rx="13" ry="6.500" fill="#8a5a3c"/>
              <rect x="184" y="250" width="14" height="88" rx="7" fill="#f0c49c"/><rect x="202" y="250" width="14" height="88" rx="7" fill="#f0c49c"/>
              <rect x="164" y="156" width="13" height="88" rx="6.500" fill="#f0c49c"/><rect x="223" y="156" width="13" height="88" rx="6.500" fill="#f0c49c"/>
              <rect x="178" y="150" width="44" height="100" rx="13" fill="#e8edf9"/>
              <rect x="163" y="152" width="15" height="30" rx="7" fill="#e8edf9"/><rect x="222" y="152" width="15" height="30" rx="7" fill="#e8edf9"/>
              <rect x="178" y="236" width="44" height="36" rx="8" fill="#6b97f6"/>
              <circle cx="189" cy="248" r="2.200" fill="#fff"/><circle cx="203" cy="244" r="2.200" fill="#fff"/><circle cx="213" cy="254" r="2.200" fill="#fff"/><circle cx="196" cy="260" r="2.200" fill="#fff"/>
              <rect x="194" y="136" width="12" height="18" fill="#f0c49c"/>
              <circle cx="200" cy="118" r="25" fill="#f0c49c"/>
              <path d="M175.500 113a24.500 24.500 0 0 1 49 0q-11-9-24.500-7.500q-13.500-1.500-24.500 7.500z" fill="#5a3d2b"/>
              <circle cx="191" cy="120" r="2.600" fill="#1b2440"/><circle cx="209" cy="120" r="2.600" fill="#1b2440"/>
              <path d="M191 130q9 7 18 0" fill="none" stroke="#1b2440" stroke-width="2.200" stroke-linecap="round"/>
            </g>
            <g id="ns-suit" stroke="rgba(255,255,255,0.16)" stroke-width="1.500" stroke-linejoin="round">
              <path d="M150 256H200L199 330C199 346 150 348 146 334Z" fill="#434c68"/>
              <path d="M200 256H250L254 334C250 348 201 346 201 330Z" fill="#434c68"/>
              <path d="M151 314q23 9 47 0M150 324q24 10 48 1M202 314q24 9 48 0M202 325q24 9 50-1" fill="none" stroke="#2c344c" stroke-width="2"/>
              <path d="M132 162Q118 166 116 190L112 300Q126 311 143 302L147 190Z" fill="#414a65"/>
              <path d="M268 162Q282 166 284 190L288 300Q274 311 257 302L253 190Z" fill="#414a65"/>
              <path d="M132 168Q132 150 160 148H240Q268 150 268 168L262 272H138Z" fill="#4b5573"/>
              <path d="M184 148H216L200 198Z" fill="#e8edf9" stroke="none"/>
              <path d="M196 152H204L207 188 200 197 193 188Z" fill="#3b76f0" stroke="none"/>
              <path d="M184 148 200 200 176 180 170 150Z" fill="#363f59"/><path d="M216 148 200 200 224 180 230 150Z" fill="#363f59"/>
              <circle cx="200" cy="222" r="3" fill="#2c344c" stroke="none"/><circle cx="200" cy="242" r="3" fill="#2c344c" stroke="none"/>
              <rect x="228" y="196" width="18" height="4" rx="1" fill="#e8edf9" stroke="none"/>
            </g>
          </defs>
          <circle cx="200" cy="200" r="185" fill="url(#ns-glow)"/>
          <g class="man"><use href="#ns-man"/></g>
          <g class="bubble">
            <circle cx="238" cy="92" r="4" fill="#fff"/><circle cx="251" cy="78" r="6.500" fill="#fff"/>
            <ellipse cx="296" cy="52" rx="40" ry="33" fill="#fff"/>
            <use href="#ns-suit" transform="translate(266 15) scale(.15)"/>
          </g>
          <g class="ad">
            <rect x="96" y="40" width="208" height="326" rx="12" fill="url(#ns-ad)" stroke="#6b97f6" stroke-width="2.500"/>
            <use class="star" href="#ns-star" x="128" y="74" fill="#f4c542"/><use class="star" href="#ns-star" x="276" y="112" fill="#f4c542"/>
            <use class="star" href="#ns-star" x="264" y="66" fill="#fff"/><use class="star" href="#ns-star" x="134" y="122" fill="#fff"/>
            <ellipse cx="172" cy="348" rx="26" ry="9" fill="#0a1228"/><ellipse cx="228" cy="348" rx="26" ry="9" fill="#0a1228"/>
            <circle cx="127" cy="306" r="12" fill="#b07d55"/><circle cx="273" cy="306" r="12" fill="#b07d55"/>
            <rect x="183" y="128" width="34" height="26" fill="#b07d55"/>
            <circle cx="200" cy="108" r="31" fill="#b07d55"/>
            <path d="M180 100h15M206 97q8-7 16-1" fill="none" stroke="#3a2718" stroke-width="3" stroke-linecap="round"/>
            <circle cx="188" cy="108" r="2.800" fill="#1b2440"/><circle cx="213" cy="108" r="2.800" fill="#1b2440"/>
            <path d="M190 122q11 6 21-2" fill="none" stroke="#3a2718" stroke-width="2.400" stroke-linecap="round"/>
          </g>
          <g class="suit"><g class="suit-in"><use href="#ns-suit"/></g></g>
        </svg>
        <span class="chip-m c1">sleeves +6&Prime;</span>
        <span class="chip-m c2">chest +14&Prime;</span>
        <span class="chip-m c3">inseam +5&Prime;</span>
        <span class="tagx">Big-boy software<small>one size: someone else&rsquo;s</small></span>
      </div>
      <div class="dots"></div>
    </div>

    <div class="beats">
      <div class="beat">
        <div class="eyebrow">The suit</div>
        <h2 class="long">A man realizes that other people are wearing suits, and it makes them look trustworthy and noble, so he wants a new suit.</h2>
      </div>
      <div class="beat">
        <p>
          He goes shopping, passes an ad on an endcap, and there&rsquo;s Dwayne Johnson looking magnificent in a
          charcoal two-piece. <em>I must have that suit.</em>
        </p>
      </div>
      <div class="beat">
        <p>
          So he buys it. The exact suit, the exact cut, in the exact size that fits the shape of Dwayne Johnson,
          fully expecting to look just as good.
        </p>
      </div>
      <div class="beat">
        <p>
          He walks out beaming. <strong>They&rsquo;ll respect me now.</strong> Sleeves past his knuckles. Inseam pooling
          over his shoes. Enough fabric in the chest to hide a second, smaller man.
        </p>
      </div>
      <div class="beat">
        <p>
          It&rsquo;s a silly picture, and everyone can see the fix: you get a suit cut for your own shape. We&rsquo;re all
          different, after all.
        </p>
        <p>
          <strong>Oddly enough, this is what most companies do with their &ldquo;big-boy&rdquo; software.</strong>
        </p>
      </div>
    </div>
  </section>

  <!-- THE SPIRAL -->
  <div class="scene-head">
    <div class="eyebrow" data-rv>What it costs</div>
    <h2 data-rv style="--d:.08s">The tailoring goes the wrong direction.</h2>
    <p data-rv style="--d:.16s">The pattern repeats in company after company. It runs on a schedule you can almost set your watch by.</p>
  </div>
  <section class="scene flip" id="spiral">
    <div class="stage" data-beat="4" aria-hidden="true">
      <div class="fig">
        <div class="count"><span>01</span><small> / 05</small></div>
        <div class="fitsq">
          <div class="co">
            <b class="was">what the company loves doing</b>
            <b class="now">a software-first company</b>
            <i></i><i></i><i></i><i></i><i></i>
          </div>
          <div class="swf"><span>THE SOFTWARE</span></div>
          <svg class="snip" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4 8.120 15.880M14.470 14.480 20 20M8.120 8.120 12 12"/></svg>
        </div>
      </div>
      <div class="dots"></div>
    </div>

    <div class="beats">
      <div class="beat"><div class="n">01</div><h3>The contract</h3><p>Multi-year, multi-million, for a system that almost fits the company&rsquo;s current shape. It looked great on someone else.</p></div>
      <div class="beat"><div class="n">02</div><h3>Sunk cost</h3><p>Eighteen months in, it still doesn&rsquo;t fit. The CTO now has to justify the whole contract, so the problem becomes the company.</p></div>
      <div class="beat"><div class="n">03</div><h3>The tailors</h3><p>Consultants and integrators arrive to fit and trim. Not the software. The business. Workflows get rewritten to match the screens.</p></div>
      <div class="beat"><div class="n">04</div><h3>Software first</h3><p>A few years on, the company has drifted from what it loved doing to a &ldquo;software-first&rdquo; company, forever bulking and cutting to fit the suit.</p></div>
      <div class="beat"><div class="n">05</div><h3>The reckoning</h3><p>Layoffs, ugly meetings, finger-wagging. The CFO and CEO want justification for a system that never supported the actual workflow.</p></div>
    </div>
  </section>

  <!-- THE DEFINITION -->
  <section class="band" id="definition">
    <div class="band-head">
      <svg class="fitfig" viewBox="100 80 200 280" aria-hidden="true" focusable="false">
        <use href="#ns-man"/>
        <g class="big"><use href="#ns-suit"/></g>
        <g class="fitted" stroke="rgba(255,255,255,0.22)" stroke-width="1.200" stroke-linejoin="round">
          <rect x="177" y="244" width="46" height="32" rx="6" fill="#27479a" stroke="none"/>
          <rect x="182" y="244" width="17" height="88" rx="4" fill="#27479a"/><rect x="201" y="244" width="17" height="88" rx="4" fill="#27479a"/>
          <rect x="162" y="152" width="16" height="76" rx="7" fill="#2a4da6"/><rect x="222" y="152" width="16" height="76" rx="7" fill="#2a4da6"/>
          <path d="M175 164Q175 150 188 149H212Q225 150 225 164L223 252H177Z" fill="#3059c2"/>
          <path d="M192 149H208L200 176Z" fill="#e8edf9" stroke="none"/>
          <path d="M198 152H202L203.500 169 200 174 196.500 169Z" fill="#f4c542" stroke="none"/>
          <path d="M192 149 200 178 187 166 185 150Z" fill="#234290"/><path d="M208 149 200 178 213 166 215 150Z" fill="#234290"/>
          <circle cx="200" cy="196" r="2" fill="#16306f" stroke="none"/><circle cx="200" cy="212" r="2" fill="#16306f" stroke="none"/>
        </g>
        <g class="spark">
          <use class="star" href="#ns-star" x="142" y="130" fill="#f4c542"/><use class="star" href="#ns-star" x="262" y="112" fill="#fff"/>
          <use class="star" href="#ns-star" x="256" y="230" fill="#f4c542"/><use class="star" href="#ns-star" x="138" y="250" fill="#fff"/>
        </g>
      </svg>
      <div class="fit-cap" data-rv>Same man. Cut for him.</div>
      <div class="eyebrow" data-rv>The word for the alternative</div>
      <h2 class="word" data-rv style="--d:.1s">New<em>SaaS</em></h2>
    </div>
    <p class="ns-lede" data-rv style="--d:.2s">
      Software built to fit one business exactly, owned by that business, and run wherever it chooses.
      The short way to say it: <strong>first-party software, with SaaS upkeep.</strong>
    </p>

    <div class="two">
      <div class="card" data-rv>
        <h3>Third-party SaaS <span style="color:var(--dim); font-weight:400;">(the suit off the ad)</span></h3>
        <ul class="bad">
          <li>One product, cut for thousands of companies, adjusted by none of them.</li>
          <li>Per seat, per month, forever. Hire someone, pay more.</li>
          <li>The feature you need is &ldquo;on the roadmap.&rdquo; It has been for a while.</li>
          <li>Your data lives at their house. Cancel, and it all evaporates.</li>
          <li>Credits, tokens and meters decide how much you get to build.</li>
          <li>You hire tailors to change the company until the software fits.</li>
        </ul>
      </div>
      <div class="card" data-rv style="--d:.15s; border-color:rgba(59,118,240,0.45);">
        <h3>NewSaaS <span style="color:var(--dim); font-weight:400;">(first-party, with upkeep)</span></h3>
        <ul class="good">
          <li>Built around how your business actually runs today.</li>
          <li>You own every line. It sits in your GitHub, or your client&rsquo;s.</li>
          <li>Hosted, patched and watched, without a lease attached.</li>
          <li>Priced per project, never per seat.</li>
          <li>Your own model, no credits or tokens. Nobody meters your builds.</li>
          <li>When the business changes, the software changes. Same week.</li>
        </ul>
      </div>
    </div>
  </section>

  <!-- THE TL;DR -->
  <section class="band">
    <p class="ns-quote">
      <span class="q">You probably never needed the suit. You probably needed the Jack Black version: shorts and a flannel shirt.</span>
      <small data-rv>Keep it light. Build it with love, attention and intention.</small>
    </p>
    <div class="ns-go" data-rv style="--d:.15s">
      <a class="btn btn-primary" href="https://start.tiknix.com/start">Let&rsquo;s do this</a>
    </div>
  </section>

  <!-- FAQ -->
  <section class="band" id="faq">
    <div class="band-head">
      <div class="eyebrow" data-rv>Questions</div>
      <h2 data-rv style="--d:.08s">Straight answers</h2>
    </div>
    <div class="faq-list">
      <?php foreach ($faqs as $i => $f): ?>
      <details class="card" data-rv<?= $i === 0 ? ' open' : '' ?>><summary><?= htmlspecialchars($f[0]) ?></summary><p><?= htmlspecialchars($f[1]) ?></p></details>
      <?php endforeach; ?>
    </div>
  </section>

  <?php include __DIR__ . '/_marketing-foot.php'; ?>

</div>

<script>
(function () {
    var root = document.documentElement;
    if (!root.classList.contains('motion')) return;   // reduced motion: the page stays a plain, complete article

    var clamp = function (v) { return v < 0 ? 0 : v > 1 ? 1 : v; };

    // Things that fade up once, when they scroll in.
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
            if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
        });
    }, { rootMargin: '0px 0px -10% 0px', threshold: 0.08 });
    document.querySelectorAll('[data-rv]').forEach(function (el) { io.observe(el); });

    // Pinned scenes: the paragraph nearest the reading line picks the drawing's state.
    var scenes = [].map.call(document.querySelectorAll('.scene'), function (el) {
        var sc = { el: el, stage: el.querySelector('.stage'), beats: [].slice.call(el.querySelectorAll('.beat')), cur: -1,
                   count: el.querySelector('.count span'), dots: [] };
        var dots = el.querySelector('.dots');
        sc.beats.forEach(function () { sc.dots.push(dots.appendChild(document.createElement('i'))); });
        return sc;
    });
    function setBeat(sc, n) {
        if (n === sc.cur) return;
        sc.cur = n;
        sc.stage.setAttribute('data-beat', n);
        sc.beats.forEach(function (b, i) { b.classList.toggle('on', i === n); });
        sc.dots.forEach(function (d, i) { d.classList.toggle('on', i === n); });
        if (sc.count) sc.count.textContent = '0' + (n + 1);
    }

    // The closing line lights up a word at a time.
    var quote = document.querySelector('.ns-quote .q'), words = [], lit = -1;
    quote.innerHTML = quote.textContent.trim().split(/\s+/).map(function (w) { return '<span>' + w + '</span>'; }).join(' ');
    words = [].slice.call(quote.children);

    var bar = document.querySelector('.progress'), hero = document.querySelector('.ns-hero'), fit = document.querySelector('.fitfig');
    var queued = false;
    function frame() {
        queued = false;
        var vh = window.innerHeight, y = window.scrollY, max = root.scrollHeight - vh;
        bar.style.transform = 'scaleX(' + (max > 0 ? clamp(y / max) : 0) + ')';
        if (y < vh * 1.5) hero.style.setProperty('--sy', y.toFixed(0));

        scenes.forEach(function (sc) {
            var r = sc.el.getBoundingClientRect();
            if (r.bottom < -vh || r.top > vh * 2) return;
            var st = sc.stage.getBoundingClientRect();
            var stacked = st.width > r.width * 0.8;                      // the stage sits above the text (phones)
            var line = stacked ? st.bottom + (vh - st.bottom) * 0.6 : vh * 0.62;
            var n = 0;
            sc.beats.forEach(function (b, i) { if (b.getBoundingClientRect().top < line) n = i; });
            setBeat(sc, n);
        });

        var fr = fit.getBoundingClientRect();
        if (fr.bottom > -vh && fr.top < vh * 2) fit.style.setProperty('--f', clamp((vh * 0.92 - fr.top) / (vh * 0.5)).toFixed(3));

        var qr = quote.getBoundingClientRect();
        if (qr.bottom > -vh && qr.top < vh * 2) {
            var upTo = Math.round(clamp((vh * 0.88 - qr.top) / (vh * 0.45)) * words.length);
            if (upTo !== lit) { lit = upTo; words.forEach(function (w, i) { w.classList.toggle('lit', i < upTo); }); }
        }
    }
    function queue() { if (!queued) { queued = true; requestAnimationFrame(frame); } }
    window.addEventListener('scroll', queue, { passive: true });
    window.addEventListener('resize', queue);
    frame();
})();
</script>
</body>
</html>
