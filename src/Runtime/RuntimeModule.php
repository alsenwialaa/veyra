<?php

declare(strict_types=1);

namespace Veyra\Runtime;

use Veyra\AI\Orchestration\CommerceAgent;
use Veyra\AI\Orchestration\DecisionPlanExecutor;
use Veyra\AI\Orchestration\PromptPolicyCompiler;
use Veyra\AI\Orchestration\ResponseVerifier;
use Veyra\AI\Orchestration\SemanticResponseVerifier;
use Veyra\AI\Orchestration\ServerComponentBuilder;
use Veyra\AI\Orchestration\WooAuthoritativeContextProvider;
use Veyra\AI\Provider\CredentialVault;
use Veyra\AI\Provider\GeminiProviderAdapter;
use Veyra\AI\Provider\ProviderPayloadValidator;
use Veyra\AI\Provider\ProviderProhibitedDataRedactor;
use Veyra\AI\Provider\ProviderReadinessService;
use Veyra\AI\Provider\ProviderReadinessStateStore;
use Veyra\AI\Provider\ProviderReleaseGate;
use Veyra\AI\Provider\ProviderRequestAttestor;
use Veyra\AI\Provider\ProviderTransmissionGate;
use Veyra\AI\Provider\RouteManifest;
use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContextFactory;
use Veyra\AI\Tool\ToolInputValidator;
use Veyra\AI\Tool\ToolRegistry;
use Veyra\Audit\Application\AuditWriter;
use Veyra\Bootstrap\CompatibilityReport;
use Veyra\Bootstrap\Container;
use Veyra\Cart\Tool\CartToolHandler;
use Veyra\Catalog\Tool\CatalogToolHandler;
use Veyra\Checkout\Application\CheckoutInputSanitizer;
use Veyra\Checkout\Application\CheckoutSessionService;
use Veyra\Checkout\Infrastructure\WooCommerceCheckoutAuthority;
use Veyra\Checkout\Infrastructure\WpdbCheckoutStateRepository;
use Veyra\Checkout\Tool\CheckoutToolHandler;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Application\LockManager;
use Veyra\Confirmation\Application\SensitiveActionGate;
use Veyra\Context\Tool\ContextToolHandler;
use Veyra\Conversation\Application\ContextBundleAssembler;
use Veyra\Conversation\Application\ConversationStateUpdater;
use Veyra\Conversation\Application\ConversationStore;
use Veyra\Conversation\Application\ShortReplyBindingValidator;
use Veyra\Conversation\Domain\ContextBundlePolicy;
use Veyra\Conversation\Domain\ContextBundleAttestor;
use Veyra\Conversation\Persistence\WpdbConversationStore;
use Veyra\Conversation\Persistence\WpdbContextBundleManifestRepository;
use Veyra\CRM\Infrastructure\WpdbCaseRepository;
use Veyra\CRM\Tool\CrmToolHandler;
use Veyra\Experience\Contract\ExperienceConfigurationValidator;
use Veyra\Experience\Contract\MessagePayloadAssembler;
use Veyra\Experience\Presentation\ChatRestController;
use Veyra\Experience\Presentation\CustomerExperience;
use Veyra\Features\Application\EffectiveFeatureStateService;
use Veyra\Features\Application\FeatureGate;
use Veyra\Features\Application\RuntimeFeatureRegistry;
use Veyra\Features\Domain\FeatureKey;
use Veyra\Features\Domain\FeatureRegistry;
use Veyra\Features\Domain\FeatureState;
use Veyra\Http\CustomerMessagePresenter;
use Veyra\Http\RateLimiter;
use Veyra\Identity\Application\ActorResolver;
use Veyra\Identity\Application\CapabilityPolicy;
use Veyra\Identity\Application\GuestSessionManager;
use Veyra\Identity\Application\GuestAccountLinkService;
use Veyra\Identity\Domain\CapabilityRegistry;
use Veyra\Identity\Infrastructure\GuestCookieManager;
use Veyra\Identity\Presentation\RestPermissionGate;
use Veyra\Infrastructure\Database\Migration\Migrator;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Knowledge\Application\KnowledgeService;
use Veyra\Knowledge\Infrastructure\WordPressPublishedKnowledgeRepository;
use Veyra\Knowledge\Tool\KnowledgeToolHandler;
use Veyra\Media\Infrastructure\WpdbAttachmentRepository;
use Veyra\Media\Tool\MediaToolHandler;
use Veyra\Operations\Configuration\AdminProductService;
use Veyra\Operations\Configuration\ConfigurationRevisionRepository;
use Veyra\Operations\Configuration\ProductConfigurationValidator;
use Veyra\Operations\Presentation\AdminProducts;
use Veyra\Operations\Presentation\AdminRestController;
use Veyra\Orders\Tool\OrderToolHandler;
use Veyra\PaymentReview\Application\PaymentReviewService;
use Veyra\PaymentReview\Infrastructure\WooCommercePaymentReviewAuthority;
use Veyra\PaymentReview\Infrastructure\WpdbPaymentReviewRepository;
use Veyra\PaymentReview\Tool\PaymentReviewToolHandler;
use Veyra\Recommendation\Application\RecommendationService;
use Veyra\Recommendation\Infrastructure\WooCommerceProductCandidateRepository;
use Veyra\Recommendation\Infrastructure\WordPressPublishedRecommendationPolicyRepository;
use Veyra\Recommendation\Tool\RecommendationToolHandler;
use Veyra\Requirements\Application\RequirementStateService;
use Veyra\Requirements\Contract\RequirementStateRepository;
use Veyra\Requirements\Infrastructure\WpdbRequirementStateRepository;
use Veyra\Requirements\Tool\RequirementsToolHandler;
use Veyra\Shared\Domain\Clock;

