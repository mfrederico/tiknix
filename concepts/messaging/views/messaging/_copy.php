<?php
/** The copy as the signed-in member would get it, and who got it and what they did. Vars: $preview (null|{subject,html,text}|{error}), $recipients, $c */
$h = fn($v) => htmlspecialchars((string) $v);
if ($preview !== null && isset($preview['error'])): ?>
<?= \app\Ui::notice(['tone' => 'warning', 'title' => 'No preview.', 'text' => 'The copy could not be made: ' . $preview['error']]) ?>
<?php elseif ($preview !== null): ?>
<?= \app\Ui::detail(['title' => 'As you would get it', 'items' => ['Subject' => $preview['subject'],
    'Email' => \app\Ui::raw('<iframe sandbox title="Preview" style="width:100%;height:420px;border:1px solid var(--bs-border-color);border-radius:.375rem;background:#fff" srcdoc="' . $h($preview['html']) . '"></iframe>'),
    'Text version' => \app\Ui::raw('<div class="text-break small" style="white-space:pre-wrap">' . $h($preview['text']) . '</div>')]]) ?>
<?php if (in_array($c['kind'], ['digest', 'trigger'], true)): ?>
<?= \app\Ui::notice(['tone' => 'info', 'text' => '{headline}, {content_html} and {content_text} are filled per person with what the app gives them at sending time (the digest\'s news, a nudge\'s text); the preview shows yours as of now. {content_html|} with nothing after the bar means "show nothing when there is none" instead of refusing to send.']) ?>
<?php endif; endif; ?>
<?= \app\Ui::table(['title' => 'Who got it (' . count($recipients) . ')', 'rows' => $recipients, 'count' => 'recipient',
    'columns' => [
        ['label' => 'Member', 'value' => 'name', 'sub' => 'email'],
        ['label' => 'Delivery', 'value' => 'state', 'as' => 'badge', 'tones' => ['delivered' => 'success', 'sent' => 'info', 'bounced' => 'danger', 'not sent' => 'secondary']],
        ['label' => 'What they did', 'value' => 'did'],
        ['label' => 'How', 'value' => 'channel', 'quiet' => true],
        ['label' => 'When', 'value' => 'when', 'as' => 'date', 'quiet' => true],
    ],
    'empty' => ['title' => 'Nobody yet', 'text' => 'Recipients appear here once the message has gone out.']]) ?>
