<?php
/** A signed-out visitor holding a plan's claim link: the one door that fits (an account here already, or a new one). @var string $name @var string $email @var bool $known @var string $login @var string $register */
if ($known) {
    echo \app\Ui::page(['title' => 'Welcome back', 'lead' => "Sign in as {$email} and we start building {$name}.",
        'primary' => ['label' => 'Sign in to build', 'url' => $login, 'icon' => 'box-arrow-in-right'],
        'more' => [['label' => 'Use a different account', 'url' => '/auth/register']]]);
} elseif ($email !== '') {
    echo \app\Ui::page(['title' => 'Your project is ready to build', 'lead' => "Create your free account with {$email} and we start building {$name}. Your first project is free.",
        'primary' => ['label' => 'Create my account', 'url' => $register, 'icon' => 'person-plus'],
        'more' => [['label' => 'I already have a Tiknix account', 'url' => $login]]]);
} else {
    echo \app\Ui::page(['title' => 'Your project is ready to build', 'lead' => "Sign in or create your free account, and we start building {$name}. Your first project is free.",
        'primary' => ['label' => 'Create my account', 'url' => $register, 'icon' => 'person-plus'],
        'more' => [['label' => 'I already have a Tiknix account', 'url' => $login]]]);
}
echo \app\Ui::notice(['tone' => 'info', 'title' => 'Tiknix is a harness utility.', 'text' => 'You bring the AI that builds (an API key or your Claude subscription). Right after your account, we show you where to connect it.']);
