<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2024100700;   // Moodle 4.5+ (core_external)
$plugin->component = 'mod_skilland';
$plugin->supported = [405, 405];


$plugin->version   = 2026092705;   // YYYYMMDDHH - SSO token carries sub, refuses inactive users (SKL-647)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.34-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