/** Composition root. Domain services remain independently gated and testable. */
final class RuntimeModule
{
    private static string $pluginFile = '';

    public static function register(string $pluginFile): void
    {
        self::$pluginFile = $pluginFile;
        add_action('veyra_register_services', [self::class, 'registerServices'], 10, 2);
    }

    public static function registerServices(Container $container, CompatibilityReport $compatibility): void
    {
        try {
            self::composeServices($container, $compatibility);
        } catch (\Throwable $error) {
            // No WordPress hooks or routes are mounted until composition has
            // completed. If a malformed manifest or dependency still aborts
            // composition, make every known feature explicitly unavailable
            // before handing the failure to Plugin's request-safe boundary.
            self::blockRuntimeAfterCompositionFailure($container);
            throw $error;
        }
    }

    private static function composeServices(Container $container, CompatibilityReport $compatibility): void
    {
        /** @var \wpdb $database */
        $database = $container->get(\wpdb::class);
        /** @var TableNames $tables */
        $tables = $container->get(TableNames::class);
        /** @var FeatureRegistry $featureRegistry */
        $featureRegistry = $container->get(FeatureRegistry::class);
        /** @var RuntimeFeatureRegistry $runtimeFeatures */
        $runtimeFeatures = $container->get(RuntimeFeatureRegistry::class);
        /** @var EffectiveFeatureStateService $effectiveFeatures */
        $effectiveFeatures = $container->get(EffectiveFeatureStateService::class);
        /** @var FeatureGate $featureGate */
        $featureGate = $container->get(FeatureGate::class);
        /** @var ActorResolver $actorResolver */
        $actorResolver = $container->get(ActorResolver::class);
        /** @var CapabilityPolicy $capabilityPolicy */
        $capabilityPolicy = $container->get(CapabilityPolicy::class);
        /** @var GuestSessionManager $guestSessions */
        $guestSessions = $container->get(GuestSessionManager::class);
        /** @var GuestAccountLinkService $guestAccountLinks */
        $guestAccountLinks = $container->get(GuestAccountLinkService::class);
        /** @var GuestCookieManager $guestCookies */
        $guestCookies = $container->get(GuestCookieManager::class);
        /** @var IdempotencyService $idempotency */
        $idempotency = $container->get(IdempotencyService::class);
        /** @var LockManager $locks */
        $locks = $container->get(LockManager::class);
        /** @var SensitiveActionGate $sensitiveActions */
        $sensitiveActions = $container->get(SensitiveActionGate::class);
        /** @var AuditWriter $audit */
        $audit = $container->get(AuditWriter::class);
        /** @var Clock $clock */
        $clock = $container->get(Clock::class);

        $linkGuestAccount = static function () use ($guestAccountLinks, $guestCookies): void {
            if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
                return;
            }
            $user = wp_get_current_user();
            if (!$user instanceof \WP_User || !$user->exists()) {
                return;
            }
            // Never merge a shopper guest identity into a privileged staff
            // actor. Staff/customer separation remains explicit.
            foreach (CapabilityRegistry::names() as $capability) {
                if ($user->has_cap($capability)) {
                    return;
                }
            }
            $rawToken = GuestCookieManager::readSessionToken();
            if ($guestAccountLinks->link($rawToken, (int) $user->ID)) {
                try {
                    $guestCookies->clear();
                } catch (\Throwable) {
                    // State was linked and audited. A stale cookie is inactive
                    // and cannot resolve; it will expire or be overwritten.
                }
            }
        };

