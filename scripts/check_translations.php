#!/usr/bin/env php
<?php
/**
 * Translation checker script for mod_skilland.
 *
 * Compares English and Spanish language files to ensure all string keys
 * are present in both files.
 *
 * Usage: php scripts/check_translations.php
 *
 * Exit codes:
 *   0 - All translations are in sync
 *   1 - Missing translations found
 *
 * @package    mod_skilland
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Define paths relative to script location.
$basedir = dirname(__DIR__);
$englishFile = $basedir . '/lang/en/skilland.php';
$spanishFile = $basedir . '/lang/es/skilland.php';

// Color codes for terminal output.
$red = "\033[31m";
$green = "\033[32m";
$yellow = "\033[33m";
$reset = "\033[0m";

/**
 * Extract string keys from a language file.
 *
 * @param string $filepath Path to the language file.
 * @return array Array of string keys.
 */
function extract_string_keys($filepath) {
    if (!file_exists($filepath)) {
        return [];
    }

    $content = file_get_contents($filepath);

    // Match all $string['key'] patterns.
    preg_match_all('/\$string\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/', $content, $matches);

    return $matches[1] ?? [];
}

// Check if files exist.
if (!file_exists($englishFile)) {
    echo "{$red}Error: English language file not found: {$englishFile}{$reset}\n";
    exit(1);
}

if (!file_exists($spanishFile)) {
    echo "{$red}Error: Spanish language file not found: {$spanishFile}{$reset}\n";
    exit(1);
}

// Extract keys from both files.
$englishKeys = extract_string_keys($englishFile);
$spanishKeys = extract_string_keys($spanishFile);

// Find differences.
$missingInSpanish = array_diff($englishKeys, $spanishKeys);
$missingInEnglish = array_diff($spanishKeys, $englishKeys);

$hasErrors = false;

echo "\n{$yellow}=== Skilland Translation Check ==={$reset}\n\n";
echo "English strings: " . count($englishKeys) . "\n";
echo "Spanish strings: " . count($spanishKeys) . "\n\n";

// Report missing in Spanish.
if (!empty($missingInSpanish)) {
    $hasErrors = true;
    echo "{$red}Missing in Spanish ({$spanishFile}):{$reset}\n";
    foreach ($missingInSpanish as $key) {
        echo "  - {$key}\n";
    }
    echo "\n";
}

// Report missing in English.
if (!empty($missingInEnglish)) {
    $hasErrors = true;
    echo "{$red}Missing in English ({$englishFile}):{$reset}\n";
    foreach ($missingInEnglish as $key) {
        echo "  - {$key}\n";
    }
    echo "\n";
}

// Summary.
if ($hasErrors) {
    $totalMissing = count($missingInSpanish) + count($missingInEnglish);
    echo "{$red}✗ Found {$totalMissing} missing translation(s).{$reset}\n";
    echo "Please add the missing strings to the appropriate language file(s).\n\n";
    exit(1);
} else {
    echo "{$green}✓ All translations are in sync!{$reset}\n\n";
    exit(0);
}


