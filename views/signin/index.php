<?php /** @var string $email @var string $to */ ?>
<?= \app\Ui::page(['title' => 'Sign in with an email link', 'lead' => 'No password needed: we email you a link that signs you in once.',
    'back' => ['label' => 'Sign in with a password', 'url' => '/auth/login']]) ?>
<?= \app\Ui::form(['action' => '/signin/send', 'submit' => 'Email me a sign-in link', 'values' => ['email' => $email], 'hidden' => ['to' => $to],
    'fields' => [['name' => 'email', 'label' => 'Your email', 'type' => 'email', 'required' => true, 'autocomplete' => 'email', 'autofocus' => $email === '']]]) ?>
