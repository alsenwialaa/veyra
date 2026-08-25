<?php

declare(strict_types=1);

namespace Veyra\Bootstrap;

use Veyra\Audit\Application\AuditReader;
use Veyra\Audit\Application\AuditRepository;
use Veyra\Audit\Application\AuditWriter;
use Veyra\Audit\Infrastructure\WpdbAuditRepository;
use Veyra\Confirmation\Application\ConfirmationRepository;
use Veyra\Confirmation\Application\ConfirmationService;
use Veyra\Confirmation\Application\IdempotencyRepository;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Application\LockManager;
use Veyra\Confirmation\Application\LockRepository;
use Veyra\Confirmation\Application\SensitiveActionGate;
use Veyra\Confirmation\Infrastructure\WpdbConfirmationRepository;
use Veyra\Confirmation\Infrastructure\WpdbIdempotencyRepository;
use Veyra\Confirmation\Infrastructure\WpdbLockRepository;
use Veyra\Features\Application\EffectiveFeatureStateService;
use Veyra\Features\Application\FeatureConfigurationStore;
use Veyra\Features\Application\FeatureGate;
use Veyra\Features\Application\RuntimeFeatureRegistry;
use Veyra\Features\Domain\FeatureRegistry;
use Veyra\Features\Infrastructure\WordPressFeatureConfigurationStore;
use Veyra\Identity\Application\ActorResolver;
use Veyra\Identity\Application\CapabilityPolicy;
use Veyra\Identity\Application\GuestSessionManager;
use Veyra\Identity\Application\GuestAccountLinkService;
use Veyra\Identity\Application\GuestSessionRepository;
use Veyra\Identity\Application\OwnershipPolicy;
use Veyra\Identity\Infrastructure\GuestCookieManager;
use Veyra\Identity\Infrastructure\WordPressActorResolver;
use Veyra\Identity\Infrastructure\WpdbGuestSessionRepository;
use Veyra\Infrastructure\Clock\SystemClock;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Infrastructure\Database\WpdbTransactionManager;
use Veyra\Shared\Domain\Clock;
use Veyra\Shared\Domain\SecretDigester;

final class FoundationFactory
{
    public static function createContainer(CompatibilityReport $compatibility): Container
    {
        global $wpdb;

        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('WordPress database adapter is unavailable.');
        }

        $container = new Container();
        $tables = new TableNames($wpdb->prefix);
        $clock = new SystemClock();
        $digestKey = function_exists('wp_salt') ? wp_salt('auth') : '';

        if (!is_string($digestKey) || strlen($digestKey) < 16) {
            throw new \RuntimeException('WordPress authentication salts are unavailable.');
        }

        $digester = new SecretDigester($digestKey);
        $guestRepository = new WpdbGuestSessionRepository($wpdb, $tables);
        $guestSessions = new GuestSessionManager($guestRepository, $digester, $clock);
        $capabilities = new CapabilityPolicy();
        $guestCookies = new GuestCookieManager();
        $actorResolver = new WordPressActorResolver($guestSessions, $guestCookies);
        $featureRegistry = FeatureRegistry::canonical();
        $featureConfiguration = new WordPressFeatureConfigurationStore();
        $runtimeFeatures = new RuntimeFeatureRegistry();
        $effectiveFeatures = new EffectiveFeatureStateService(
            $featureRegistry,
            $featureConfiguration,
            $runtimeFeatures
        );
        $featureGate = new FeatureGate($effectiveFeatures);
        $auditRepository = new WpdbAuditRepository($wpdb, $tables);
        $auditWriter = new AuditWriter($auditRepository, $clock);
        $confirmationRepository = new WpdbConfirmationRepository($wpdb, $tables);
        $confirmationService = new ConfirmationService($confirmationRepository, $digester, $clock);
        $idempotencyRepository = new WpdbIdempotencyRepository($wpdb, $tables);
        $idempotencyService = new IdempotencyService($idempotencyRepository, $digester, $clock);
        $lockRepository = new WpdbLockRepository($wpdb, $tables);
        $transactions = new WpdbTransactionManager($wpdb);
        $guestAccountLinks = new GuestAccountLinkService(
            $guestSessions,
            $wpdb,
            $tables,
            $transactions,
            $auditWriter,
            $clock
        );

        $container->set(CompatibilityReport::class, $compatibility);
        $container->set(\wpdb::class, $wpdb);
        $container->set(TableNames::class, $tables);
        $container->set(Clock::class, $clock);
        $container->set(SecretDigester::class, $digester);
        $container->set(GuestSessionRepository::class, $guestRepository);
        $container->set(GuestSessionManager::class, $guestSessions);
        $container->set(GuestCookieManager::class, $guestCookies);
        $container->set(GuestAccountLinkService::class, $guestAccountLinks);
        $container->set(CapabilityPolicy::class, $capabilities);
        $container->set(ActorResolver::class, $actorResolver);
        $container->set(OwnershipPolicy::class, new OwnershipPolicy([]));
        $container->set(FeatureRegistry::class, $featureRegistry);
        $container->set(FeatureConfigurationStore::class, $featureConfiguration);
        $container->set(RuntimeFeatureRegistry::class, $runtimeFeatures);
        $container->set(EffectiveFeatureStateService::class, $effectiveFeatures);
        $container->set(FeatureGate::class, $featureGate);
        $container->set(AuditRepository::class, $auditRepository);
        $container->set(AuditWriter::class, $auditWriter);
        $container->set(AuditReader::class, new AuditReader($auditRepository, $capabilities));
        $container->set(ConfirmationRepository::class, $confirmationRepository);
        $container->set(ConfirmationService::class, $confirmationService);
        $container->set(IdempotencyRepository::class, $idempotencyRepository);
        $container->set(IdempotencyService::class, $idempotencyService);
        $container->set(LockRepository::class, $lockRepository);
        $container->set(LockManager::class, new LockManager($lockRepository, $digester, $clock));
        $container->set(WpdbTransactionManager::class, $transactions);
        $container->set(
            SensitiveActionGate::class,
            new SensitiveActionGate(
                $transactions,
                $confirmationService,
                $idempotencyService,
                $auditWriter
            )
        );

        return $container;
    }
}
