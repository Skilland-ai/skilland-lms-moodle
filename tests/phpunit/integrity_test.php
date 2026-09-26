<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * Integrity tests that catch common Moodle plugin maintenance mistakes:
 * - Language strings referenced in code but not defined in lang files
 * - Language parity between EN and ES translations
 * - DB column references that don't match install.xml schema
 * - version.php sanity checks
 */
class integrity_test extends TestCase {

    private static string $srcDir;
    private static array $enStrings = [];
    private static array $esStrings = [];
    private static array $dbColumns = [];  // table => [column, ...]

    public static function setUpBeforeClass(): void {
        self::$srcDir = realpath(__DIR__ . '/../../src');

        // Parse EN lang file.
        $string = [];
        require self::$srcDir . '/lang/en/skilland.php';
        self::$enStrings = $string;

        // Parse ES lang file.
        $string = [];
        require self::$srcDir . '/lang/es/skilland.php';
        self::$esStrings = $string;

        // Parse install.xml for DB columns.
        $xml = simplexml_load_file(self::$srcDir . '/db/install.xml');
        foreach ($xml->TABLES->TABLE as $table) {
            $tableName = (string)$table['NAME'];
            $columns = [];
            foreach ($table->FIELDS->FIELD as $field) {
                $columns[] = (string)$field['NAME'];
            }
            self::$dbColumns[$tableName] = $columns;
        }
    }

    // ---------------------------------------------------------------
    // Language string completeness
    // ---------------------------------------------------------------

    /**
     * Find all get_string('key', 'mod_skilland') calls in PHP source files.
     */
    private function extractStringReferences(): array {
        $keys = [];
        $phpFiles = glob(self::$srcDir . '/*.php');
        $phpFiles = array_merge($phpFiles, glob(self::$srcDir . '/classes/*.php'));
        $phpFiles = array_merge($phpFiles, glob(self::$srcDir . '/classes/**/*.php'));
        $phpFiles = array_merge($phpFiles, glob(self::$srcDir . '/db/*.php'));

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            // Match get_string('key', 'mod_skilland') patterns.
            // Also match get_string('key', 'skilland') since Moodle normalises 'mod_skilland' to the lang file name.
            if (preg_match_all("/get_string\(\s*['\"]([a-zA-Z0-9_]+)['\"]\s*,\s*['\"](?:mod_)?skilland['\"]/", $content, $matches)) {
                foreach ($matches[1] as $key) {
                    $keys[$key] = basename($file);
                }
            }
        }

