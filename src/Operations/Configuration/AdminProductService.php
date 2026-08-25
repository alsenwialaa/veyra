<?php

declare(strict_types=1);

namespace Veyra\Operations\Configuration;

use Veyra\AI\Provider\CredentialVault;
use Veyra\AI\Provider\ProviderReadinessService;
use Veyra\Bootstrap\CompatibilityReport;
use Veyra\Features\Application\EffectiveFeatureStateService;
use Veyra\Features\Domain\FeatureRegistry;
use Veyra\Features\Domain\FeatureState;
use Veyra\Features\Infrastructure\WordPressFeatureConfigurationStore;
use Veyra\Infrastructure\Database\Migration\Migrator;
use Veyra\Infrastructure\Database\TableNames;

final class AdminProductService
{
    /** @var array<string, string> */
    public const CAPABILITIES = [
        'agent' => 'manage_veyra_agent',
        'knowledge' => 'manage_veyra_context_knowledge',
        'experience' => 'manage_veyra_experience',
        'commerce' => 'manage_veyra_features',
        'operations' => 'view_veyra_dashboard',
    ];

    public function __construct(
        private readonly \wpdb $database,
        private readonly TableNames $tables,
        private readonly ConfigurationRevisionRepository $revisions,
        private readonly ProductConfigurationValidator $validator,
        private readonly FeatureRegistry $features,
        private readonly EffectiveFeatureStateService $effectiveFeatures,
        private readonly ProviderReadinessService $readiness,
        private readonly CredentialVault $credentials,
        private readonly CompatibilityReport $compatibility
    ) {
    }

    /** @return array<string, mixed> */
    public function state(string $product, int $userId): array
    {
        $this->assertProduct($product);
        if ($product === 'operations') {
            return $this->operationsState();
        }

        $draft = $this->ensureDraft($product, $userId);
        $published = $this->revisions->latest($product, 'published');
        $canEdit = current_user_can(self::CAPABILITIES[$product]);
        $history = $this->revisions->publishedHistory($product, 2);
        $validation = is_array($draft['validation'] ?? null) ? $draft['validation'] : ['status' => 'not_run', 'issues' => []];
        $actions = $canEdit ? ['save_draft', 'validate', 'simulate'] : [];
        if ($canEdit && ($validation['status'] ?? null) === 'passed') {
            $actions[] = 'publish';
        }
        if ($canEdit && count($history) >= 2) {
            $actions[] = 'rollback';
        }

        return [
            'schema_version' => 'veyra.admin_product_state.v1',
            'product' => $product,
            'effective_state' => $published === null ? 'Draft only' : 'Published',
            'permissions' => [
                'view' => true,
                'edit' => $canEdit,
                'validate' => $canEdit,
                'simulate' => $canEdit,
                'publish' => $canEdit,
                'schedule' => false,
                'rollback' => $canEdit && count($history) >= 2,
                'import' => false,
                'manage_models' => $product === 'agent' && current_user_can('manage_veyra_models'),
            ],
            'available_actions' => array_values(array_unique($actions)),
            'draft' => ['version' => $draft['version'], 'configuration' => $draft['configuration']],
            'published' => $published === null ? null : ['version' => $published['version'], 'configuration' => $published['configuration']],
            'validation' => $validation,
            'schedule' => ['activation_at' => null, 'status' => 'not_supported_in_candidate'],
            'resources' => $this->resources($product),
        ];
    }

    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    public function saveDraft(string $product, array $configuration, ?string $expectedVersion, int $userId): array
    {
        $this->assertEditableProduct($product);
        $current = $this->ensureDraft($product, $userId);
        if ($expectedVersion === null || !hash_equals((string) $current['version'], $expectedVersion)) {
            return ['ok' => false, 'code' => 'draft_version_conflict'];
        }
        $completenessIssues = $this->commerceCompletenessIssues($product, $configuration);
        if ($completenessIssues !== []) {
            return ['ok' => false, 'code' => 'commerce_feature_map_incomplete', 'issues' => $completenessIssues];
        }
        $written = $this->revisions->append(
            $product,
            'draft',
            $configuration,
            $userId,
            $expectedVersion,
            ['status' => 'not_run', 'issues' => []]
        );
        return $written === null
            ? ['ok' => false, 'code' => 'draft_write_conflict']
            : ['ok' => true, 'code' => 'draft_saved', 'version' => $written['version']];
    }

