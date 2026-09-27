<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2024100700;   // Moodle 4.5+ (core_external)
$plugin->component = 'mod_skilland';
$plugin->supported = [405, 405];


$plugin->version   = 2026092706;   // YYYYMMDDHH - shared string_loader guards against the Str.get_strings race (SKL-773)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.35-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
