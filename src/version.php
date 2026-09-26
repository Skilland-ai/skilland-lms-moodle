<?php
defined('MOODLE_INTERNAL') || die();

$plugin->requires  = 2023042400;   // Moodle 4.2+ (core_external)
$plugin->component = 'mod_skilland';


$plugin->version   = 2026092614;   // YYYYMMDDHH - GraphQL retries for read queries, non-2xx error decoding, package size check (SKL-672)
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '0.9.20-beta';
$plugin->dependencies = ['mod_scorm' => ANY_VERSION];
