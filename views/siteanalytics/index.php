<?php
echo \app\Ui::page(['title' => 'Google Analytics', 'lead' => 'For tiknix.com itself. Each project connects its own under Connections.',
    'more' => array_merge([['label' => 'Open Google Analytics', 'url' => 'https://analytics.google.com/', 'icon' => 'box-arrow-up-right']],
        $connected ? [['label' => 'Turn it off', 'post' => '/siteanalytics/off', 'confirm' => 'Stop counting visits to tiknix.com?']] : [])]);
if (!$plugin) echo \app\Ui::notice(['tone' => 'danger', 'title' => 'The Google Analytics plugin is off.', 'text' => 'Nothing is counted without it.', 'action' => ['label' => 'Plugins', 'url' => '/admin/concepts?show=googleanalytics']]);
elseif ($connected && !$asking) echo \app\Ui::notice(['tone' => 'warning', 'title' => 'Not running yet.', 'text' => 'It waits for a yes, and until this site asks, nobody can say yes.', 'action' => ['label' => 'Cookie consent', 'url' => '/consent']]);
elseif ($connected) echo \app\Ui::notice(['tone' => 'success', 'title' => 'Running.', 'text' => 'Visitors who said yes to Statistics are counted. Admins are not.']);
echo \app\Ui::form(['action' => '/siteanalytics', 'submit' => 'Save', 'values' => ['measurement_id' => $id], 'problems' => $problems,
    'fields' => [['name' => 'measurement_id', 'label' => 'Measurement ID', 'required' => true, 'placeholder' => 'G-AB12CD34EF', 'help' => 'In Google Analytics: Admin, Data streams, your site. It starts with G-.']]]);
