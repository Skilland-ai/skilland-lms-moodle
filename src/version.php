<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2023042400;   // Moodle 4.2+ (core_external)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092610;   // YYYYMMDDHH - fetch_topics error payload passes response validation (SKL-665)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.16-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
