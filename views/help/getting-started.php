<?php
/**
 * Your first project, step by step (/help/start). For people, not developers: what to press, in order, and what happens.
 * ui: hand-made — a numbered guide, not a list, a form or a record.
 */
$__signedIn = !empty($isLoggedIn) && !empty($member);
$step = function (int $n, string $title, string $body, ?array $go = null): string {
    $h = '<li class="ui-panel mb-3"><div class="ui-panel-body d-flex gap-3">'
       . '<div class="flex-none fw-bold text-primary" style="font-size:1.5rem;line-height:1;min-width:1.5rem">' . $n . '</div>'
       . '<div class="flex-grow-1" style="min-width:0"><h2 class="h5 mb-1">' . htmlspecialchars($title) . '</h2><div class="text-body-secondary">' . $body . '</div>';
    if ($go) $h .= '<a class="btn btn-sm btn-outline-primary mt-2" href="' . htmlspecialchars($go[1]) . '">' . htmlspecialchars($go[0]) . '</a>';
    return $h . '</div></div></li>';
};
?>
<?= \app\Ui::page(['title' => 'Your first project, step by step',
    'lead' => 'Tiknix is a harness utility: it gives the AI you bring a place to build your app, with its own server, database, tests, and a browser to check its work. Here is the whole way from nothing to a running app.',
    'back' => ['label' => 'Help', 'url' => '/help']]) ?>

<ol class="list-unstyled" style="max-width:46rem">
<?= $step(1, 'Make an account', 'Most people start by answering a few questions about their business (5 to 10 minutes). We draft a plan for the app, and you make your account at the end. Or just make an account and start with a project.',
          $__signedIn ? null : ['Answer a few questions', 'https://start.tiknix.com/start']) ?>
<?= $step(2, 'Connect your AI', 'Open <strong>Your AI</strong> and paste an API key from your AI provider (Anthropic, OpenRouter, z.ai, DeepSeek or Ollama). We test it on the spot. You connect it once: every project you make builds with it, and you pay your provider directly. Using a Claude subscription instead? Sign in with it on a project\'s <strong>AI agents</strong> page.',
          $__signedIn ? ['Open Your AI', '/yourai'] : null) ?>
<?= $step(3, 'Create a project', 'Open <strong>Projects</strong>, press <strong>New Project</strong>, give your app a name (like "Sunrise Bakery") and press <strong>Create &amp; work on it</strong>. Its own server is ready in about two minutes, with your AI already connected.',
          $__signedIn ? ['Create a project', '/projects?new=1'] : null) ?>
<?= $step(4, 'Ask for what you want', 'In the Builder, open <strong>Ask</strong> and describe it in your own words: "a booking page for my classes, where people pick a time and get an email". The AI plans the work and asks what it needs to know. Answer its questions, then approve the plan.') ?>
<?= $step(5, 'Watch it build', 'Each part of the plan is built, then checked in a real browser. You see every step on the board as it happens. If something needs your answer, it waits and says so.') ?>
<?= $step(6, 'Open your app', 'The project bar at the top links to your app at its own address. Ask for changes the same way, as often as you like. When it is ready for the world, <strong>Deploy</strong> puts it on your own domain or your GitHub.') ?>
</ol>

<?= \app\Ui::notice(['tone' => 'neutral', 'title' => 'Stuck?', 'text' => 'Tell us what you were doing and what happened instead. A person reads every message.',
    'action' => ['label' => 'Message support', 'url' => '/contact']]) ?>
