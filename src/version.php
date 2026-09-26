<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2022041900;   // Moodle 4.0+ (adjust if needed)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092604;   // YYYYMMDDHH - Reconcile the topic SCORM when the topic or lesson selection changes (SKL-655)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.10-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
