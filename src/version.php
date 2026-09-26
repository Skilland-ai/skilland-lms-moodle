<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2023042400;   // Moodle 4.2+ (core_external)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092613;   // YYYYMMDDHH - Declarative settings page, custom field on upgrade, hardened configure_api CLI (SKL-700)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.19-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
