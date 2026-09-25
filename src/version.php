<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2022041900;   // Moodle 4.0+ (adjust if needed)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092519;   // YYYYMMDDHH - Remove dev SSO secret from shipped JS, validate SSO settings (SKL-658)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.2-beta';
