<?php

declare(strict_types=1);

// Some PHP WebAssembly CLI-compatible runtimes omit the conventional stream
// constants even though php://stdout and php://stderr are available.
if (!defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'wb'));
}
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

// Request-boundary fixtures exercise production sanitization without loading a
// complete WordPress runtime. Individual integration runners may override
// these before including this bootstrap.
if (!function_exists('wp_unslash')) {
    function wp_unslash(string $value): string
    {
        return stripslashes($value);
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

$composer = dirname(__DIR__) . '/vendor/autoload.php';

if (is_readable($composer)) {
    require_once $composer;
} else {
    require_once dirname(__DIR__) . '/src/Bootstrap/Autoloader.php';
    \Veyra\Bootstrap\Autoloader::register(dirname(__DIR__) . '/src');

    spl_autoload_register(
        static function (string $class): void {
            $prefix = 'Veyra\\Tests\\';

            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }

            $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_readable($path)) {
                require_once $path;
            }
        }
    );
}
