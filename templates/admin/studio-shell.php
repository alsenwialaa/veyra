<?php
/** @var string $veyraProduct */
/** @var array{title:string,menu:string,capability:string,description:string} $veyraDefinition */
defined('ABSPATH') || exit;

$veyraIsOperations = $veyraProduct === 'operations';
$veyraTitleId = 'veyra-' . $veyraProduct . '-title';
?>
<div
    class="wrap veyra-admin"
    data-veyra-admin-shell
    data-product="<?php echo esc_attr($veyraProduct); ?>"
    data-required-capability="<?php echo esc_attr($veyraDefinition['capability']); ?>"
    data-mode="<?php echo $veyraIsOperations ? 'read-only' : 'publication'; ?>"
    dir="<?php echo function_exists('is_rtl') && is_rtl() ? 'rtl' : 'ltr'; ?>"
>
    <header class="veyra-admin__masthead">
        <div>
            <p class="veyra-admin__eyebrow">Veyra administration</p>
            <h1 id="<?php echo esc_attr($veyraTitleId); ?>"><?php echo esc_html($veyraDefinition['title']); ?></h1>
            <p class="veyra-admin__lede"><?php echo esc_html($veyraDefinition['description']); ?></p>
        </div>
        <div class="veyra-admin__version-state" aria-label="Configuration state">
            <span class="veyra-admin__state" data-admin-state>Loading</span>
            <span data-admin-version>—</span>
        </div>
    </header>

    <div class="veyra-admin__notice" data-admin-live role="status" aria-live="polite" aria-atomic="true">
        Loading authoritative state…
    </div>
    <div class="veyra-admin__alert" data-admin-error role="alert" aria-live="assertive" hidden></div>

    <div class="veyra-admin__layout">
        <main class="veyra-admin__workspace" aria-labelledby="<?php echo esc_attr($veyraTitleId); ?>">
            <?php if ($veyraProduct === 'agent') : ?>
                <form data-admin-form class="veyra-admin__form" autocomplete="off">
                    <fieldset disabled data-admin-fieldset>
                        <legend>Public identity</legend>
                        <div class="veyra-admin__field-grid">
                            <label>
                                <span>Public AI name</span>
                                <input type="text" data-field="public_name" maxlength="80" required>
                            </label>
                            <label>
                                <span>Default language</span>
                                <select data-field="default_language">
                                    <option value="ar">Arabic</option>
                                    <option value="en">English</option>
                                    <option value="auto">Shopper language</option>
                                </select>
                            </label>
                            <label class="veyra-admin__field--wide">
                                <span>AI disclosure</span>
                                <input type="text" data-field="disclosure_text" maxlength="240" required>
                                <small>The responder must always remain clearly identified as AI.</small>
                            </label>
                        </div>
                    </fieldset>
                    <fieldset disabled data-admin-fieldset>
                        <legend>Sales behavior</legend>
                        <div class="veyra-admin__field-grid">
                            <label><span>Formality</span><select data-field="formality"><option value="casual">Casual</option><option value="balanced">Balanced</option><option value="formal">Formal</option></select></label>
                            <label><span>Response length</span><select data-field="response_length"><option value="concise">Concise</option><option value="balanced">Balanced</option><option value="detailed">Detailed when needed</option></select></label>
                            <label><span>Recommendation limit</span><input type="number" data-field="recommendation_limit" min="1" max="8" step="1"></label>
                            <label><span>Handoff threshold</span><select data-field="handoff_threshold"><option value="early">Early</option><option value="balanced">Balanced</option><option value="only_when_needed">Only when needed</option></select></label>
                        </div>
                        <label class="veyra-admin__check"><input type="checkbox" data-field="respect_refusal"><span>Respect refusals and suppress repeated tactics</span></label>
                    </fieldset>
                    <section class="veyra-admin__panel" aria-labelledby="veyra-provider-title">
                        <div class="veyra-admin__panel-heading">
                            <div><h2 id="veyra-provider-title">Models &amp; Providers</h2><p>Provider routes, credentials, health, privacy, and fallback are separately capability-protected.</p></div>
                            <span class="veyra-admin__state" data-provider-health>Unavailable</span>
                        </div>
                        <dl class="veyra-admin__facts" data-provider-facts>
                            <div><dt>Default provider</dt><dd data-provider="primary">Not loaded</dd></div>
                            <div><dt>Effective route</dt><dd data-provider="route">Not loaded</dd></div>
                            <div><dt>Readiness</dt><dd data-provider="readiness">Not loaded</dd></div>
                            <div><dt>Last check</dt><dd data-provider="checked_at">Not loaded</dd></div>
                        </dl>
                        <?php if (current_user_can('manage_veyra_models')) : ?>
                            <div class="veyra-admin__credential" data-provider-controls>
                                <label>
                                    <span>Gemini API key</span>
                                    <input
                                        type="password"
                                        data-provider-secret
                                        autocomplete="new-password"
                                        spellcheck="false"
                                        maxlength="4096"
                                        aria-describedby="veyra-provider-secret-help"
                                    >
                                </label>
                                <p id="veyra-provider-secret-help" class="description">The key is sent only when you explicitly save it. It is never returned to or hydrated into this screen.</p>
                                <div class="veyra-admin__credential-actions">
                                    <button type="button" class="button button-primary" data-provider-action="save" disabled>Save credential</button>
                                    <button type="button" class="button" data-provider-action="clear" disabled>Clear credential</button>
                                    <button type="button" class="button" data-provider-action="readiness" disabled>Run readiness test</button>
                                </div>
                            </div>
                        <?php else : ?>
                            <p class="description">Credential and readiness controls require the separately granted <code>manage_veyra_models</code> capability.</p>
                        <?php endif; ?>
                    </section>
                </form>
            <?php elseif ($veyraProduct === 'knowledge') : ?>
                <form data-admin-form class="veyra-admin__form" autocomplete="off">
                    <fieldset disabled data-admin-fieldset>
                        <legend>Store and market context</legend>
                        <div class="veyra-admin__field-grid">
                            <label><span>Store display name</span><input type="text" data-field="store_name" maxlength="120"></label>
                            <label><span>Primary time zone</span><input type="text" data-field="timezone" placeholder="Asia/Aden" maxlength="64"></label>
                            <label><span>Primary market</span><input type="text" data-field="market" maxlength="80"></label>
                            <label><span>Currency</span><input type="text" data-field="currency" maxlength="3" dir="ltr"></label>
                        </div>
                    </fieldset>
                    <section class="veyra-admin__panel" aria-labelledby="veyra-sources-title">
                        <div class="veyra-admin__panel-heading"><div><h2 id="veyra-sources-title">Approved sources</h2><p>Saved content remains draft until validation, approval, indexing, and publication.</p></div><button type="button" class="button" data-admin-resource-action="new_source" disabled>Add source</button></div>
                        <div class="veyra-admin__resource-list" data-knowledge-sources aria-live="polite"><p>No authoritative source state loaded.</p></div>
                    </section>
                    <fieldset disabled data-admin-fieldset>
                        <legend>Conversation continuity</legend>
                        <p>Conversation Focus, active-journey state, current-conversation memory, and validated summaries are mandatory.</p>
                        <label class="veyra-admin__check"><input type="checkbox" data-field="durable_memory_enabled"><span>Enable optional durable preference memory only under published consent, category, retention, and customer-control policy</span></label>
                    </fieldset>
                </form>
            <?php elseif ($veyraProduct === 'experience') : ?>
                <form data-admin-form class="veyra-admin__form" autocomplete="off">
                    <fieldset disabled data-admin-fieldset>
                        <legend>Validated design tokens</legend>
                        <div class="veyra-admin__field-grid">
                            <label><span>Brand color</span><input type="text" data-field="tokens.colors.brand" pattern="#[0-9A-Fa-f]{6}" placeholder="#0C4A46" dir="ltr"></label>
                            <label><span>Accent color</span><input type="text" data-field="tokens.colors.accent" pattern="#[0-9A-Fa-f]{6}" placeholder="#B84720" dir="ltr"></label>
                            <label><span>Body type size (px)</span><input type="number" data-field="tokens.body_font_size_px" min="14" max="24"></label>
                            <label><span>Minimum touch target (px)</span><input type="number" data-field="tokens.minimum_touch_target_px" min="44" max="80"></label>
                        </div>
                    </fieldset>
                    <section class="veyra-admin__panel" aria-labelledby="veyra-truth-title">
                        <div class="veyra-admin__panel-heading"><div><h2 id="veyra-truth-title">Non-hideable truth</h2><p>Presentation may change. These facts and controls cannot be disabled by a theme or imported draft.</p></div><span class="veyra-admin__lock">Policy locked</span></div>
                        <ul class="veyra-admin__truth-grid" data-required-truths>
                            <?php
                            $veyraTruths = [
                                'product_identity' => 'Product identity',
                                'variation' => 'Exact variation',
                                'current_price' => 'Current price',
                                'current_total' => 'Current total',
                                'shipping' => 'Shipping information',
                                'tax' => 'Tax information',
                                'fees' => 'Fee information',
                                'required_terms' => 'Required terms',
                                'ai_identity' => 'AI identity',
                                'error_state' => 'Error state',
                                'permission_state' => 'Permission state',
                                'confirmation_scope' => 'Confirmation scope',
                                'payment_implications' => 'Payment implications',
                                'accessibility_controls' => 'Accessibility controls',
                            ];
                            foreach ($veyraTruths as $veyraTruthKey => $veyraTruthLabel) :
                                ?>
                                <li><span aria-hidden="true">✓</span><span><?php echo esc_html($veyraTruthLabel); ?></span><input type="hidden" data-required-truth="<?php echo esc_attr($veyraTruthKey); ?>" value="1"></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                    <section class="veyra-admin__panel" aria-labelledby="veyra-preview-title">
                        <div class="veyra-admin__panel-heading"><div><h2 id="veyra-preview-title">Representative previews</h2><p>Preview mobile, desktop, Arabic, English, RTL, blocked, stale, error, and success fixtures before publication.</p></div></div>
                        <div class="veyra-admin__fixture-grid" data-experience-fixtures><p>No validated preview fixtures loaded.</p></div>
                    </section>
                </form>
            <?php elseif ($veyraProduct === 'commerce') : ?>
                <form data-admin-form class="veyra-admin__form" autocomplete="off">
                    <section class="veyra-admin__panel" aria-labelledby="veyra-feature-title">
                        <div class="veyra-admin__panel-heading"><div><h2 id="veyra-feature-title">Feature effective state</h2><p>Configured state never overrides dependencies, capability, privacy, health, or safe fallback requirements.</p></div></div>
                        <div class="veyra-admin__table-wrap">
                            <table class="widefat striped veyra-admin__table">
                                <caption class="screen-reader-text">Veyra feature configured and effective states</caption>
                                <thead><tr><th scope="col">Feature</th><th scope="col">Configured</th><th scope="col">Effective</th><th scope="col">Reason and remediation</th></tr></thead>
                                <tbody data-feature-rows><tr><td colspan="4">No authoritative feature state loaded.</td></tr></tbody>
                            </table>
                        </div>
                    </section>
                </form>
            <?php else : ?>
                <section class="veyra-admin__operations" data-operations-view>
                    <div class="veyra-admin__health-grid" data-health-grid aria-live="polite">
                        <article class="veyra-admin__health-card"><h2>Provider &amp; AI</h2><p>Not loaded</p></article>
                        <article class="veyra-admin__health-card"><h2>WooCommerce</h2><p>Not loaded</p></article>
                        <article class="veyra-admin__health-card"><h2>Context &amp; memory</h2><p>Not loaded</p></article>
                        <article class="veyra-admin__health-card"><h2>Queues &amp; storage</h2><p>Not loaded</p></article>
                    </div>
                    <section class="veyra-admin__panel" aria-labelledby="veyra-work-title">
                        <div class="veyra-admin__panel-heading"><div><h2 id="veyra-work-title">Operational workload</h2><p>Viewing is read-only. Messaging, notes, decisions, execution, and verified results require separate capabilities and actions.</p></div><button type="button" class="button" data-admin-refresh>Refresh</button></div>
                        <div class="veyra-admin__work-grid">
                            <div><h3>Conversations &amp; handoffs</h3><div data-operation-resource="conversations"><p>Not loaded</p></div></div>
                            <div><h3>CRM cases</h3><div data-operation-resource="crm"><p>Not loaded</p></div></div>
                            <div><h3>Payment reviews</h3><div data-operation-resource="payment_reviews"><p>Not loaded</p></div></div>
                            <div><h3>Failures &amp; dead letters</h3><div data-operation-resource="failures"><p>Not loaded</p></div></div>
                        </div>
                    </section>
                </section>
            <?php endif; ?>
        </main>

        <?php if (!$veyraIsOperations) : ?>
            <aside class="veyra-admin__publication" aria-label="Draft and publication">
                <h2>Publication</h2>
                <dl class="veyra-admin__version-list">
                    <div><dt>Draft</dt><dd data-draft-version>Not loaded</dd></div>
                    <div><dt>Published</dt><dd data-published-version>Not loaded</dd></div>
                    <div><dt>Validation</dt><dd data-validation-state>Not run</dd></div>
                    <div><dt>Schedule</dt><dd data-schedule-state>None</dd></div>
                </dl>
                <p class="veyra-admin__dirty" data-dirty-state>Draft state is not loaded.</p>
                <div class="veyra-admin__action-stack">
                    <button type="button" class="button button-primary" data-admin-action="save_draft" disabled>Save draft</button>
                    <button type="button" class="button" data-admin-action="validate" disabled>Validate</button>
                    <button type="button" class="button" data-admin-action="simulate" disabled>Run simulation</button>
                    <button type="button" class="button" data-admin-action="publish" disabled>Publish</button>
                    <button type="button" class="button" data-admin-action="schedule" disabled>Schedule</button>
                    <button type="button" class="button" data-admin-action="rollback" disabled>Rollback</button>
                </div>
                <p class="description">Every action is capability-checked, validated, version-checked, and audited by the server. A button click is not evidence of success.</p>
            </aside>
        <?php endif; ?>
    </div>

    <dialog class="veyra-admin__dialog" data-admin-confirm aria-labelledby="veyra-admin-confirm-title">
        <form method="dialog">
            <h2 id="veyra-admin-confirm-title" data-admin-confirm-title>Confirm publication action</h2>
            <p data-admin-confirm-copy>This action has not run.</p>
            <label data-admin-schedule-field hidden><span>Activation time and zone</span><input type="datetime-local" data-admin-schedule-at></label>
            <div class="veyra-admin__dialog-actions">
                <button type="submit" class="button" value="cancel">Cancel</button>
                <button type="submit" class="button button-primary" value="confirm" data-admin-confirm-submit>Continue</button>
            </div>
        </form>
    </dialog>
</div>
