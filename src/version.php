<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2023042400;   // Moodle 4.2+ (core_external)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092609;   // YYYYMMDDHH - No PII or internal details in logs and client errors (SKL-670)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.15-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
