<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2022041900;   // Moodle 4.0+ (adjust if needed)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092522;   // YYYYMMDDHH - Scope topic/lesson endpoints to the course's mapped skill (SKL-661)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.4-beta';
