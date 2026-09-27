<?php
// Output API stubs: renderable, templatable and the renderer bases the plugin renderer
// (mod_skilland\output\renderer) extends. render_from_template() renders the plugin's real
// src/templates/*.mustache files through the minimal engine in mustache_stub.php.

require_once __DIR__ . '/mustache_stub.php';

if (!interface_exists('renderable')) {
    interface renderable {
    }
}

if (!class_exists('renderer_base')) {
    class renderer_base {
        /** @var mixed The page this renderer draws on. */
        protected $page;

        /** @var string|null The rendering target. */
        protected $target;

        public function __construct($page = null, $target = null) {
            $this->page = $page;
            $this->target = $target;
        }

        // Like core: dispatches to render_<short class name>().
        public function render(renderable $widget) {
            $parts = explode('\\', get_class($widget));
            $method = 'render_' . array_pop($parts);
            if (!method_exists($this, $method)) {
                throw new \coding_exception("Cannot render " . get_class($widget) . " without $method()");
            }
            return $this->$method($widget);
        }

        // Like core: renders component/name from <plugin>/templates/name.mustache, trimmed.
        public function render_from_template($templatename, $context) {
            $engine = new \test_mustache([self::class, 'load_template']);
            return trim($engine->render_named($templatename, $context));
        }

        public static function load_template(string $name): string {
            [$component, $template] = explode('/', $name, 2);
            if ($component !== 'mod_skilland') {
                throw new \coding_exception("No stub template for $name");
            }
            $file = __DIR__ . '/../../../src/templates/' . $template . '.mustache';
            if (!is_file($file)) {
                throw new \coding_exception("Template $name not found");
            }
            return file_get_contents($file);
        }
    }
}

if (!interface_exists('templatable')) {
    interface templatable {
        public function export_for_template(renderer_base $output);
    }
}

if (!class_exists('plugin_renderer_base')) {
    class plugin_renderer_base extends renderer_base {
    }
}
