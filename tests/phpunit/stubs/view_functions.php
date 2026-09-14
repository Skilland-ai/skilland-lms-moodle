<?php
// Extract only the function definitions from view.php for testing.
// This avoids executing the script-level code (require config.php, etc.).

// view.php uses "use mod_skilland\logger;" so functions reference bare "logger".
// Create a global alias so the extracted functions can resolve the class.
if (!class_exists('logger', false)) {
    class_alias('mod_skilland\logger', 'logger');
}

$viewSource = file_get_contents(__DIR__ . '/../../../src/view.php');

// Extract each function definition. We find "function skilland_render_..." and everything up to
// the matching closing brace. Since PHP's token_get_all is available, we use a robust approach.
$tokens = token_get_all($viewSource);
$functions = [];
$capturing = false;
$braceDepth = 0;
$currentFunc = '';

foreach ($tokens as $token) {
    if (is_array($token) && $token[0] === T_FUNCTION) {
        $capturing = true;
        $braceDepth = 0;
        $currentFunc = 'function ';
        continue;
    }
    if ($capturing) {
        $text = is_array($token) ? $token[1] : $token;
        $currentFunc .= $text;
        if ($text === '{') {
            $braceDepth++;
        } elseif ($text === '}') {
            $braceDepth--;
            if ($braceDepth === 0) {
                $functions[] = $currentFunc;
                $capturing = false;
                $currentFunc = '';
            }
        }
    }
}

// Define each extracted function if it doesn't already exist.
foreach ($functions as $funcCode) {
    // Extract function name.
    if (preg_match('/function\s+(\w+)\s*\(/', $funcCode, $m)) {
        if (!function_exists($m[1])) {
            eval($funcCode);
        }
    }
}