    /** @return array<string, mixed> */
    public function validateDraft(string $product, ?string $expectedVersion, int $userId): array
    {
        $this->assertEditableProduct($product);
        $draft = $this->ensureDraft($product, $userId);
        if ($expectedVersion === null || !hash_equals((string) $draft['version'], $expectedVersion)) {
            return ['ok' => false, 'code' => 'draft_version_conflict'];
        }
        $issues = $this->validateConfiguration($product, $draft['configuration']);
        $validation = ['status' => $issues === [] ? 'passed' : 'failed', 'issues' => $issues, 'checked_at' => gmdate(DATE_ATOM)];
        $written = $this->revisions->append(
            $product,
            'draft',
            $draft['configuration'],
            $userId,
            $expectedVersion,
            $validation
        );
        return $written === null
            ? ['ok' => false, 'code' => 'validation_write_conflict']
            : ['ok' => true, 'code' => $issues === [] ? 'validation_passed' : 'validation_failed', 'issues' => $issues, 'version' => $written['version']];
    }

    /** @return array<string, mixed> */
    public function simulate(string $product, ?string $expectedVersion, int $userId): array
    {
        $this->assertEditableProduct($product);
        $draft = $this->ensureDraft($product, $userId);
        if ($expectedVersion === null || !hash_equals((string) $draft['version'], $expectedVersion)) {
            return ['ok' => false, 'code' => 'draft_version_conflict'];
        }
        $issues = $this->validateConfiguration($product, $draft['configuration']);
        return [
            'ok' => $issues === [],
            'code' => $issues === [] ? 'simulation_passed' : 'simulation_blocked',
            'issues' => $issues,
            'fixtures' => $product === 'experience' ? $this->experienceFixtures($issues === []) : [],
            'note' => 'This deterministic schema simulation is not a substitute for human accessibility, cultural, device or commerce acceptance testing.',
        ];
    }

    /** @return array<string, mixed> */
    public function publish(string $product, ?string $expectedDraft, ?string $expectedPublished, int $userId): array
    {
        $this->assertEditableProduct($product);
        $draft = $this->ensureDraft($product, $userId);
        $published = $this->revisions->latest($product, 'published');
        $actualPublished = $published['version'] ?? null;
        if ($expectedDraft === null || !hash_equals((string) $draft['version'], $expectedDraft) || $actualPublished !== $expectedPublished) {
            return ['ok' => false, 'code' => 'publication_version_conflict'];
        }
        $validation = $draft['validation'] ?? [];
        if (!is_array($validation) || ($validation['status'] ?? null) !== 'passed' || $this->validateConfiguration($product, $draft['configuration']) !== []) {
            return ['ok' => false, 'code' => 'publication_validation_required'];
        }
        if (!$this->transactionalPublicationStorageReady()) {
            return ['ok' => false, 'code' => 'publication_transactional_storage_required'];
        }
        $created = $this->revisions->appendGuarded(
            $product,
            'published',
            $draft['configuration'],
            $userId,
            [
                'draft' => $expectedDraft,
                'published' => is_string($actualPublished) ? $actualPublished : null,
            ],
            $validation,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            (string) $draft['version'],
            function (array $_created) use ($product, $draft): void {
                $this->applyPublished($product, $draft['configuration']);
            },
            null,
            function (array $_created) use ($product): void {
                $this->invalidatePublishedOptionCache($product);
            }
        );
        if ($created === null) {
            return ['ok' => false, 'code' => 'publication_write_conflict'];
        }
        return ['ok' => true, 'code' => 'publication_completed', 'version' => $created['version']];
    }

