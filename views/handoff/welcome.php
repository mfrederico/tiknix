<?php
/**
 * A signed-out visitor holding a plan's claim link: the one door that fits.
 *   an account here already  → a sign-in link by email (or their password)
 *   a new address the start site proved is theirs → one press makes the account (no password) and goes to connecting their AI
 *   a new address not proven, or none → the sign-up form, with the address filled in
 * @var string $name @var string $email @var bool $known @var bool $verified @var string $token @var string $back @var string $login @var string $register
 */
if ($known) {
    echo \app\Ui::page(['title' => 'Welcome back', 'lead' => "We email {$email} a sign-in link, and you start building {$name}.",
        'primary' => ['label' => 'Email me a sign-in link', 'post' => '/signin/send', 'fields' => ['email' => $email, 'to' => $back], 'icon' => 'envelope'],
        'more' => [['label' => 'Sign in with my password', 'url' => $login]]]);
} elseif ($email !== '' && $verified) {
    echo \app\Ui::page(['title' => 'Your project is ready to build', 'lead' => "One press makes your free account for {$email}, and we start on {$name}. No password needed: you sign in with links we email you.",
        'primary' => ['label' => 'Create my account and build', 'post' => '/handoff/join', 'fields' => ['token' => $token], 'icon' => 'rocket-takeoff'],
        'more' => [['label' => 'I already have a Tiknix account', 'url' => $login]]]);
} else {
    // No proven address: confirm it here. The emailed link makes the account (no password) and carries on to the AI, then the build.
    echo \app\Ui::page(['title' => 'Your project is ready to build', 'lead' => "Confirm your email and we make your free account, then start on {$name}. No password needed.",
        'more' => [['label' => 'I already have a Tiknix account', 'url' => $login]]]);
    echo \app\Ui::form(['action' => '/signin/join', 'submit' => 'Email me a link to create my account', 'values' => ['email' => $email],
        'hidden' => ['to' => '/yourai?next=' . rawurlencode($back), 'back' => $back],
        'fields' => [['name' => 'email', 'label' => 'Your email', 'type' => 'email', 'required' => true, 'autocomplete' => 'email', 'help' => 'One click on the link confirms it and makes your account.']]]);
}
echo \app\Ui::notice(['tone' => 'info', 'title' => 'Tiknix is a harness utility.', 'text' => 'You bring the AI that builds (an API key or your Claude subscription). Right after your account, we show you where to connect it.']);
