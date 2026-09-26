<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2022041900;   // Moodle 4.0+ (adjust if needed)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092603;   // YYYYMMDDHH - Keep the activity form's lesson selection per topic and reject lessons outside the topic (SKL-657)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.9-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
