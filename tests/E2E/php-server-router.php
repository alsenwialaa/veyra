<?php

declare(strict_types=1);

/**
 * Router for the browser-only WordPress fixture.
 *
 * PHP's development server returns existing files directly. Every other path
 * is routed through WordPress with root-relative script metadata so plugins_url()
 * cannot inherit the temporary CI install-directory name.
 */

$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$candidate = is_string($documentRoot) && is_string($requestPath)
    ? realpath($documentRoot . '/' . ltrim($requestPath, '/'))
    : false;

if (is_string($documentRoot)
    && is_string($candidate)
    && is_file($candidate)
    && ($candidate === $documentRoot || str_starts_with($candidate, $documentRoot . DIRECTORY_SEPARATOR))
) {
    return false;
}

if (!is_string($documentRoot) || !is_file($documentRoot . '/index.php')) {
    http_response_code(500);
    echo 'WordPress document root is unavailable.';
    return true;
}

$_SERVER['SCRIPT_FILENAME'] = $documentRoot . '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $documentRoot . '/index.php';

return true;
