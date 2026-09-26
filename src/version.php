<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2022041900;   // Moodle 4.0+ (adjust if needed)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092606;   // YYYYMMDDHH - Plugin CI, gated release and mocked E2E suite (SKL-662, SKL-676)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.12-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