        $manifest = new RouteManifest(dirname(self::$pluginFile) . '/config/provider-route-manifest.php');
        $payloadValidator = new ProviderPayloadValidator();
        $credentials = new CredentialVault();
        $readinessStates = new ProviderReadinessStateStore();
        $bundleAttestor = new ContextBundleAttestor();
        $requestAttestor = new ProviderRequestAttestor();
        $transmissionGate = new ProviderTransmissionGate(
            $manifest,
            $readinessStates,
            $bundleAttestor,
            $requestAttestor,
            $payloadValidator
        );
        $provider = new GeminiProviderAdapter($manifest, $credentials, $payloadValidator, $transmissionGate);
        $readiness = new ProviderReadinessService($provider, $payloadValidator, $manifest, $readinessStates, $requestAttestor);
        $releaseGate = new ProviderReleaseGate($manifest);
        self::configureRuntimeFeatures($runtimeFeatures, $readiness, $releaseGate, $compatibility);
        $route = $manifest->route(ProviderReleaseGate::ROUTE_ID);
        $releaseDecision = $releaseGate->decision($readiness->current());
        $requiredSchema = defined('VEYRA_SCHEMA_VERSION') ? (string) VEYRA_SCHEMA_VERSION : '1.6.0';
        $schemaReady = (string) get_option(Migrator::SCHEMA_OPTION, '0.0.0') === $requiredSchema;
        $contextPolicy = new ContextBundlePolicy(
            ProviderReleaseGate::ROUTE_ID,
            $manifest->version(),
            (string) ($route['shopper_purpose'] ?? 'shopper_commerce_assistance'),
            $schemaReady && $compatibility->commerceReady() && $releaseDecision['allowed'],
            !$schemaReady
                ? 'schema_migration_required'
                : ($compatibility->commerceReady()
                    ? $releaseDecision['reason_code']
                    : self::commerceCompatibilityReason($compatibility)),
            is_array($route['allowed_data_classes'] ?? null)
                ? array_values(array_filter($route['allowed_data_classes'], 'is_string'))
                : [],
            (int) ($route['max_context_bytes'] ?? 65536),
            (int) ($route['max_context_items'] ?? 256),
            (int) ($route['context_bundle_ttl_seconds'] ?? 300)
        );

