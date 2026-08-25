<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Bootstrap/Autoloader.php';
\Veyra\Bootstrap\Autoloader::register($root . '/src');

$symbols = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $root . '/src',
    FilesystemIterator::SKIP_DOTS
));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($root . '/src/'));
    $symbols[] = 'Veyra\\' . str_replace('/', '\\', substr($relative, 0, -4));
}
sort($symbols, SORT_STRING);

$passed = 0;
$failures = [];
foreach ($symbols as $symbol) {
    try {
        if (class_exists($symbol)
            || interface_exists($symbol)
            || trait_exists($symbol)
            || (function_exists('enum_exists') && enum_exists($symbol))
        ) {
            ++$passed;
            continue;
        }
        $failures[] = $symbol . ': declaration not found';
    } catch (Throwable $error) {
        $failures[] = $symbol . ': ' . $error->getMessage();
    }
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL {$failure}\n");
}
printf(
    "SOURCE_SYMBOL_LOAD passed=%d failed=%d total=%d\n",
    $passed,
    count($failures),
    count($symbols)
);
exit($failures === [] ? 0 : 1);
