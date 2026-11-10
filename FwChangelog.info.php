<?php

namespace ProcessWire;

$info = array(
    'title' => 'FwChangelog',
    'summary' => 'View a detailed changelog of a specific page.',
    'author' => 'João Mota',
    'href' => 'https://github.com/joaochoupinaa/FwChangelog',
    'version' => '1.0.0',
    'autoload' => true,
    'singular' => true,
    'icon' => 'history',
    'requires' => 'ProcessWire>=3.0.200',
    'permissions' => array(
        'fw-changelog-view' => 'View a detailed changelog of a specific page.',
    ),
);
