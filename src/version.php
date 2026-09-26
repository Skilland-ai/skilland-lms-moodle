<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2022041900;   // Moodle 4.0+ (adjust if needed)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092605;   // YYYYMMDDHH - Keep stored lesson timestamps until a successful SCORM rebuild (SKL-683)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.11-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
