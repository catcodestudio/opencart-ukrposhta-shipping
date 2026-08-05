<?php
// PHP 8.0 string helpers, polyfilled so the module still runs on PHP 7.4.
//
// ⚠ No `defined('ABSPATH') || exit;` here. That guard is WordPress-only:
// ABSPATH never exists in OpenCart, so the line exits the WHOLE request the
// moment this file is required — the polyfills are never declared and the
// page dies blank. It was copied in from our WP plugins and shipped that way.
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('array_is_list')) {
    function array_is_list($array) {
        return $array === array() || array_keys($array) === range(0, count($array) - 1);
    }
}
