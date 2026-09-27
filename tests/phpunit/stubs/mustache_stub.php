<?php
// Minimal Mustache engine for the standalone harness, so the plugin renderer renders the real
// src/templates/*.mustache files. It covers the subset the plugin's templates use: escaped
// {{var}} (through s(), Moodle's escape callback), raw {{{var}}} / {{&var}}, dotted names, {{.}},
// sections over lists / objects / truthy values, inverted sections, comments, partials
// ({{> mod_skilland/name}}) and Moodle's {{#str}} / {{#cleanstr}} helpers. Standalone-line
// whitespace is not stripped, so compare rendered HTML by content, never byte for byte.

if (!class_exists('test_mustache')) {
    class test_mustache {
        /** @var callable Returns the source of a template given its name (component/name). */
        private $loader;

        public function __construct(callable $loader) {
            $this->loader = $loader;
        }

        public function render_named(string $name, $context): string {
            return $this->render(($this->loader)($name), $context);
        }

        public function render(string $template, $context): string {
            return $this->render_nodes($this->parse($template), [$context]);
        }

        private function parse(string $template): array {
            preg_match_all('/\{\{\{\s*(.+?)\s*\}\}\}|\{\{([!#^\/>&]?)\s*(.*?)\s*\}\}/s', $template, $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            $root = ['children' => []];
            $stack = [&$root];
            $pos = 0;
            foreach ($matches as $m) {
                $tagstart = $m[0][1];
                $tagend = $tagstart + strlen($m[0][0]);
                $current = &$stack[count($stack) - 1];
                if ($tagstart > $pos) {
                    $current['children'][] = ['type' => 'text', 'value' => substr($template, $pos, $tagstart - $pos)];
                }
                $pos = $tagend;
                if (isset($m[1]) && $m[1][1] !== -1 && $m[1][0] !== '') {
                    $current['children'][] = ['type' => 'var', 'name' => trim($m[1][0]), 'escape' => false];
                    unset($current);
                    continue;
                }
                $sigil = $m[2][0];
                $name = trim($m[3][0]);
                switch ($sigil) {
                    case '!':
                        break;
                    case '#':
                    case '^':
                        $current['children'][] = ['type' => 'section', 'name' => $name, 'inverted' => $sigil === '^',
                            'children' => [], 'start' => $tagend];
                        $stack[] = &$current['children'][count($current['children']) - 1];
                        break;
                    case '/':
                        if (count($stack) < 2 || $current['name'] !== $name) {
                            throw new \RuntimeException("Unexpected closing tag {{/$name}}");
                        }
                        $current['raw'] = substr($template, $current['start'], $tagstart - $current['start']);
                        array_pop($stack);
                        break;
                    case '>':
                        $current['children'][] = ['type' => 'partial', 'name' => $name];
                        break;
                    case '&':
                        $current['children'][] = ['type' => 'var', 'name' => $name, 'escape' => false];
                        break;
                    default:
                        $current['children'][] = ['type' => 'var', 'name' => $name, 'escape' => true];
                }
                unset($current);
            }
            if (count($stack) !== 1) {
                throw new \RuntimeException('Unclosed section {{#' . $stack[count($stack) - 1]['name'] . '}}');
            }
            if ($pos < strlen($template)) {
                $root['children'][] = ['type' => 'text', 'value' => substr($template, $pos)];
            }
            return $root['children'];
        }

        private function render_nodes(array $nodes, array $stack): string {
            $out = '';
            foreach ($nodes as $node) {
                switch ($node['type']) {
                    case 'text':
                        $out .= $node['value'];
                        break;
                    case 'var':
                        $value = $this->lookup($node['name'], $stack);
                        $string = $this->to_string($value);
                        $out .= $node['escape'] ? s($string) : $string;
                        break;
                    case 'partial':
                        $out .= $this->render_nodes($this->parse(($this->loader)($node['name'])), $stack);
                        break;
                    case 'section':
                        $out .= $this->render_section($node, $stack);
                        break;
                }
            }
            return $out;
        }

        private function render_section(array $node, array $stack): string {
            if (!$node['inverted'] && in_array($node['name'], ['str', 'cleanstr'], true)) {
                $string = $this->string_helper($node['raw'], $stack);
                return $node['name'] === 'cleanstr' ? s($string) : $string;
            }
            $value = $this->lookup($node['name'], $stack);
            $islist = is_array($value) && array_is_list($value);
            $falsy = empty($value) || ($islist && count($value) === 0);
            if ($node['inverted']) {
                return $falsy ? $this->render_nodes($node['children'], $stack) : '';
            }
            if ($falsy) {
                return '';
            }
            if ($islist) {
                $out = '';
                foreach ($value as $item) {
                    $out .= $this->render_nodes($node['children'], array_merge($stack, [$item]));
                }
                return $out;
            }
            return $this->render_nodes($node['children'], array_merge($stack, [$value]));
        }

        // Moodle's mustache_string_helper: "identifier, component[, a]"; a is rendered (escaped) first.
        private function string_helper(string $text, array $stack): string {
            $parts = array_map('trim', explode(',', $text, 3));
            $a = null;
            if (isset($parts[2]) && $parts[2] !== '') {
                $a = $this->render_nodes($this->parse($parts[2]), $stack);
                if (strpos($parts[2], '{') === 0 && strpos($parts[2], '{{') !== 0) {
                    $a = json_decode($a);
                }
            }
            return get_string($parts[0], $parts[1] ?? '', $a);
        }

        private function lookup(string $name, array $stack) {
            if ($name === '.') {
                return end($stack);
            }
            $parts = explode('.', $name);
            $first = array_shift($parts);
            $value = null;
            $found = false;
            for ($i = count($stack) - 1; $i >= 0; $i--) {
                [$found, $value] = $this->get($stack[$i], $first);
                if ($found) {
                    break;
                }
            }
            if (!$found) {
                return null;
            }
            foreach ($parts as $part) {
                [$found, $value] = $this->get($value, $part);
                if (!$found) {
                    return null;
                }
            }
            return $value;
        }

        private function get($frame, string $key): array {
            if (is_array($frame) && array_key_exists($key, $frame)) {
                return [true, $frame[$key]];
            }
            if (is_object($frame) && property_exists($frame, $key)) {
                return [true, $frame->$key];
            }
            return [false, null];
        }

        private function to_string($value): string {
            if ($value === null || $value === false || is_array($value) || is_object($value)) {
                return '';
            }
            return $value === true ? '1' : (string) $value;
        }
    }
}

// $PAGE->requires: records every js_call_amd() call in $calls.
if (!class_exists('test_page_requirements')) {
    class test_page_requirements {
        /** @var array[] Each call as ['module' => ..., 'function' => ..., 'params' => ...]. */
        public array $calls = [];

        public function js_call_amd($module, $function, $params = []) {
            $this->calls[] = ['module' => $module, 'function' => $function, 'params' => $params];
        }

        public function css($url) {
        }
    }
}

// $PAGE: hands out the plugin renderer (mod_skilland\output\renderer) and records AMD calls.
if (!class_exists('test_moodle_page')) {
    class test_moodle_page {
        public test_page_requirements $requires;

        public function __construct() {
            $this->requires = new test_page_requirements();
        }

        public function get_renderer($component, $subtype = null, $target = null) {
            $class = $component . '\\output\\renderer';
            return new $class($this, $target);
        }
    }
}
