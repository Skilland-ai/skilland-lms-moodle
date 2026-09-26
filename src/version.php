<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2024100700;   // Moodle 4.5+ (core_external)
$plugin->component = 'mod_skilland';
$plugin->supported = [405, 405];


$plugin->version   = 2026092701;   // YYYYMMDDHH - real course activity listing page in index.php (SKL-667)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.30-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
