<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2024100700;   // Moodle 4.5+ (core_external)
$plugin->component = 'mod_skilland';
$plugin->supported = [405, 405];


$plugin->version   = 2026092702;   // YYYYMMDDHH - move hardcoded plugin strings to language files (SKL-671)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.31-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
