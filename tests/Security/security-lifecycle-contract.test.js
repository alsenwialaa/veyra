'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '../..');
const source = (file) => fs.readFileSync(path.join(root, file), 'utf8');

test('sensitive REST adapters are explicitly authorized and write-idempotent', () => {
  const admin = source('src/Operations/Presentation/AdminRestController.php');
  const media = source('src/Media/Presentation/MediaRestController.php');
  for (const adapter of [admin, media]) {
    assert.doesNotMatch(adapter, /__return_true/);
    assert.match(adapter, /'permission_callback'/);
    assert.match(adapter, /Cache-Control/);
  }
  assert.match(admin, /IdempotencyService \$idempotency/);
  assert.match(admin, /get_header\('Idempotency-Key'\)/);
  assert.match(admin, /\$this->idempotency->begin/);
  assert.match(admin, /\$this->idempotency->(?:complete|fail)/);
  assert.match(media, /X-WP-Nonce/);
  assert.match(media, /X-Veyra-CSRF/);
  assert.match(media, /Idempotency-Key/);
});

test('protected media stays actor-owned and has no public storage fallback', () => {
  const media = source('src/Media/Presentation/MediaRestController.php');
  const access = source('src/Media/Application/ProtectedAttachmentAccessService.php');
  const factory = source('src/Media/Infrastructure/ProtectedStorageFactory.php');
  const uploads = source('src/Media/Application/ProtectedUploadService.php');
  const lifecycle = source('src/Bootstrap/SecurityLifecycleModule.php');
  assert.match(media, /ActorType::Guest, ActorType::Customer/);
  assert.match(media, /attachment_not_owned_or_unavailable/);
  assert.match(access, /hash_equals\(\$context->conversationId, \$attachment->conversationId\)/);
  assert.match(access, /attachment_integrity_verification_failed/);
  assert.match(access, /hash_equals\(\$expectedChecksum, \$checksum\)/);
  assert.match(factory, /VEYRA_PROTECTED_STORAGE_PATH/);
  assert.match(factory, /VEYRA_PROTECTED_MEDIA_RETENTION_SECONDS/);
  assert.match(factory, /public static function retentionSeconds\(\): \?int/);
  assert.match(factory, /'retention' => \$retention === null \? 'Blocked' : 'Available'/);
  assert.doesNotMatch(uploads, /retentionSeconds\s*=\s*2592000/);
  assert.match(lifecycle, /ProtectedStorageFactory::retentionSeconds\(\)/);
  assert.match(lifecycle, /\$retentionSeconds === null/);
  assert.match(factory, /WP_CONTENT_DIR/);
  assert.match(factory, /\$_SERVER\['DOCUMENT_ROOT'\]/);
  assert.match(factory, /wp_upload_dir/);
  assert.doesNotMatch(media, /storageKey|storage_key|public_url/);
});