    /** @return array<string, mixed> */
    public function rollback(string $product, ?string $expectedPublished, int $userId): array
    {
        $this->assertEditableProduct($product);
        $history = $this->revisions->publishedHistory($product, 2);
        if (count($history) < 2 || $expectedPublished === null || !hash_equals((string) $history[0]['version'], $expectedPublished)) {
            return ['ok' => false, 'code' => 'rollback_target_unavailable'];
        }
        $target = $history[1];
        $issues = $this->validateConfiguration($product, $target['configuration']);
        if ($issues !== []) {
            return ['ok' => false, 'code' => 'rollback_target_no_longer_valid', 'issues' => $issues];
        }
        if (!$this->transactionalPublicationStorageReady()) {
            return ['ok' => false, 'code' => 'rollback_transactional_storage_required'];
        }
        $created = $this->revisions->appendGuarded(
            $product,
            'published',
            $target['configuration'],
            $userId,
            ['published' => $expectedPublished],
            ['status' => 'passed', 'issues' => [], 'checked_at' => gmdate(DATE_ATOM)],
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            (string) $target['version'],
            function (array $_created) use ($product, $target): void {
                $this->applyPublished($product, $target['configuration']);
            },
            null,
            function (array $_created) use ($product): void {
                $this->invalidatePublishedOptionCache($product);
            }
        );
        if ($created === null) {
            return ['ok' => false, 'code' => 'rollback_write_conflict'];
        }
        return ['ok' => true, 'code' => 'rollback_completed', 'version' => $created['version'], 'restored_from' => $target['version']];
    }

    /** @return array<string, mixed> */
    private function ensureDraft(string $product, int $userId): array
    {
        $draft = $this->revisions->latest($product, 'draft');
        if ($draft !== null) {
            return $draft;
        }
        $defaults = $this->initialConfiguration($product);
        $created = $this->revisions->append($product, 'draft', $defaults, $userId, null, ['status' => 'not_run', 'issues' => []]);
        if ($created === null) {
            $created = $this->revisions->latest($product, 'draft');
        }
        if ($created === null) {
            throw new \RuntimeException('The initial configuration draft could not be created.');
        }
        return $created;
    }

