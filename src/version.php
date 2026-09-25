<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2022041900;   // Moodle 4.0+ (adjust if needed)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092524;   // YYYYMMDDHH - Harden outbound HTTP: no redirects, curl security, package host allowlist, size/zip checks (SKL-656)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.6-beta';