        $conversationStore = new WpdbConversationStore($database);
        $contextBundleManifests = new WpdbContextBundleManifestRepository($database, $tables);
        $providerOutboundSanitizer = new ProviderProhibitedDataRedactor();
        $requirementStates = new WpdbRequirementStateRepository($database, $tables);
        $requirements = new RequirementStateService($conversationStore, $requirementStates, $clock);
        $tools = new ToolRegistry(new ToolInputValidator());
        $actorMapper = new FoundationActorMapper();
        $tools->register(new ContextToolHandler($conversationStore));
        $tools->register(new CatalogToolHandler());
        $tools->register(new CartToolHandler($idempotency, $actorMapper, null, $sensitiveActions, $locks, $audit));
        $tools->register(new OrderToolHandler());
        self::registerOptionalRuntimeHandlers(
            $tools,
            $requirements,
            $idempotency,
            $actorMapper,
            $database,
            $tables,
            $clock,
            $locks,
            $audit
        );

        $commerceAgent = new CommerceAgent(
            $provider,
            $payloadValidator,
            $tools,
            $conversationStore,
            new ContextBundleAssembler(
                $conversationStore,
                $contextPolicy,
                $requirements,
                null,
                $bundleAttestor,
                $contextBundleManifests,
                $providerOutboundSanitizer
            ),
            new ConversationStateUpdater($conversationStore),
            new WooAuthoritativeContextProvider(),
            new PromptPolicyCompiler(),
            new ResponseVerifier(),
            new SemanticResponseVerifier($provider, $payloadValidator, $requestAttestor),
            new ServerComponentBuilder(),
            new ShortReplyBindingValidator(),
            new DecisionPlanExecutor($tools),
            (int) ($route['max_provider_calls'] ?? 3),
            (int) ($route['max_tool_calls'] ?? 8),
            $requestAttestor
        );
        $contextFactory = new ToolContextFactory($featureRegistry, $effectiveFeatures);
        $rateLimiter = new RateLimiter($database, $tables);
        $presenter = new CustomerMessagePresenter(new MessagePayloadAssembler());
        $readPermission = new RestPermissionGate(
            $actorResolver,
            $capabilityPolicy,
            $featureGate,
            $guestSessions,
            new FeatureKey('ai_semantic_orchestration'),
            null,
            true,
            false
        );
        $writePermission = new RestPermissionGate(
            $actorResolver,
            $capabilityPolicy,
            $featureGate,
            $guestSessions,
            new FeatureKey('ai_semantic_orchestration'),
            null,
            true,
            true
        );
        $chat = new ChatRestController(
            $actorResolver,
            $readPermission,
            $writePermission,
            $conversationStore,
            $commerceAgent,
            $contextFactory,
            $presenter,
            $idempotency,
            $rateLimiter
        );

        $configurationRevisions = new ConfigurationRevisionRepository($database, $tables);
        $configurationValidator = new ProductConfigurationValidator(
            $featureRegistry,
            new ExperienceConfigurationValidator()
        );
        $adminService = new AdminProductService(
            $database,
            $tables,
            $configurationRevisions,
            $configurationValidator,
            $featureRegistry,
            $effectiveFeatures,
            $readiness,
            $credentials,
            $compatibility
        );
        $adminRest = new AdminRestController(
            $adminService,
            $credentials,
            $readiness,
            $releaseGate,
            $runtimeFeatures,
            $actorResolver,
            $audit,
            $idempotency
        );

        $container->set(RouteManifest::class, $manifest);
        $container->set(CredentialVault::class, $credentials);
        $container->set(ProviderPayloadValidator::class, $payloadValidator);
        $container->set(GeminiProviderAdapter::class, $provider);
        $container->set(ProviderReadinessService::class, $readiness);
        $container->set(ProviderReadinessStateStore::class, $readinessStates);
        $container->set(ProviderReleaseGate::class, $releaseGate);
        $container->set(ProviderTransmissionGate::class, $transmissionGate);
        $container->set(ConversationStore::class, $conversationStore);
        $container->set(RequirementStateRepository::class, $requirementStates);
        $container->set(RequirementStateService::class, $requirements);
        $container->set(ToolRegistry::class, $tools);
        $container->set(CommerceAgent::class, $commerceAgent);
        $container->set(ToolContextFactory::class, $contextFactory);
        $container->set(RateLimiter::class, $rateLimiter);
        $container->set(AdminProductService::class, $adminService);

