<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2022041900;   // Moodle 4.0+ (adjust if needed)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092523;   // YYYYMMDDHH - Enforce view capability, viewed event, view completion, provision/accessstudio caps (SKL-685)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.5-beta';
