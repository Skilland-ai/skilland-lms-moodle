<?php
// Minimal ZipArchive stand-in for images without ext-zip (php:8.2-cli).
// open() accepts a file only when it carries a zip end-of-central-directory record.

if (!class_exists('ZipArchive')) {
    class ZipArchive {
        const ER_NOZIP = 19;
        const ER_OPEN = 11;

        public function open(string $filename, int $flags = 0) {
            $data = @file_get_contents($filename);
            if ($data === false) {
                return self::ER_OPEN;
            }
            if (strncmp($data, "PK\x03\x04", 4) !== 0 || strpos($data, "PK\x05\x06") === false) {
                return self::ER_NOZIP;
            }
            return true;
        }

        public function close(): bool {
            return true;
        }
    }
}