        $adminProducts = new AdminProducts(self::$pluginFile, static fn (): array => [
            'routes' => [
                'provider' => '/admin/provider',
                'provider_credential' => '/admin/provider/credential',
                'provider_readiness' => '/admin/provider/readiness',
            ],
        ], defined('VEYRA_VERSION') ? VEYRA_VERSION : '0.1.7');

        $aiFeature = new FeatureKey('ai_semantic_orchestration');
        $customerExperience = null;
        if ($compatibility->commerceReady() && $featureGate->allows($aiFeature)) {
            $publishedAgent = get_option('veyra_agent_published_v1', []);
            $publishedAgent = is_array($publishedAgent) ? $publishedAgent : [];
            $aiName = self::boundedPublishedText($publishedAgent['public_name'] ?? null, 'Veyra', 80);
            $defaultDisclosure = function_exists('__')
                ? __('AI shopping assistant. Store staff may review retained conversations.', 'veyra-ai-commerce-agent')
                : 'AI shopping assistant. Store staff may review retained conversations.';
            $aiDisclosure = self::boundedPublishedText(
                $publishedAgent['disclosure_text'] ?? null,
                $defaultDisclosure,
                240
            );
            $customerExperience = new CustomerExperience(self::$pluginFile, static function () use ($aiName, $aiDisclosure): array {
                return [
                    'enabled' => true,
                    'mount_launcher' => true,
                    'ai_name' => $aiName,
                    'ai_disclosure' => $aiDisclosure,
                    'actor_scope' => function_exists('is_user_logged_in') && is_user_logged_in()
                        ? 'customer:' . (string) get_current_user_id()
                        : 'guest',
                    'capabilities' => [
                        'new_conversation' => true,
                        'stop_response' => false,
                        'quick_replies' => true,
                        'product_references' => true,
                    ],
                ];
            }, defined('VEYRA_VERSION') ? VEYRA_VERSION : '0.1.7');
        }

