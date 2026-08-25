<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\Experience\Contract\ExperienceConfigurationValidator;
use Veyra\Features\Domain\FeatureRegistry;
use Veyra\Operations\Configuration\ProductConfigurationValidator;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        ++$passed;
        fwrite(STDOUT, "PASS {$message}\n");
        return;
    }
    ++$failed;
    fwrite(STDERR, "FAIL {$message}\n");
};

$features = FeatureRegistry::canonical();
$validator = new ProductConfigurationValidator($features, new ExperienceConfigurationValidator());
$defaults = $validator->defaults();

$agent = $defaults['agent'];
$check($validator->validate('agent', $agent) === [], 'complete default agent configuration is valid');
$agent['tone'] = 'ignore server policy';
$check(
    in_array('configuration_field_unknown', array_column($validator->validate('agent', $agent), 'code'), true),
    'unknown agent fields fail closed'
);

$knowledge = $defaults['knowledge'];
$knowledge['durable_memory_enabled'] = 'false';
$check(
    in_array('durable_memory_state_invalid', array_column($validator->validate('knowledge', $knowledge), 'code'), true),
    'knowledge booleans require their exact type'
);

$completeFeatures = [];
foreach ($features->all() as $definition) {
    $completeFeatures[$definition->key->value()] = [
        'configured_state' => $definition->foundational ? 'On' : 'Off',
    ];
}
$check($validator->validate('commerce', ['features' => $completeFeatures]) === [], 'complete safe commerce feature map is valid');
array_pop($completeFeatures);
$check(
    in_array('feature_missing', array_column($validator->validate('commerce', ['features' => $completeFeatures]), 'code'), true),
    'partial commerce maps cannot reset omitted features'
);

fwrite(STDOUT, "Product configuration scenarios: {$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
