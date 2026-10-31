<?php // the admin's way to the plugin's page (Chrome 'prepare': the menu before it is drawn; the shell's Admin section)
if (!empty($__isAdmin)) $__adminItems[] = ['url' => '/consent', 'label' => 'Cookie consent', 'icon' => 'cookie'];
