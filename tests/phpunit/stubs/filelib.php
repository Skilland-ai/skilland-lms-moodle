<?php
// Minimal curl class stub for standalone testing.
// Configurable via $GLOBALS['_test_curl_response'] for testing HTTP paths.

if (!class_exists('curl')) {
    class curl {
        private $options = [];
        private $headers = [];

        public function __construct(array $settings = []) {}

        public function setopt(array $options): void {
            $this->options = array_merge($this->options, $options);
        }

        public function setHeader(array $headers): void {
            $this->headers = $headers;
        }

        public function post(string $url, string $data): string {
            if (isset($GLOBALS['_test_curl_response'])) {
                return $GLOBALS['_test_curl_response']['body'] ?? '';
            }
            return '';
        }

        public function get_info(): array {
            if (isset($GLOBALS['_test_curl_response'])) {
                return ['http_code' => $GLOBALS['_test_curl_response']['http_code'] ?? 0];
            }
            return ['http_code' => 0];
        }

        public function get_errno(): int {
            if (isset($GLOBALS['_test_curl_response'])) {
                return $GLOBALS['_test_curl_response']['errno'] ?? 0;
            }
            return 7; // CURLE_COULDNT_CONNECT
        }

        public function error(): string {
            if (isset($GLOBALS['_test_curl_response'])) {
                return $GLOBALS['_test_curl_response']['error'] ?? '';
            }
            return 'Stubbed curl — not connected';
        }
    }
}
