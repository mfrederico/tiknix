<?php /** @var string $email @var string $to */ ?>
<?= \app\Ui::page(['title' => 'Check your email', 'lead' => "If {$email} has an account, a sign-in link is on its way. It works once, for 15 minutes."]) ?>
<?= \app\Ui::notice(['tone' => 'info', 'title' => 'Nothing arrived?', 'text' => 'Look in spam, or send another link. You can also sign in with your password if you set one.',
    'action' => ['label' => 'Send another link', 'url' => '/signin?email=' . urlencode($email) . ($to !== '' ? '&to=' . urlencode($to) : '')]]) ?>
