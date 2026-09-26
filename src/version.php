<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2023042400;   // Moodle 4.2+ (core_external)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092612;   // YYYYMMDDHH - Robustness batch: API errors, hidden lessons, course mapping, intro editor (SKL-749)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.18-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