test('privacy, retention, deactivation and uninstall remain capability-gated and fail-safe', () => {
  const privacy = source('src/Privacy/WordPressPrivacyIntegration.php');
  const retention = source('src/Privacy/RetentionService.php');
  const activator = source('src/Bootstrap/Activator.php');
  const deactivator = source('src/Bootstrap/Deactivator.php');
  const uninstaller = source('src/Bootstrap/Uninstaller.php');
  assert.match(privacy, /wp_privacy_personal_data_exporters/);
  assert.match(privacy, /wp_privacy_personal_data_erasers/);
  assert.match(privacy, /export_veyra_conversations/);
  assert.match(privacy, /erase_veyra_data/);
  assert.match(privacy, /writeRequired/);
  assert.doesNotMatch(privacy, /\{\$this->[^}]*\(/, 'SQL must not interpolate method calls');
  assert.match(privacy, /erasableRemainingCount/);
  assert.match(privacy, /array\|\\WP_Error/);
  assert.match(privacy, /veyra_privacy_export_not_authorized/);
  assert.match(privacy, /veyra_privacy_erasure_not_authorized/);
  assert.doesNotMatch(privacy, /return \['data' => \[\], 'done' => true\]/);
  assert.match(privacy, /private function retainedCount\(ActorScope \$scope\): \?int/);
  assert.match(privacy, /\$done = !\$erasureFailure && \$remaining === 0/);
  assert.match(privacy, /invalid-json-not-exported/);
  assert.match(privacy, /Customer-export allowlist/);
  assert.match(privacy, /'context-bundle-manifests'/);
  assert.match(privacy, /'conversation-focus'[^\n]*unresolved_references_json/);
  assert.match(privacy, /AND legal_hold = 0 LIMIT %d/);
  assert.match(privacy, /AND legal_hold = 1/);
  assert.match(privacy, /NOT EXISTS \([\s\S]*paymentReviews/);
  assert.match(privacy, /LOCATE\(CONCAT\(CHAR\(34\), a\.public_id, CHAR\(34\)\), r\.evidence_json\) > 0/);
  assert.match(privacy, /LOCATE\(CONCAT\(CHAR\(34\), a\.public_id, CHAR\(34\)\), c\.request_json\) > 0/);
  assert.doesNotMatch(privacy, /'columns' => '[^']*(?:memory_json|summary_json|decision_json|execution_json|transition_json)/);
  assert.match(retention, /ProtectedObjectEraser/);
  assert.doesNotMatch(retention, /\{\$this->[^}]*\(/, 'SQL must not interpolate method calls');
  assert.match(retention, /scan_status = CASE WHEN scan_status = %s THEN %s ELSE scan_status END/);
  assert.match(retention, /'clean',\s*'unavailable'/);
  assert.match(retention, /retention_expires_at IS NOT NULL/);
  assert.match(retention, /AND legal_hold = 0/);
  assert.match(activator, /wp_schedule_event\(time\(\) \+ 300, 'daily', 'veyra_retention'\)/);
  assert.match(deactivator, /wp_clear_scheduled_hook/);
  assert.match(deactivator, /as_unschedule_all_actions/);
  assert.match(deactivator, /as_has_scheduled_action/);
  assert.match(deactivator, /plugin_deactivated/);
  assert.match(deactivator, /plugin_deactivated_during_execution/);
  assert.ok(
    deactivator.indexOf("'uncertain'") < deactivator.indexOf("prepare('DELETE FROM %i'"),
    'in-progress mutations must become uncertain before runtime locks are released'
  );
  assert.ok(uninstaller.indexOf('deleteProtectedObjects') < uninstaller.indexOf('DROP TABLE IF EXISTS'));
  assert.match(uninstaller, /prepare\('DROP TABLE IF EXISTS %i', \$table\)[\s\S]*tableExists\(\$wpdb, \$table\) !== false/);
  assert.doesNotMatch(uninstaller, /DROP TABLE IF EXISTS \{\$table\}/, 'uninstall identifiers must use the WordPress %i placeholder');
  assert.match(uninstaller, /if \(!self::deleteOptions\(\)\)[\s\S]*return/);
  assert.match(uninstaller, /get_option\(\$option, \$missing\) !== \$missing/);
  assert.doesNotMatch(
    uninstaller,
    /Deactivator::deactivate\(\);\s*RoleCapabilityInstaller::removeFromAllRoles\(\);/,
    'uninstall must not remove recovery capabilities before choosing and completing a data disposition'
  );
  assert.ok(
    uninstaller.lastIndexOf('RoleCapabilityInstaller::removeFromAllRoles()')
      > uninstaller.indexOf('DROP TABLE IF EXISTS'),
    'authorized deletion must finish before recovery capabilities are removed'
  );
  assert.match(uninstaller, /deletionEnabled/);
});

test('security lifecycle wiring and staff/customer separation are explicit', () => {
  const plugin = source('veyra-ai-commerce-agent.php');
  const bootstrap = source('src/Bootstrap/Plugin.php');
  const activator = source('src/Bootstrap/Activator.php');
  const module = source('src/Bootstrap/SecurityLifecycleModule.php');
  const runtime = source('src/Runtime/RuntimeModule.php');
  const gate = source('src/Identity/Presentation/RestPermissionGate.php');
  const cookieManager = source('src/Identity/Infrastructure/GuestCookieManager.php');
  const actorResolver = source('src/Identity/Infrastructure/WordPressActorResolver.php');
  const customer = source('src/Experience/Presentation/CustomerExperience.php');
  assert.match(plugin, /SecurityLifecycleModule::register/);
  assert.match(
    bootstrap,
    /try \{[\s\S]*FoundationFactory::createContainer[\s\S]*do_action\('veyra_register_services'[\s\S]*\} catch \(\\Throwable\)/,
    'runtime composition must be contained at the bootstrap boundary'
  );
  assert.match(bootstrap, /runtime_boot_failed/);
  assert.match(bootstrap, /Activator::scheduleMigrationResume\(\$requiredSchema\)/);
  assert.doesNotMatch(bootstrap, /Activator::resumeMigrations\(\)/);
  assert.match(activator, /schema_version_incompatible/);
  assert.match(module, /ProtectedStorageFactory::storage/);
  assert.match(module, /ProtectedStorageFactory::scanner/);
  assert.match(module, /self::registerHooks\(\$privacy, \$retention, \$media\)/);
  assert.match(module, /catch \(\\Throwable \$error\)[\s\S]*remove_filter\([\s\S]*remove_action\([\s\S]*throw \$error/);
  assert.match(module, /get_option\(Migrator::SCHEMA_OPTION, '0\.0\.0'\)[\s\S]*!==[\s\S]*VEYRA_SCHEMA_VERSION/);
  assert.doesNotMatch(module, /version_compare/, 'security lifecycle must reject both older and unknown newer schemas');
  assert.match(gate, /ActorType::Guest, ActorType::Customer/);
  assert.match(cookieManager, /\$rawToken = wp_unslash\(\$_COOKIE\[GuestSessionManager::COOKIE_NAME\]\)/);
  assert.match(cookieManager, /sanitize_text_field\(\$rawToken\)/);
  assert.match(cookieManager, /hash_equals\(\$rawToken, \$token\)/);
  assert.match(cookieManager, /\^\[A-Za-z0-9_-\]\{32,192\}\$/);
  assert.match(gate, /GuestCookieManager::readSessionToken\(\)/);
  assert.match(actorResolver, /GuestCookieManager::readSessionToken\(\)/);
  assert.doesNotMatch(gate, /\$_COOKIE/, 'permission gates must use the centralized sanitized cookie boundary');
  assert.doesNotMatch(actorResolver, /\$_COOKIE/, 'actor resolution must use the centralized sanitized cookie boundary');
  assert.match(customer, /staff_blocked/);
  assert.match(runtime, /if \(\$compatibility->commerceReady\(\) && \$featureGate->allows\(\$aiFeature\)\)/);
  assert.ok(
    runtime.indexOf('$featureGate->allows($aiFeature)') < runtime.indexOf('$chat->register()'),
    'the effective AI gate must run before public chat routes are registered'
  );
  assert.match(runtime, /get_option\('veyra_agent_published_v1', \[\]\)/);
  assert.match(bootstrap, /add_action\('init', array\(\$this, 'loadTranslations'\), 0\)/);
  assert.match(bootstrap, /load_plugin_textdomain\(/);
  assert.match(runtime, /'ai_name' => \$aiName/);
  assert.match(runtime, /'ai_disclosure' => \$aiDisclosure/);
  assert.match(runtime, /'customer:' \. \(string\) get_current_user_id\(\)/);
  assert.doesNotMatch(runtime, /\? 'customer' : 'guest'/, 'customer draft storage must be account scoped');
  const guestLink = source('src/Identity/Application/GuestAccountLinkService.php');
  assert.match(guestLink, /contextBundleManifests\(\)/);
  assert.match(guestLink, /context_bundle_manifests/);
});

test('audit metadata denylist includes banking and payment identifiers', () => {
  const audit = source('src/Audit/Application/SafeAuditMetadata.php');
  for (const identifier of [
    'iban',
    'swift',
    'bic',
    'account_',
    'bank',
    'routing_',
    'sort_code',
    'payment_account',
  ]) {
    assert.match(audit, new RegExp(identifier));
  }
});

test('actor-scoped persistence preserves database NULL semantics', () => {
  const repository = source('src/Infrastructure/Database/Repository/ActorScopedRepository.php');
  assert.match(repository, /if \(\$value === null\)/);
  assert.match(repository, /\$set\[\] = "\{\$column\} = NULL"/);
});

test('browser mutation identifiers fail closed without Web Crypto', () => {
  const customer = source('assets/customer/veyra-chat.js');
  const admin = source('assets/admin/veyra-admin.js');
  for (const client of [customer, admin]) {
    assert.doesNotMatch(client, /Math\.random\(\)/);
    assert.doesNotMatch(client, /Date\.now\(\)\.toString\(36\)/);
    assert.match(client, /Secure browser randomness is unavailable/);
  }
  assert.ok(
    customer.indexOf("client_message_id: this.newOpaqueId('msg')")
      < customer.indexOf('this.pendingCommands.set(command.client_message_id, command)'),
    'a shopper command gets a strong identifier before it can enter the send path'
  );
});
