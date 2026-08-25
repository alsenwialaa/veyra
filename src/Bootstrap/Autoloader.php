<?php

declare(strict_types=1);

namespace Veyra\Bootstrap;

final class Autoloader
{
    public static function register(string $sourceDirectory): void
    {
        $sourceDirectory = rtrim($sourceDirectory, '/\\');

        spl_autoload_register(
            static function (string $class) use ($sourceDirectory): void {
                $prefix = 'Veyra\\';

                if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                    return;
                }

                $relative = substr($class, strlen($prefix));
                $path = $sourceDirectory . DIRECTORY_SEPARATOR
                    . str_replace('\\', DIRECTORY_SEPARATOR, $relative)
                    . '.php';

                if (is_file($path) && is_readable($path)) {
                    require_once $path;
                }
            }
        );
    }
}

