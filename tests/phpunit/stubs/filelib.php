<?php
// Minimal curl class stub for standalone testing.
// Configurable via $GLOBALS['_test_curl_response'] for testing HTTP paths.
// Every instance records its constructor settings, options, headers and requested URL
// into $GLOBALS['_test_curl_last']; every request URL is appended to $GLOBALS['_test_curl_requests'].
// $GLOBALS['_test_curl_responses'] (a list) queues one response per request, in order: each request
// shifts the next one into $GLOBALS['_test_curl_response']. A response's 'headers' (name => value)
// are what getResponse() returns for it.

if (!class_exists('curl')) {
    class curl {
        private $options = [];
        private $headers = [];

        public function __construct(array $settings = []) {
            $GLOBALS['_test_curl_last'] = [
                'settings' => $settings,
                'options' => [],
                'headers' => [],
                'url' => null,
            ];
        }

        public function setopt(array $options): void {
            $this->options = array_merge($this->options, $options);
            $GLOBALS['_test_curl_last']['options'] = $this->options;
        }

        public function setHeader(array $headers): void {
            $this->headers = $headers;
            $GLOBALS['_test_curl_last']['headers'] = $headers;
        }

        private function record(string $url): void {
            if (!empty($GLOBALS['_test_curl_responses'])) {
                $GLOBALS['_test_curl_response'] = array_shift($GLOBALS['_test_curl_responses']);
            }
            $GLOBALS['_test_curl_last']['url'] = $url;
            $GLOBALS['_test_curl_requests'][] = $url;
        }

        public function post(string $url, string $data): string {
            $this->record($url);
            $GLOBALS['_test_curl_last']['body'] = $data;
            $GLOBALS['_test_curl_posts'][] = ['url' => $url, 'body' => $data];
            if (isset($GLOBALS['_test_curl_response'])) {
                return $GLOBALS['_test_curl_response']['body'] ?? '';
            }
            return '';
        }

        public function get(string $url, $params = [], $options = []): string {
            $this->record($url);
            if (isset($GLOBALS['_test_curl_response'])) {
                return $GLOBALS['_test_curl_response']['body'] ?? '';
            }
            return '';
        }

        public function download_one($url, $params, $options = []) {
            $this->record($url);
            $response = $GLOBALS['_test_curl_response'] ?? null;
            if ($response === null) {
                return false;
            }
            if (isset($options['file']) && is_resource($options['file'])) {
                fwrite($options['file'], (string) ($response['body'] ?? ''));
            }
            $code = (int) ($response['http_code'] ?? 0);
            return $code >= 200 && $code < 300;
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

        public function getResponse(): array {
            if (isset($GLOBALS['_test_curl_response'])) {
                return $GLOBALS['_test_curl_response']['headers'] ?? [];
            }
            return [];
        }

        public function error(): string {
            if (isset($GLOBALS['_test_curl_response'])) {
                return $GLOBALS['_test_curl_response']['error'] ?? '';
            }
            return 'Stubbed curl — not connected';
        }
    }
}
