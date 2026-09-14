<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2022041900;   // Moodle 4.0+ (adjust if needed)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026060200;   // YYYYMMDDHH - Fix crash during Moodle install (#28)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.1-beta';
