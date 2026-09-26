<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2023042400;   // Moodle 4.2+ (core_external)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092607;   // YYYYMMDDHH - Web services on core_external with validate_context (SKL-666)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.13-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
