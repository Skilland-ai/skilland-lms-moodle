<?php
// Plugin composer autoloader.
// Firebase JWT and other dependencies are loaded here in production.
// In the test environment, PHPUnit's autoloader already provides these classes.
return require_once __DIR__ . '/../../vendor/autoload.php';
