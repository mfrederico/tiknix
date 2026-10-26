<?php
/** The service did not answer, or refused. @var string $why @var string $back */
?>
<?= \app\Ui::page(['title' => 'Messaging is not answering', 'back' => ['label' => 'Back', 'url' => $back]]) ?>
<?= \app\Ui::notice(['tone' => 'danger', 'title' => 'The messaging service could not be reached, or refused this app.', 'text' => $why
    . ' Messaging is a Tiknix service the project\'s owner switches on; the app reaches it with its broker key (conf/broker.ini).']) ?>
