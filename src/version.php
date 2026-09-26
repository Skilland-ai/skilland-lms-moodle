<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2023042400;   // Moodle 4.2+ (core_external)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092608;   // YYYYMMDDHH - Activity completion and grades from SCORM lesson progress (SKL-668)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.14-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