        return $keys;
    }

    public function test_all_referenced_strings_exist_in_en(): void {
        $referenced = $this->extractStringReferences();
        $missing = [];

        foreach ($referenced as $key => $file) {
            if (!isset(self::$enStrings[$key])) {
                $missing[] = "'$key' (referenced in $file)";
            }
        }

        $this->assertEmpty(
            $missing,
            "Missing EN lang strings:\n  " . implode("\n  ", $missing)
        );
    }

    public function test_all_referenced_strings_exist_in_es(): void {
        $referenced = $this->extractStringReferences();
        $missing = [];

        foreach ($referenced as $key => $file) {
            if (!isset(self::$esStrings[$key])) {
                $missing[] = "'$key' (referenced in $file)";
            }
        }

        $this->assertEmpty(
            $missing,
            "Missing ES lang strings:\n  " . implode("\n  ", $missing)
        );
    }

    // ---------------------------------------------------------------
    // Language parity (EN ↔ ES)
    // ---------------------------------------------------------------

    public function test_es_has_all_en_keys(): void {
        $missing = array_diff_key(self::$enStrings, self::$esStrings);

        $this->assertEmpty(
            $missing,
            "ES lang file is missing " . count($missing) . " keys present in EN:\n  " .
            implode("\n  ", array_keys($missing))
        );
    }

    public function test_en_has_all_es_keys(): void {
        $extra = array_diff_key(self::$esStrings, self::$enStrings);

        $this->assertEmpty(
            $extra,
            "ES lang file has " . count($extra) . " keys not present in EN (orphaned):\n  " .
            implode("\n  ", array_keys($extra))
        );
    }

    public function test_no_empty_lang_strings_en(): void {
        $empty = [];
        foreach (self::$enStrings as $key => $value) {
            if (trim($value) === '') {
                $empty[] = $key;
            }
        }

        $this->assertEmpty(
            $empty,
            "EN lang file has empty string values:\n  " . implode("\n  ", $empty)
        );
    }

    public function test_no_empty_lang_strings_es(): void {
        $empty = [];
        foreach (self::$esStrings as $key => $value) {
            if (trim($value) === '') {
                $empty[] = $key;
            }
        }

        $this->assertEmpty(
            $empty,
            "ES lang file has empty string values:\n  " . implode("\n  ", $empty)
        );
    }

    // ---------------------------------------------------------------
    // DB column references vs install.xml schema
    // ---------------------------------------------------------------

    /**
     * Extract DB column references from code patterns like:
     * - $DB->set_field('table', 'column', ...)
     * - $DB->get_field('table', 'column', ...)
     * - $DB->get_record('table', ['column' => ...])
     * - Object properties assigned before insert_record/update_record
     */
    private function extractDbFieldReferences(): array {
        $refs = []; // ['table.column' => file]
        $phpFiles = glob(self::$srcDir . '/*.php');
        $phpFiles = array_merge($phpFiles, glob(self::$srcDir . '/classes/*.php'));
        $phpFiles = array_merge($phpFiles, glob(self::$srcDir . '/classes/**/*.php'));

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            $basename = basename($file);

            // Match set_field('table', 'column', ...) and get_field('table', 'column', ...)
            if (preg_match_all("/(?:set_field|get_field)\(\s*['\"](\w+)['\"]\s*,\s*['\"](\w+)['\"]/", $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $refs[$match[1] . '.' . $match[2]] = $basename;
                }
            }

            // Match get_record('table', ['column' => ...])
            if (preg_match_all("/get_record\(\s*['\"](\w+)['\"]\s*,\s*\[([^\]]+)\]/", $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (preg_match_all("/['\"](\w+)['\"]\s*=>/", $match[2], $colMatches)) {
                        foreach ($colMatches[1] as $col) {
                            $refs[$match[1] . '.' . $col] = $basename;
                        }
                    }
                }
            }

            // Match record_exists('table', ['column' => ...])
            if (preg_match_all("/record_exists\(\s*['\"](\w+)['\"]\s*,\s*\[([^\]]+)\]/", $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (preg_match_all("/['\"](\w+)['\"]\s*=>/", $match[2], $colMatches)) {
                        foreach ($colMatches[1] as $col) {
                            $refs[$match[1] . '.' . $col] = $basename;
                        }
                    }
                }
            }

            // Match delete_records('table', ['column' => ...])
            if (preg_match_all("/delete_records\(\s*['\"](\w+)['\"]\s*,\s*(?:array\(|\[)([^\]\)]+)/", $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (preg_match_all("/['\"](\w+)['\"]\s*=>/", $match[2], $colMatches)) {
                        foreach ($colMatches[1] as $col) {
                            $refs[$match[1] . '.' . $col] = $basename;
                        }
                    }
                }
            }
        }

        return $refs;
    }

    public function test_db_field_references_match_schema(): void {
        $refs = $this->extractDbFieldReferences();
        $mismatches = [];

        foreach ($refs as $tableCol => $file) {
            [$table, $column] = explode('.', $tableCol, 2);

            // Only check tables we own (defined in our install.xml).
            if (!isset(self::$dbColumns[$table])) {
                continue;
            }

            if (!in_array($column, self::$dbColumns[$table])) {
                $mismatches[] = "'$table.$column' (referenced in $file)";
            }
        }

        $this->assertEmpty(
            $mismatches,
            "DB column references not found in install.xml:\n  " . implode("\n  ", $mismatches)
        );
    }

    public function test_install_xml_tables_exist(): void {
        $this->assertArrayHasKey('skilland', self::$dbColumns);
        $this->assertArrayHasKey('skilland_course', self::$dbColumns);
        $this->assertArrayHasKey('skilland_lesson', self::$dbColumns);
    }

    public function test_skilland_table_has_required_columns(): void {
        $required = ['id', 'course', 'name', 'skilland_topicid', 'timecreated', 'timemodified',
                     'snapshotid', 'lastsynced', 'autoupdate', 'scormcmid', 'topic_orderindex'];

        foreach ($required as $col) {
            $this->assertContains($col, self::$dbColumns['skilland'],
                "Column '$col' missing from skilland table in install.xml");
        }
    }

    public function test_skilland_lesson_table_has_required_columns(): void {
        $required = ['id', 'skillandid', 'skilland_lessonid', 'title', 'orderindex',
                     'visible', 'scoid', 'updatedat'];

        foreach ($required as $col) {
            $this->assertContains($col, self::$dbColumns['skilland_lesson'],
                "Column '$col' missing from skilland_lesson table in install.xml");
        }
        // SKL-661 dropped the legacy per-lesson SCORM column.
        $this->assertNotContains('scormcmid', self::$dbColumns['skilland_lesson'],
            "Legacy column 'scormcmid' must not come back on skilland_lesson");
    }

    // ---------------------------------------------------------------
    // version.php sanity
    // ---------------------------------------------------------------

    public function test_version_file_defines_required_fields(): void {
        // Moodle maturity constants used in version.php.
        if (!defined('MOODLE_INTERNAL')) {
            define('MOODLE_INTERNAL', true);
        }
        if (!defined('MATURITY_ALPHA')) {
            define('MATURITY_ALPHA', 50);
        }
        if (!defined('MATURITY_BETA')) {
            define('MATURITY_BETA', 100);
        }
        if (!defined('MATURITY_RC')) {
            define('MATURITY_RC', 150);
        }
        if (!defined('MATURITY_STABLE')) {
            define('MATURITY_STABLE', 200);
        }

        $plugin = new \stdClass();
        require self::$srcDir . '/version.php';

        $this->assertObjectHasProperty('component', $plugin, 'version.php must define $plugin->component');
        $this->assertEquals('mod_skilland', $plugin->component);
        $this->assertObjectHasProperty('version', $plugin, 'version.php must define $plugin->version');
        $this->assertIsInt($plugin->version);
        $this->assertObjectHasProperty('requires', $plugin, 'version.php must define $plugin->requires');
    }

    public function test_version_file_declares_mod_scorm_dependency(): void {
        if (!defined('MATURITY_BETA')) {
            define('MATURITY_BETA', 100);
        }
        $plugin = new \stdClass();
        require self::$srcDir . '/version.php';

        $this->assertObjectHasProperty('dependencies', $plugin, 'version.php must declare $plugin->dependencies');
        $this->assertArrayHasKey('mod_scorm', $plugin->dependencies,
            'Provisioning creates mod_scorm activities, so mod_scorm must be a declared dependency');
        $this->assertGreaterThanOrEqual(2026092601, $plugin->version);
    }

    public function test_provisioning_error_strings_exist_in_en_and_es(): void {
        foreach (['en', 'es'] as $lang) {
            $string = [];
            require self::$srcDir . "/lang/$lang/skilland.php";
            foreach (['error_provision_in_progress', 'error_scorm_parse_failed'] as $key) {
                $this->assertArrayHasKey($key, $string, "$lang missing $key");
                $this->assertNotSame('', trim($string[$key]), "$lang $key is empty");
            }
            $this->assertStringContainsString('{$a}', $string['error_scorm_parse_failed'],
                "$lang error_scorm_parse_failed must show the missing SCO identifiers");
        }
    }

    // ---------------------------------------------------------------
    // Placeholder parameters in lang strings
    // ---------------------------------------------------------------

    // ---------------------------------------------------------------
    // Services ↔ External API consistency
    // ---------------------------------------------------------------

    /**
     * Every function declared in db/services.php must name a class under
     * classes/external/ that defines execute(), execute_parameters() and execute_returns().
     */
    public function test_services_methods_exist_in_external_class(): void {
        $content = file_get_contents(self::$srcDir . '/db/services.php');

        preg_match_all("/'classname'\\s*=>\\s*'([^']+)'/", $content, $classes);
        preg_match_all("/'methodname'\\s*=>\\s*'(\\w+)'/", $content, $methods);

        $this->assertNotEmpty($classes[1], 'No classnames found in services.php');
        $this->assertCount(count($classes[1]), $methods[1], 'Every service needs a methodname');

        $missing = [];
        foreach ($classes[1] as $i => $classname) {
            $classname = str_replace('\\\\', '\\', $classname);
            $prefix = 'mod_skilland\\external\\';
            if (strpos($classname, $prefix) !== 0) {
                $missing[] = "$classname is not in the mod_skilland\\external namespace";
                continue;
            }
            $file = self::$srcDir . '/classes/external/' . substr($classname, strlen($prefix)) . '.php';
            if (!is_file($file)) {
                $missing[] = "$classname has no class file";
                continue;
            }
            $source = file_get_contents($file);
            foreach (['execute', 'execute_parameters', 'execute_returns'] as $method) {
                if (!preg_match('/public\s+static\s+function\s+' . $method . '\s*\(/', $source)) {
                    $missing[] = "$classname::$method()";
                }
            }
            if ($methods[1][$i] !== 'execute') {
                $missing[] = "$classname methodname is {$methods[1][$i]}, expected execute";
            }
        }

        $this->assertEmpty(
            $missing,
            "External API classes missing for services.php declarations:\n  " . implode("\n  ", $missing)
        );
    }

    public function test_version_requires_moodle_42_for_core_external(): void {
        if (!defined('MATURITY_BETA')) {
            define('MATURITY_BETA', 100);
        }
        $plugin = new \stdClass();
        require self::$srcDir . '/version.php';

        $this->assertGreaterThanOrEqual(2023042400, $plugin->requires,
            'The web services extend core_external\\external_api, which needs Moodle 4.2+');
    }

    // ---------------------------------------------------------------
    // Scheduled task wiring
    // ---------------------------------------------------------------

    public function test_scheduled_task_classes_exist(): void {
        $content = file_get_contents(self::$srcDir . '/db/tasks.php');

        preg_match_all("/'classname'\\s*=>\\s*'([^']+)'/", $content, $matches);
        $classnames = $matches[1];

        $this->assertNotEmpty($classnames, 'No tasks found in tasks.php');

        $missing = [];
        foreach ($classnames as $classname) {
            // Convert namespace to file path.
            $relative = str_replace('\\', '/', $classname);
            // Remove leading mod_skilland/ prefix → classes/...
            $relative = preg_replace('#^mod_skilland/#', 'classes/', $relative);
            $filepath = self::$srcDir . '/' . $relative . '.php';

            if (!file_exists($filepath)) {
                $missing[] = "$classname (expected at $filepath)";
            }
        }

        $this->assertEmpty(
            $missing,
            "Scheduled task classes not found:\n  " . implode("\n  ", $missing)
        );
    }

    // ---------------------------------------------------------------
    // Upgrade savepoint ordering
    // ---------------------------------------------------------------

    public function test_upgrade_savepoints_match_conditions_and_within_version(): void {
        $upgradeSource = file_get_contents(self::$srcDir . '/db/upgrade.php');

        // Extract pairs: if ($oldversion < X) ... upgrade_mod_savepoint(true, Y, ...)
        // Each condition threshold must match its savepoint.
        preg_match_all('/\$oldversion\s*<\s*(\d+)/', $upgradeSource, $conditions);
        preg_match_all('/upgrade_mod_savepoint\s*\(\s*true\s*,\s*(\d+)/', $upgradeSource, $savepoints);

        $conditionVersions = array_map('intval', $conditions[1]);
        $savepointVersions = array_map('intval', $savepoints[1]);

        $this->assertNotEmpty($savepointVersions, 'No savepoints found in upgrade.php');
        $this->assertCount(
            count($conditionVersions),
            $savepointVersions,
            'Number of if($oldversion < X) conditions must match number of savepoints'
        );

        // Each condition must match its savepoint.
        for ($i = 0; $i < count($conditionVersions); $i++) {
            $this->assertEquals(
                $conditionVersions[$i],
                $savepointVersions[$i],
                "Condition threshold {$conditionVersions[$i]} doesn't match savepoint {$savepointVersions[$i]} (block " . ($i + 1) . ")"
            );
        }

        // Check highest savepoint ≤ version.php version.
        if (!defined('MATURITY_BETA')) {
            define('MATURITY_BETA', 100);
        }
        $plugin = new \stdClass();
        require self::$srcDir . '/version.php';

        $maxSavepoint = max($savepointVersions);
        $this->assertLessThanOrEqual(
            $plugin->version,
            $maxSavepoint,
            "Highest upgrade savepoint ($maxSavepoint) exceeds version.php ({$plugin->version})"
        );
    }

    // ---------------------------------------------------------------
    // Logger method calls reference real methods
    // ---------------------------------------------------------------

    public function test_logger_calls_reference_existing_methods(): void {
        $loggerSource = file_get_contents(self::$srcDir . '/classes/logger.php');

        // Extract public static method names from logger class.
        preg_match_all('/public\s+static\s+function\s+(\w+)\s*\(/', $loggerSource, $matches);
        $validMethods = $matches[1];

        // Scan all PHP files for logger:: calls.
        $phpFiles = glob(self::$srcDir . '/*.php');
        $phpFiles = array_merge($phpFiles, glob(self::$srcDir . '/classes/*.php'));
        $phpFiles = array_merge($phpFiles, glob(self::$srcDir . '/classes/**/*.php'));
        $phpFiles = array_merge($phpFiles, glob(self::$srcDir . '/db/*.php'));

        $invalid = [];
        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            if (preg_match_all('/logger::(\w+)\s*\(/', $content, $calls)) {
                foreach ($calls[1] as $method) {
                    if (!in_array($method, $validMethods)) {
                        $invalid[] = "logger::$method() in " . basename($file);
                    }
                }
            }
        }

        $this->assertEmpty(
            $invalid,
            "Logger calls referencing non-existent methods:\n  " . implode("\n  ", array_unique($invalid))
        );
    }

    // ---------------------------------------------------------------
    // Capabilities lang strings
    // ---------------------------------------------------------------

    public function test_capabilities_have_lang_strings(): void {
        $content = file_get_contents(self::$srcDir . '/db/access.php');

        // Extract capability names like 'mod/skilland:addinstance'.
        preg_match_all("/'(mod\\/skilland:(\\w+))'/", $content, $matches);
        $capabilities = $matches[2];

        $this->assertNotEmpty($capabilities, 'No capabilities found in access.php');

        // Moodle convention: capability lang string key is "skilland:capname".
        // Check these exist in the EN lang file.
        $missing = [];
        foreach ($capabilities as $cap) {
            $langKey = 'skilland:' . $cap;
            if (!isset(self::$enStrings[$langKey])) {
                $missing[] = "'$langKey' for capability mod/skilland:$cap";
            }
        }

        $this->assertEmpty(
            $missing,
            "Missing EN lang strings for capabilities:\n  " . implode("\n  ", $missing)
        );
    }

    // ---------------------------------------------------------------
    // Callbacks reference real class methods
    // ---------------------------------------------------------------

    public function test_callbacks_reference_existing_methods(): void {
        $content = file_get_contents(self::$srcDir . '/db/callbacks.php');

        // Extract callback class and method pairs.
        preg_match_all("/'callback'\\s*=>\\s*\\[([^\\]]+)\\]/", $content, $matches);

        $missing = [];
        foreach ($matches[1] as $callbackDef) {
            // Extract class and method from something like \mod_skilland\hooks::class, 'before_footer_html_generation'
            if (preg_match("/\\\\(mod_skilland\\\\[\\w\\\\]+)::class\\s*,\\s*'(\\w+)'/", $callbackDef, $m)) {
                $className = $m[1];
                $methodName = $m[2];

                // Convert namespace to file path.
                $relative = str_replace('\\', '/', $className);
                $relative = preg_replace('#^mod_skilland/#', 'classes/', $relative);
                $filepath = self::$srcDir . '/' . $relative . '.php';

                if (!file_exists($filepath)) {
                    $missing[] = "$className (file not found at $filepath)";
                    continue;
                }

                // Check method exists in file.
                $classSource = file_get_contents($filepath);
                if (!preg_match('/function\s+' . preg_quote($methodName) . '\s*\(/', $classSource)) {
                    $missing[] = "$className::$methodName() (method not found)";
                }
            }
        }

        $this->assertEmpty(
            $missing,
            "Callback references to non-existent classes/methods:\n  " . implode("\n  ", $missing)
        );
    }

    // ---------------------------------------------------------------
    // Placeholder parameters in lang strings
    // ---------------------------------------------------------------

    public function test_lang_strings_with_placeholder_have_matching_translations(): void {
        $mismatches = [];

        foreach (self::$enStrings as $key => $enValue) {
            $enHasPlaceholder = strpos($enValue, '{$a}') !== false;
            if (!isset(self::$esStrings[$key])) {
                continue; // Parity test covers this.
            }
            $esHasPlaceholder = strpos(self::$esStrings[$key], '{$a}') !== false;

            if ($enHasPlaceholder !== $esHasPlaceholder) {
                $mismatches[] = "'$key': EN " . ($enHasPlaceholder ? 'has' : 'missing') .
                    ' {$a}, ES ' . ($esHasPlaceholder ? 'has' : 'missing') . ' {$a}';
            }
        }

        $this->assertEmpty(
            $mismatches,
            "Placeholder {" . '$a} mismatch between EN and ES:' . "\n  " . implode("\n  ", $mismatches)
        );
    }

    // ---------------------------------------------------------------
    // Event observers (SKL-673)
    // ---------------------------------------------------------------

    public function test_events_file_declares_course_module_deleted_observer(): void {
        $file = self::$srcDir . '/db/events.php';
        $this->assertFileExists($file);

        $observers = null;
        require $file;
        $this->assertIsArray($observers);

        $found = array_values(array_filter($observers,
            fn($o) => ($o['eventname'] ?? '') === '\\core\\event\\course_module_deleted'));
        $this->assertCount(1, $found);
        $this->assertFalse($found[0]['internal']);

        [$class, $method] = explode('::', $found[0]['callback']);
        $class = ltrim($class, '\\');
        $this->assertTrue(class_exists($class), "Observer class $class not found");
        $this->assertTrue(method_exists($class, $method), "Observer method $class::$method not found");
    }

    public function test_version_is_bumped_for_event_observers(): void {
        if (!defined('MATURITY_BETA')) {
            define('MATURITY_BETA', 100);
        }
        $plugin = new \stdClass();
        require self::$srcDir . '/version.php';
        $this->assertGreaterThanOrEqual(2026092602, $plugin->version);
    }

    // ---------------------------------------------------------------
    // skilland.scomappings (SKL-655)
    // ---------------------------------------------------------------

    public function test_scomappings_column_is_installed_and_upgraded(): void {
        $this->assertContains('scomappings', self::$dbColumns['skilland']);

        $xml = simplexml_load_file(self::$srcDir . '/db/install.xml');
        $field = $xml->xpath('//TABLE[@NAME="skilland"]/FIELDS/FIELD[@NAME="scomappings"]');
        $this->assertCount(1, $field);
        $this->assertSame('text', (string) $field[0]['TYPE']);
        $this->assertSame('false', (string) $field[0]['NOTNULL']);

        $upgrade = file_get_contents(self::$srcDir . '/db/upgrade.php');
        $this->assertMatchesRegularExpression(
            "/if \\(\\\$oldversion < 2026092604\\) \\{.*new xmldb_field\\('scomappings', XMLDB_TYPE_TEXT.*field_exists.*add_field.*upgrade_mod_savepoint\\(true, 2026092604, 'skilland'\\);/s",
            $upgrade
        );
    }

    public function test_version_is_bumped_for_scomappings(): void {
        if (!defined('MATURITY_BETA')) {
            define('MATURITY_BETA', 100);
        }
        $plugin = new \stdClass();
        require self::$srcDir . '/version.php';
        $this->assertGreaterThanOrEqual(2026092604, $plugin->version);
    }

    public function test_skl655_strings_exist_in_en_and_es(): void {
        foreach (['en', 'es'] as $lang) {
            $string = [];
            include self::$srcDir . "/lang/$lang/skilland.php";
            foreach (['topic_change_confirm', 'topic_change_confirm_title', 'topic_changed_reprovision_failed',
                    'lesson_sco_missing'] as $key) {
                $this->assertNotEmpty($string[$key] ?? '', "$lang string $key");
            }
        }
    }

    // ---------------------------------------------------------------
    // Completion and grades (SKL-668)
    // ---------------------------------------------------------------

    public function test_completion_and_grade_columns_are_installed_and_upgraded(): void {
        $xml = simplexml_load_file(self::$srcDir . '/db/install.xml');
        foreach (['completionlessons' => '1', 'grade' => '10'] as $name => $length) {
            $field = $xml->xpath('//TABLE[@NAME="skilland"]/FIELDS/FIELD[@NAME="' . $name . '"]');
            $this->assertCount(1, $field, $name);
            $this->assertSame('int', (string) $field[0]['TYPE']);
            $this->assertSame($length, (string) $field[0]['LENGTH']);
            $this->assertSame('true', (string) $field[0]['NOTNULL']);
            $this->assertSame('0', (string) $field[0]['DEFAULT']);
        }

        $upgrade = file_get_contents(self::$srcDir . '/db/upgrade.php');
        $this->assertMatchesRegularExpression(
            "/if \\(\\\$oldversion < 2026092608\\) \\{.*new xmldb_field\\('completionlessons'.*new xmldb_field\\('grade'.*" .
            "new xmldb_table\\('skilland_progress'\\).*table_exists.*create_table.*scorm_grade_item_update.*" .
            "upgrade_mod_savepoint\\(true, 2026092608, 'skilland'\\);/s",
            $upgrade
        );
    }

    public function test_progress_table_is_installed_with_its_keys_and_indexes(): void {
        $this->assertArrayHasKey('skilland_progress', self::$dbColumns);
        foreach (['id', 'skillandid', 'lessonid', 'userid', 'status', 'score', 'timemodified'] as $col) {
            $this->assertContains($col, self::$dbColumns['skilland_progress'], $col);
        }

        $xml = simplexml_load_file(self::$srcDir . '/db/install.xml');
        $table = $xml->xpath('//TABLE[@NAME="skilland_progress"]')[0];
        $score = $table->xpath('FIELDS/FIELD[@NAME="score"]')[0];
        $this->assertSame('number', (string) $score['TYPE']);
        $this->assertSame('false', (string) $score['NOTNULL']);
        $lessonfk = $table->xpath('KEYS/KEY[@NAME="lessonid_fk"]')[0];
        $this->assertSame('skilland_lesson', (string) $lessonfk['REFTABLE']);
        $unique = $table->xpath('INDEXES/INDEX[@UNIQUE="true"]');
        $this->assertCount(1, $unique);
        $this->assertSame('lessonid, userid', (string) $unique[0]['FIELDS']);
        $this->assertCount(1, $table->xpath('INDEXES/INDEX[@FIELDS="skillandid, userid"]'));
    }

    public function test_events_file_declares_scorm_tracking_observers(): void {
        $observers = null;
        require self::$srcDir . '/db/events.php';

        foreach (['\\mod_scorm\\event\\status_submitted', '\\mod_scorm\\event\\scoreraw_submitted'] as $name) {
            $found = array_values(array_filter($observers, fn($o) => ($o['eventname'] ?? '') === $name));
            $this->assertCount(1, $found, $name);
            [$class, $method] = explode('::', $found[0]['callback']);
            $class = ltrim($class, '\\');
            $this->assertTrue(class_exists($class), "Observer class $class not found");
            $this->assertTrue(method_exists($class, $method), "Observer method $class::$method not found");
        }
    }

    public function test_custom_completion_class_exists(): void {
        $this->assertFileExists(self::$srcDir . '/classes/completion/custom_completion.php');
        $this->assertTrue(class_exists(\mod_skilland\completion\custom_completion::class));
    }

    public function test_backup_carries_completion_and_grade(): void {
        $backup = file_get_contents(self::$srcDir . '/backup/moodle2/backup_skilland_stepslib.php');
        $this->assertStringContainsString("'completionlessons'", $backup);
        $this->assertStringContainsString("'grade'", $backup);
    }

    public function test_version_is_bumped_for_completion_and_grades(): void {
        if (!defined('MATURITY_BETA')) {
            define('MATURITY_BETA', 100);
        }
        $plugin = new \stdClass();
        require self::$srcDir . '/version.php';
        $this->assertGreaterThanOrEqual(2026092608, $plugin->version);
    }

    public function test_skl668_strings_exist_in_en_and_es(): void {
        foreach (['en', 'es'] as $lang) {
            $string = [];
            include self::$srcDir . "/lang/$lang/skilland.php";
            foreach (['completionlessons', 'completionlessons_help', 'completionlessons_desc',
                    'completiondetail:lessons', 'error_grade_scale_unsupported'] as $key) {
                $this->assertNotEmpty($string[$key] ?? '', "$lang string $key");
            }
        }
    }
}
