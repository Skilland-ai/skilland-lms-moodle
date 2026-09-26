<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2022041900;   // Moodle 4.0+ (adjust if needed)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092602;   // YYYYMMDDHH - Delete the linked SCORM with the activity and observe SCORM deletion (SKL-673)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.8-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