        // Hook registration is the activation boundary. Nothing above this
        // point publishes an endpoint, shortcode, launcher, or account-linking
        // callback, so failed composition cannot leave a partial public surface.
        add_action('init', $linkGuestAccount, 1);
        $adminRest->register();
        $adminProducts->register();
        if ($customerExperience instanceof CustomerExperience) {
            $chat->register();
            $customerExperience->register();
        }
    }

    private static function boundedPublishedText(mixed $value, string $fallback, int $maximum): string
    {
        if (!is_string($value)) {
            return $fallback;
        }
        $value = trim($value);
        if ($value === '' || preg_match('//u', $value) !== 1) {
            return $fallback;
        }
        if (strlen($value) <= $maximum) {
            return $value;
        }
        $bounded = substr($value, 0, $maximum);
        while ($bounded !== '' && preg_match('//u', $bounded) !== 1) {
            $bounded = substr($bounded, 0, -1);
        }
        $bounded = rtrim($bounded);
        return $bounded !== '' ? $bounded : $fallback;
    }

    private static function configureRuntimeFeatures(
        RuntimeFeatureRegistry $runtime,
        ProviderReadinessService $readiness,
        ProviderReleaseGate $releaseGate,
        CompatibilityReport $compatibility
    ): void {
        $requiredSchema = defined('VEYRA_SCHEMA_VERSION') ? (string) VEYRA_SCHEMA_VERSION : '1.6.0';
        $schemaReady = (string) get_option(Migrator::SCHEMA_OPTION, '0.0.0') === $requiredSchema;
        $provider = $readiness->current();
        $releaseDecision = $releaseGate->decision($provider);
        foreach (self::runtimeFeaturePlan($schemaReady, $releaseDecision, $compatibility) as $feature => $availability) {
            $runtime->register(new FeatureKey($feature), $availability['state'], $availability['reason']);
        }
    }

    /**
     * Pure effective-runtime plan used by composition and deterministic tests.
     *
     * @param array{allowed?:mixed,reason_code?:mixed} $releaseDecision
     * @return array<string, array{state:FeatureState,reason:string}>
     */
    private static function runtimeFeaturePlan(
        bool $schemaReady,
        array $releaseDecision,
        CompatibilityReport $compatibility
    ): array {
        $releaseAllowed = ($releaseDecision['allowed'] ?? false) === true;
        $releaseReason = is_string($releaseDecision['reason_code'] ?? null)
            && $releaseDecision['reason_code'] !== ''
                ? $releaseDecision['reason_code']
                : 'provider_release_decision_invalid';
        $commerceReady = $compatibility->commerceReady();
        $providerReady = $schemaReady && $commerceReady && $releaseAllowed;
        $providerReason = !$schemaReady
            ? 'schema_migration_required'
            : (!$commerceReady
                ? self::commerceCompatibilityReason($compatibility)
                : ($providerReady ? 'runtime_ready' : $releaseReason));

        $plan = [];
        foreach (['ai_semantic_orchestration', 'ai_context_graph', 'ai_conversation_memory', 'ai_conversation_focus'] as $feature) {
            $plan[$feature] = [
                'state' => $providerReady ? FeatureState::On : FeatureState::Blocked,
                'reason' => $providerReason,
            ];
        }

        $plan += [
            'ai_time_awareness' => ['state' => FeatureState::On, 'reason' => 'runtime_clock_available'],
            'ai_merchant_knowledge' => ['state' => FeatureState::Degraded, 'reason' => 'published_source_required'],
            'commerce_product_assistance' => ['state' => FeatureState::Degraded, 'reason' => 'adapter_dependent_tools_may_be_unavailable'],
            'commerce_cart' => ['state' => FeatureState::Degraded, 'reason' => 'confirmed_clear_not_published'],
            'commerce_chat_checkout' => ['state' => FeatureState::Degraded, 'reason' => 'order_placement_not_published'],
            'commerce_order_service' => ['state' => FeatureState::Degraded, 'reason' => 'sensitive_order_actions_not_published'],
            'ai_human_handoff' => ['state' => FeatureState::Degraded, 'reason' => 'human_handoff_submission_not_published'],
            'service_crm' => ['state' => FeatureState::Degraded, 'reason' => 'case_submission_not_published'],
            'payment_offline_review' => ['state' => FeatureState::Blocked, 'reason' => 'review_confirmation_and_submission_not_certified'],
            'ai_multimodal_understanding' => ['state' => FeatureState::Blocked, 'reason' => 'protected_media_pipeline_not_certified'],
            'operations_human_console' => ['state' => FeatureState::Degraded, 'reason' => 'workflow_decision_console_not_published'],
            'chat_message_quoting' => ['state' => FeatureState::On, 'reason' => 'runtime_ready'],
            'chat_product_references' => ['state' => FeatureState::On, 'reason' => 'runtime_ready'],
        ];

        if (!$commerceReady) {
            $reason = self::commerceCompatibilityReason($compatibility);
            foreach (['commerce_product_assistance', 'commerce_cart', 'commerce_chat_checkout', 'commerce_order_service'] as $feature) {
                $plan[$feature] = ['state' => FeatureState::Blocked, 'reason' => $reason];
            }
        }

        return $plan;
    }

    private static function commerceCompatibilityReason(CompatibilityReport $compatibility): string
    {
        foreach ($compatibility->issues as $issue) {
            if ($issue->scope === 'woocommerce' || $issue->blocksFoundation) {
                return $issue->code;
            }
        }

        return 'woocommerce_compatibility_unavailable';
    }

    private static function blockRuntimeAfterCompositionFailure(Container $container): void
    {
        try {
            /** @var RuntimeFeatureRegistry $runtime */
            $runtime = $container->get(RuntimeFeatureRegistry::class);
            /** @var FeatureRegistry $features */
            $features = $container->get(FeatureRegistry::class);
            foreach ($features->all() as $definition) {
                $runtime->register(
                    $definition->key,
                    FeatureState::Blocked,
                    'runtime_module_composition_failed'
                );
            }
        } catch (\Throwable) {
            // The shared Plugin boundary records runtime_boot_failed. If even
            // the registries are unavailable, there is no runtime surface to
            // advertise and no safe secondary dependency to invoke here.
        }
    }

    private static function registerOptionalRuntimeHandlers(
        ToolRegistry $tools,
        RequirementStateService $requirements,
        IdempotencyService $idempotency,
        FoundationActorMapper $actorMapper,
        \wpdb $database,
        TableNames $tables,
        Clock $clock,
        LockManager $locks,
        AuditWriter $audit
    ): void {
        try {
            $checkoutStates = new WpdbCheckoutStateRepository($database);
            $checkoutSessions = new CheckoutSessionService($checkoutStates, $clock);
            $checkoutAuthority = new WooCommerceCheckoutAuthority();
            $tools->register(new CheckoutToolHandler(
                $checkoutSessions,
                $checkoutAuthority,
                $idempotency,
                $actorMapper,
                new CheckoutInputSanitizer(),
                $locks,
                $audit
            ));
        } catch (\Throwable) {
            // Fail closed: a partially composed checkout workflow is not exposed.
        }

        try {
            $knowledge = new KnowledgeService(new WordPressPublishedKnowledgeRepository(), $clock);
            $tools->register(new KnowledgeToolHandler($knowledge));
        } catch (\Throwable) {
            // Fail closed: unpublished or malformed knowledge never becomes evidence.
        }

        try {
            $tools->register(new RequirementsToolHandler($requirements));
        } catch (\Throwable) {
            // Fail closed: requirement memory remains unavailable rather than partial.
        }

        try {
            $recommendations = new RecommendationService(
                new WooCommerceProductCandidateRepository(),
                new WordPressPublishedRecommendationPolicyRepository()
            );
            $tools->register(new RecommendationToolHandler($recommendations, $requirements));
        } catch (\Throwable) {
            // Fail closed: partial or unpublished ranking policy is never exposed.
        }

        try {
            $cases = new WpdbCaseRepository($database, $tables);
            $tools->register(new CrmToolHandler($cases, $idempotency, $actorMapper));
        } catch (\Throwable) {
            // Fail closed: draft case tools are not exposed without owned persistence.
        }

        try {
            $attachments = new WpdbAttachmentRepository($database, $tables);
            $tools->register(new MediaToolHandler($attachments, $actorMapper));
        } catch (\Throwable) {
            // Fail closed: protected-media metadata is absent if persistence
            // cannot be composed. Raw bytes are never a model tool.
        }

        try {
            $attachments = new WpdbAttachmentRepository($database, $tables);
            $gatewayIds = get_option('veyra_payment_review_gateway_ids', []);
            $gatewayIds = is_array($gatewayIds)
                ? array_values(array_unique(array_filter(array_map('sanitize_key', $gatewayIds))))
                : [];
            $reviewService = new PaymentReviewService(
                new WpdbPaymentReviewRepository($database, $tables),
                $attachments,
                new WooCommercePaymentReviewAuthority($gatewayIds),
                $actorMapper,
                $clock
            );
            $tools->register(new PaymentReviewToolHandler($reviewService, $idempotency, $actorMapper, $locks));
        } catch (\Throwable) {
            // Fail closed: draft review tooling is absent until every owned
            // persistence/authority dependency is available. Feature remains
            // Blocked until its confirmation and operations path is certified.
        }
    }
}
