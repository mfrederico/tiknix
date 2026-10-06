<?php
/** Write, review or change a knowledge-base entry (controls/Knowledge.php). Described with \app\Ui. */
use app\Ui;

echo Ui::page(['title' => $entry ? ($entry->status === 'candidate' ? 'Review a candidate' : 'Edit entry') : 'Write an entry',
    'lead' => 'Write it for an agent that is stuck right now: what it sees, why, and exactly what to do.',
    'back' => ['label' => 'Knowledge base', 'url' => '/knowledge']]);

if ($from !== '') echo Ui::notice(['title' => "Started from {$from}.", 'text' => 'The question and the last answer are filled in — cut them down to what any project would need, and take out anything about that one member or project.']);
if ($entry && $entry->source === 'notebook') echo Ui::notice(['title' => "A lesson from a project's notebook: " . (string) $entry->sourceNote . '.', 'text' => 'An agent wrote it as one sentence. If it is true of the platform, split it into what is seen and what to do, then publish; if it is only about that app, dismiss it.']);

echo Ui::form([
    'action' => '/knowledge/edit' . ($entry ? '?id=' . (int) $entry->id : ''),
    'hidden' => $from !== '' ? ['source_note' => $from] : [],
    'values' => $form, 'problems' => $problems,
    'submit' => 'Save entry', 'cancel' => '/knowledge',
    'fields' => [
        ['name' => 'title', 'label' => 'What is seen, in one line', 'required' => true, 'max' => 160,
         'help' => 'The words an agent would search with — include the error text or code ("git push fails with 403").'],
        ['name' => 'symptom', 'label' => 'What is seen, in full', 'type' => 'textarea', 'rows' => 3,
         'help' => 'Where it happens, the exact message, what the agent was doing.'],
        ['name' => 'cause', 'label' => 'Why it happens', 'type' => 'textarea', 'rows' => 2],
        ['name' => 'fix', 'label' => 'What to do', 'type' => 'textarea', 'rows' => 4, 'required' => true,
         'help' => 'Commands and paths exactly. Say what NOT to do if agents tend to try it.'],
        ['name' => 'status', 'label' => 'Who gets it', 'type' => 'radio', 'required' => true,
         'options' => ['published' => 'Published — agents that ask about this get it', 'candidate' => 'Not yet — keep it with the candidates', 'retired' => 'Retired — it is no longer true'],
         'help' => 'Saving a published entry marks it as confirmed today.'],
    ],
]);
