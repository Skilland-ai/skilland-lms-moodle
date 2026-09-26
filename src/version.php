<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2022041900;   // Moodle 4.0+ (adjust if needed)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092601;   // YYYYMMDDHH - Atomic, idempotent SCORM provisioning through core module APIs (SKL-663)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.7-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
