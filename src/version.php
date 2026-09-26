<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2024100700;   // Moodle 4.5+ (core_external)
$plugin->component = 'mod_skilland';
$plugin->supported = [405, 405];


$plugin->version   = 2026092621;   // YYYYMMDDHH - version/PHP floor, upgrade savepoint order, AMD build, backup/restore (SKL-646/648/652/651)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.24-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