    /** @return array<string, mixed> */
    private function resources(string $product): array
    {
        return match ($product) {
            'agent' => ['provider' => $this->providerResource()],
            'knowledge' => ['knowledge_sources' => []],
            'experience' => ['fixtures' => $this->experienceFixtures(false)],
            'commerce' => ['features' => $this->featureResources()],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function providerResource(): array
    {
        $state = $this->readiness->current();
        return [
            'primary' => 'Google Gemini',
            'route' => (string) ($state['route_id'] ?? 'default_text_tool_orchestration'),
            'readiness' => (string) ($state['state'] ?? 'Unconfigured'),
            'checked_at' => $state['checked_at'] ?? null,
            'credential_configured' => $this->credentials->hasGeminiCredential(),
            'release_certified' => false,
            'safe_error_code' => $state['safe_error_code'] ?? null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function featureResources(): array
    {
        $configured = get_option(WordPressFeatureConfigurationStore::OPTION, []);
        $configured = is_array($configured) ? $configured : [];
        $rows = [];
        foreach ($this->features->all() as $definition) {
            $state = $this->effectiveFeatures->get($definition->key);
            $raw = $configured[$definition->key->value()] ?? ($definition->defaultOn ? FeatureState::On->value : FeatureState::Off->value);
            $rows[] = [
                'key' => $definition->key->value(),
                'label' => ucwords(str_replace('_', ' ', $definition->key->value())),
                'configured_state' => $raw === FeatureState::On->value ? 'On' : 'Off',
                'effective_state' => ucfirst($state->state->value),
                'reason' => $state->reasonCode,
                'remediation' => $state->safeFallback,
                'release_unit' => $definition->releaseUnit->value,
            ];
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function experienceFixtures(bool $validated): array
    {
        $status = $validated ? 'schema_passed_only' : 'not_run';
        return array_map(static fn (string $id): array => ['fixture_id' => $id, 'label' => ucwords(str_replace('_', ' ', $id)), 'status' => $status], [
            'mobile_en_ltr', 'mobile_ar_rtl', 'desktop_en_ltr', 'blocked', 'stale', 'error', 'success',
        ]);
    }

    /** @return array<string, mixed> */
    private function operationsState(): array
    {
        $readiness = $this->readiness->current();
        return [
            'schema_version' => 'veyra.admin_product_state.v1',
            'product' => 'operations',
            'status' => 'Read only',
            'snapshot_version' => gmdate('YmdHi'),
            'permissions' => ['view' => true, 'edit' => false],
            'available_actions' => [],
            'resources' => [
                'health' => [
                    ['key' => 'provider', 'label' => 'Provider & AI', 'state' => (string) ($readiness['state'] ?? 'Unconfigured'), 'safe_reason' => $readiness['safe_error_code'] ?? null],
                    ['key' => 'woocommerce', 'label' => 'WooCommerce', 'state' => $this->compatibility->commerceReady() ? 'Available' : 'Blocked', 'safe_reason' => implode(',', $this->compatibility->codes())],
                    ['key' => 'schema', 'label' => 'Context & storage', 'state' => (string) get_option(Migrator::SCHEMA_OPTION, '0.0.0'), 'safe_reason' => null],
                    ['key' => 'release', 'label' => 'Release evidence', 'state' => 'NOT READY', 'safe_reason' => 'Runtime, compatibility, evaluation and human acceptance gates remain open.'],
                ],
                'conversations' => $this->countResource($this->tables->conversations(), 'Conversations'),
                'crm' => $this->countResource($this->tables->cases(), 'CRM cases'),
                'payment_reviews' => $this->countResource($this->tables->paymentReviews(), 'Payment reviews'),
                'failures' => [],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function countResource(string $table, string $label): array
    {
        $count = $this->database->get_var("SELECT COUNT(*) FROM {$table}");
        return [['label' => $label, 'status' => is_numeric($count) ? (string) ((int) $count) : 'Unavailable']];
    }

    /** @param array<string, mixed> $configuration */
    private function applyPublished(string $product, array $configuration): void
    {
        if ($product === 'commerce') {
            if ($this->commerceCompletenessIssues($product, $configuration) !== []) {
                throw new \RuntimeException('Published commerce configuration must include every registered feature.');
            }
            $values = [];
            foreach (($configuration['features'] ?? []) as $key => $feature) {
                if (is_string($key) && is_array($feature)) {
                    $values[$key] = ($feature['configured_state'] ?? 'Off') === 'On' ? FeatureState::On->value : FeatureState::Off->value;
                }
            }
            $this->writeAndVerifyPublishedOption(WordPressFeatureConfigurationStore::OPTION, $values);
            return;
        }
        $option = 'veyra_' . $product . '_published_v1';
        $this->writeAndVerifyPublishedOption($option, $configuration);
    }

    /** @param array<string, mixed> $value */
    private function writeAndVerifyPublishedOption(string $option, array $value): void
    {
        $optionTable = is_string($this->database->options ?? null) ? $this->database->options : '';
        if ($optionTable === '') {
            throw new \RuntimeException('Published configuration option table is unavailable.');
        }
        $serialized = serialize($value);
        $written = $this->database->query($this->database->prepare(
            "INSERT INTO {$optionTable} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'",
            $option,
            $serialized
        ));
        if ($written === false) {
            throw new \RuntimeException('Published configuration persistence failed.');
        }
        $persisted = $this->database->get_var($this->database->prepare(
            "SELECT option_value FROM {$optionTable} WHERE option_name = %s LIMIT 1",
            $option
        ));
        if (!is_string($persisted)) {
            throw new \RuntimeException('Published configuration persistence could not be read authoritatively.');
        }
        if (!hash_equals($serialized, $persisted)) {
            throw new \RuntimeException('Published configuration persistence could not be verified authoritatively.');
        }
    }

    /** @param array<string, mixed> $configuration @return list<array{code:string,path:string,message:string}> */
    private function validateConfiguration(string $product, array $configuration): array
    {
        return array_values(array_merge(
            $this->validator->validate($product, $configuration),
            $this->commerceCompletenessIssues($product, $configuration)
        ));
    }

    /** @param array<string, mixed> $configuration @return list<array{code:string,path:string,message:string}> */
    private function commerceCompletenessIssues(string $product, array $configuration): array
    {
        if ($product !== 'commerce') {
            return [];
        }
        $configured = $configuration['features'] ?? null;
        $known = array_map(
            static fn ($definition): string => $definition->key->value(),
            $this->features->all()
        );
        if (!is_array($configured)) {
            return [[
                'code' => 'feature_map_incomplete',
                'path' => '$.features',
                'message' => 'A complete keyed map of every registered feature is required.',
            ]];
        }
        $missing = array_values(array_diff($known, array_keys($configured)));
        if ($missing === []) {
            return [];
        }
        return [[
            'code' => 'feature_map_incomplete',
            'path' => '$.features',
            'message' => 'The feature map omitted registered features: ' . implode(', ', $missing) . '.',
        ]];
    }

    /** @return array<string, mixed> */
    private function initialConfiguration(string $product): array
    {
        $configuration = $this->validator->defaults()[$product];
        if ($product !== 'commerce') {
            return $configuration;
        }
        $stored = get_option(WordPressFeatureConfigurationStore::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $configuration['features'] = [];
        foreach ($this->features->all() as $definition) {
            $key = $definition->key->value();
            $raw = $stored[$key] ?? ($definition->defaultOn ? FeatureState::On->value : FeatureState::Off->value);
            $configuration['features'][$key] = [
                'configured_state' => $raw === FeatureState::On->value ? 'On' : 'Off',
            ];
        }
        return $configuration;
    }

    private function invalidatePublishedOptionCache(string $product): void
    {
        if (!function_exists('wp_cache_delete')) {
            return;
        }
        $option = $product === 'commerce'
            ? WordPressFeatureConfigurationStore::OPTION
            : 'veyra_' . $product . '_published_v1';
        wp_cache_delete($option, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    private function transactionalPublicationStorageReady(): bool
    {
        $optionTable = is_string($this->database->options ?? null) ? $this->database->options : '';
        foreach ([$this->tables->configurationRevisions(), $optionTable] as $table) {
            if ($table === '') {
                return false;
            }
            $row = $this->database->get_row(
                $this->database->prepare('SHOW TABLE STATUS WHERE Name = %s', $table),
                ARRAY_A
            );
            if (!is_array($row)) {
                return false;
            }
            $engine = null;
            foreach ($row as $key => $value) {
                if (is_string($key) && strcasecmp($key, 'Engine') === 0) {
                    $engine = $value;
                    break;
                }
            }
            if (!is_string($engine) || strcasecmp($engine, 'InnoDB') !== 0) {
                return false;
            }
        }
        return true;
    }

    private function assertProduct(string $product): void
    {
        if (!isset(self::CAPABILITIES[$product])) {
            throw new \InvalidArgumentException('Unknown administration product.');
        }
    }

    private function assertEditableProduct(string $product): void
    {
        $this->assertProduct($product);
        if ($product === 'operations') {
            throw new \InvalidArgumentException('Operations is read-only.');
        }
    }
}
