<?php

declare(strict_types=1);

namespace Veyra\Features\Infrastructure;

use Veyra\Features\Domain\FeatureRegistry;

final class FeatureDefaultsInstaller
{
    public static function install(): void
    {
        if (get_option(WordPressFeatureConfigurationStore::OPTION, null) !== null) {
            return;
        }

        $defaults = [];

        foreach (FeatureRegistry::canonical()->all() as $feature) {
            $defaults[$feature->key->value()] = $feature->defaultOn ? 'on' : 'off';
        }

        add_option(WordPressFeatureConfigurationStore::OPTION, $defaults, '', false);
        add_option(WordPressFeatureConfigurationStore::CERTIFICATION_OPTION, [], '', false);
    }
}

