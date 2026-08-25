<?php

declare(strict_types=1);

namespace Veyra\Bootstrap;

use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContextFactory;
use Veyra\Audit\Application\AuditWriter;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Application\LockManager;
use Veyra\Conversation\Application\ConversationStore;
use Veyra\Features\Application\FeatureGate;
use Veyra\Http\RateLimiter;
use Veyra\Identity\Application\ActorResolver;
use Veyra\Identity\Application\GuestSessionManager;
use Veyra\Infrastructure\Database\Migration\Migrator;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Media\Application\ProtectedAttachmentAccessService;
use Veyra\Media\Application\ProtectedUploadService;
use Veyra\Media\Infrastructure\GdImageReencoder;
use Veyra\Media\Infrastructure\MagicByteFileValidator;
use Veyra\Media\Infrastructure\ProtectedObjectEraser;
use Veyra\Media\Infrastructure\ProtectedStorageFactory;
use Veyra\Media\Infrastructure\WpdbAttachmentRepository;
use Veyra\Media\Presentation\MediaRestController;
use Veyra\Privacy\RetentionService;
use Veyra\Privacy\WordPressPrivacyIntegration;
use Veyra\Shared\Domain\Clock;

/** Independently gated security, privacy, protected-media and retention wiring. */
final class SecurityLifecycleModule
{
    public static function register(string $pluginFile): void
    {
        unset($pluginFile);
        add_action('veyra_register_services', [self::class, 'registerServices'], 20, 2);
    }

    public static function registerServices(Container $container, CompatibilityReport $compatibility): void
    {
        try {
            /** @var \wpdb $database */
            $database = $container->get(\wpdb::class);
            /** @var TableNames $tables */
            $tables = $container->get(TableNames::class);
            /** @var Clock $clock */
            $clock = $container->get(Clock::class);
            /** @var ActorResolver $actors */
            $actors = $container->get(ActorResolver::class);
            /** @var AuditWriter $audit */
            $audit = $container->get(AuditWriter::class);
        } catch (\Throwable) {
            return;
        }

        $eraser = new ProtectedObjectEraser();
        $privacy = new WordPressPrivacyIntegration($database, $tables, $actors, $audit, $eraser);
        $retention = new RetentionService($database, $tables, $eraser);

        if (!$compatibility->commerceReady()
            || (string) get_option(Migrator::SCHEMA_OPTION, '0.0.0')
                !== (defined('VEYRA_SCHEMA_VERSION') ? (string) VEYRA_SCHEMA_VERSION : '1.6.0')
        ) {
            self::registerHooks($privacy, $retention, null);
            return;
        }

        $required = [
            GuestSessionManager::class,
            FeatureGate::class,
            ToolContextFactory::class,
            ConversationStore::class,
            IdempotencyService::class,
            LockManager::class,
            RateLimiter::class,
        ];
        foreach ($required as $service) {
            if (!$container->has($service)) {
                self::registerHooks($privacy, $retention, null);
                return;
            }
        }

        $storage = ProtectedStorageFactory::storage();
        $scanner = ProtectedStorageFactory::scanner();
        $retentionSeconds = ProtectedStorageFactory::retentionSeconds();
        if ($storage === null || $scanner === null || $retentionSeconds === null) {
            self::registerHooks($privacy, $retention, null);
            return;
        }

        /** @var GuestSessionManager $guestSessions */
        $guestSessions = $container->get(GuestSessionManager::class);
        /** @var FeatureGate $features */
        $features = $container->get(FeatureGate::class);
        /** @var ToolContextFactory $contexts */
        $contexts = $container->get(ToolContextFactory::class);
        /** @var ConversationStore $conversations */
        $conversations = $container->get(ConversationStore::class);
        /** @var IdempotencyService $idempotency */
        $idempotency = $container->get(IdempotencyService::class);
        /** @var LockManager $locks */
        $locks = $container->get(LockManager::class);
        /** @var RateLimiter $rateLimiter */
        $rateLimiter = $container->get(RateLimiter::class);
        $attachments = new WpdbAttachmentRepository($database, $tables);
        $actorMapper = new FoundationActorMapper();
        $uploads = new ProtectedUploadService(
            new MagicByteFileValidator(),
            new GdImageReencoder(sys_get_temp_dir()),
            $scanner,
            $storage,
            $attachments,
            $idempotency,
            $actorMapper,
            $clock,
            $conversations,
            $locks,
            $retentionSeconds
        );
        $access = new ProtectedAttachmentAccessService($attachments, $storage, $actorMapper, $clock);
        $media = new MediaRestController(
            $actors,
            $guestSessions,
            $features,
            $contexts,
            $uploads,
            $access,
            $rateLimiter,
            $clock
        );

        self::registerHooks($privacy, $retention, $media);
    }

    /**
     * Registration happens only after every selected service has composed.
     * If a WordPress hook API or controller registration throws, remove every
     * callback from this module before allowing Plugin's request-safe boundary
     * to health-block the failed boot.
     */
    private static function registerHooks(
        WordPressPrivacyIntegration $privacy,
        RetentionService $retention,
        ?MediaRestController $media
    ): void {
        $retentionCallback = static function () use ($retention): void {
            $retention->run();
        };

        try {
            $privacy->register();
            add_action('veyra_retention', $retentionCallback);
            $media?->register();
        } catch (\Throwable $error) {
            if (function_exists('remove_filter')) {
                remove_filter('wp_privacy_personal_data_exporters', [$privacy, 'registerExporter']);
                remove_filter('wp_privacy_personal_data_erasers', [$privacy, 'registerEraser']);
                if ($media !== null) {
                    remove_filter('rest_pre_serve_request', [$media, 'serveProtectedStream'], 10);
                }
            }
            if (function_exists('remove_action')) {
                remove_action('veyra_retention', $retentionCallback);
                if ($media !== null) {
                    remove_action('rest_api_init', [$media, 'registerRoutes']);
                }
            }

            throw $error;
        }
    }
}
