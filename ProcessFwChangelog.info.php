<?php

namespace ProcessWire;

$info = array(
    'title' => 'FwChangelog Dashboard',
    'summary' => 'Overview of the changes logged by FwChangelog across all pages, with filters by user, date, template and branch.',
    'author' => 'João Mota',
    'href' => 'https://github.com/joaochoupinaa/FwChangelog',
    'version' => '1.2.0',
    'icon' => 'code-fork',
    'requires' => 'FwChangelog',
    'permission' => 'fw-changelog-dashboard',
    'permissions' => array(
        'fw-changelog-dashboard' => 'View the changelog dashboard of all pages',
    ),
    'page' => array(
        'name' => 'changelog',
        'parent' => 'setup',
        'title' => 'Changelog',
    ),
);
