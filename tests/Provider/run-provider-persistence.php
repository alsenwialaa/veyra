<?php

declare(strict_types=1);

define('AUTH_KEY', str_repeat('a', 32));
define('SECURE_AUTH_SALT', str_repeat('s', 32));

/** @var array<string, mixed> $veyraOptions */
$veyraOptions = [];
$veyraFailOptionWrite = false;
$veyraFailOptionDelete = false;

function update_option(string $name, mixed $value, bool $autoload = false): bool
{
    global $veyraOptions, $veyraFailOptionWrite;
    if ($veyraFailOptionWrite) {
        return false;
    }
    $changed = !array_key_exists($name, $veyraOptions) || $veyraOptions[$name] !== $value;
    $veyraOptions[$name] = $value;
    return $changed;
}

function get_option(string $name, mixed $default = false): mixed
{
    global $veyraOptions;
    return array_key_exists($name, $veyraOptions) ? $veyraOptions[$name] : $default;
}

function delete_option(string $name): bool
{
    global $veyraOptions, $veyraFailOptionDelete;
    if ($veyraFailOptionDelete) {
        return false;
    }
    if (!array_key_exists($name, $veyraOptions)) {
        return false;
    }
    unset($veyraOptions[$name]);
    return true;
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\AI\Contract\ProviderAdapter;
use Veyra\AI\Contract\ProviderRequest;
use Veyra\AI\Contract\ProviderResult;
use Veyra\AI\Provider\CredentialVault;
use Veyra\AI\Provider\ProviderPayloadValidator;
use Veyra\AI\Provider\ProviderReadinessService;
use Veyra\AI\Provider\RouteManifest;

final class PersistenceTestProvider implements ProviderAdapter
{
    public function providerKey(): string { return 'test'; }
    public function execute(ProviderRequest $request): ProviderResult
    {
        return ProviderResult::failure('not_used', false);
    }
}

$passed = 0;
$failed = 0;
$scenario = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        ++$passed;
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$scenario('credential success requires encrypted persistence readback', static function () use ($assert): void {
    global $veyraOptions, $veyraFailOptionWrite;
    $veyraOptions = [];
    $veyraFailOptionWrite = false;
    $vault = new CredentialVault();
    $vault->storeGeminiCredential('test-api-key');
    $assert($vault->hasGeminiCredential(), 'Verified credential was not readable.');

    $veyraFailOptionWrite = true;
    $thrown = false;
    try {
        $vault->storeGeminiCredential('different-key');
    } catch (RuntimeException) {
        $thrown = true;
    }
    $veyraFailOptionWrite = false;
    $assert($thrown, 'Failed credential persistence was reported as success.');
    $assert($vault->geminiCredential() === 'test-api-key', 'Failed overwrite changed the verified credential.');
});

$scenario('credential revocation and readiness block require persistence readback', static function () use ($assert): void {
    global $veyraOptions, $veyraFailOptionDelete, $veyraFailOptionWrite;
    $veyraOptions = [];
    $veyraFailOptionDelete = false;
    $veyraFailOptionWrite = false;
    $vault = new CredentialVault();
    $vault->storeGeminiCredential('test-api-key');
    $veyraFailOptionDelete = true;
    $thrown = false;
    try {
        $vault->clearGeminiCredential();
    } catch (RuntimeException) {
        $thrown = true;
    }
    $veyraFailOptionDelete = false;
    $assert($thrown && $vault->hasGeminiCredential(), 'Failed credential revocation was reported as complete.');

    $service = new ProviderReadinessService(
        new PersistenceTestProvider(),
        new ProviderPayloadValidator(),
        new RouteManifest(dirname(__DIR__, 2) . '/config/provider-route-manifest.php')
    );
    $veyraFailOptionWrite = true;
    $thrown = false;
    try {
        $service->block('provider_unconfigured');
    } catch (RuntimeException) {
        $thrown = true;
    }
    $veyraFailOptionWrite = false;
    $assert($thrown, 'Failed readiness persistence was reported as blocked.');
});

fwrite(STDOUT, sprintf("Provider persistence scenarios: %d passed, %d failed\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
