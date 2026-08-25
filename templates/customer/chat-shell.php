<?php
/**
 * Customer messaging shell.
 *
 * Expected variables are supplied by CustomerExperience. All dynamic history,
 * commerce state, and action outcomes come from versioned server payloads.
 */
defined('ABSPATH') || exit;

$veyraIsModal = in_array($veyraSurface, ['launcher', 'panel'], true);
$veyraPanelHidden = !$veyraOpen;
$veyraShowLauncher = $veyraSurface === 'launcher';
?>
<div
    id="<?php echo esc_attr($veyraInstanceId); ?>"
    class="veyra-chat veyra-chat--<?php echo esc_attr($veyraSurface); ?>"
    data-veyra-chat
    data-veyra-surface="<?php echo esc_attr($veyraSurface); ?>"
    dir="<?php echo esc_attr($veyraDirection); ?>"
>
    <button
        class="veyra-chat__launcher"
        type="button"
        data-veyra-open
        aria-controls="<?php echo esc_attr($veyraInstanceId); ?>-panel"
        aria-expanded="<?php echo $veyraPanelHidden ? 'false' : 'true'; ?>"
        aria-label="<?php echo esc_attr($veyraStrings['open_chat']); ?>"
        <?php echo $veyraShowLauncher ? '' : 'hidden'; ?>
    >
        <span class="veyra-chat__launcher-mark" aria-hidden="true">V</span>
        <span class="veyra-chat__launcher-label"><?php echo esc_html($veyraAiName); ?></span>
    </button>

    <section
        id="<?php echo esc_attr($veyraInstanceId); ?>-panel"
        class="veyra-chat__panel"
        data-veyra-panel
        role="<?php echo $veyraIsModal ? 'dialog' : 'region'; ?>"
        <?php echo $veyraIsModal ? 'aria-modal="true"' : ''; ?>
        aria-labelledby="<?php echo esc_attr($veyraInstanceId); ?>-title"
        <?php echo $veyraPanelHidden ? 'hidden' : ''; ?>
    >
        <header class="veyra-chat__header">
            <div class="veyra-chat__identity">
                <span class="veyra-chat__avatar" aria-hidden="true">V</span>
                <span class="veyra-chat__identity-copy">
                    <span class="veyra-chat__title-line">
                        <strong id="<?php echo esc_attr($veyraInstanceId); ?>-title" class="veyra-chat__title">
                            <?php echo esc_html($veyraAiName); ?>
                        </strong>
                        <span class="veyra-chat__ai-badge"><?php echo esc_html($veyraStrings['ai_badge']); ?></span>
                    </span>
                    <span class="veyra-chat__disclosure"><?php echo esc_html($veyraDisclosure); ?></span>
                </span>
            </div>
            <div class="veyra-chat__header-actions">
                <button
                    type="button"
                    class="veyra-chat__icon-button"
                    data-veyra-history
                    aria-label="<?php echo esc_attr($veyraStrings['history']); ?>"
                    title="<?php echo esc_attr($veyraStrings['history']); ?>"
                >
                    <span aria-hidden="true">↶</span>
                </button>
                <button
                    type="button"
                    class="veyra-chat__icon-button"
                    data-veyra-new
                    aria-label="<?php echo esc_attr($veyraStrings['new_conversation']); ?>"
                    title="<?php echo esc_attr($veyraStrings['new_conversation']); ?>"
                    hidden
                >
                    <span aria-hidden="true">＋</span>
                </button>
                <button
                    type="button"
                    class="veyra-chat__icon-button"
                    data-veyra-close
                    aria-label="<?php echo esc_attr($veyraStrings['close_chat']); ?>"
                    title="<?php echo esc_attr($veyraStrings['close_chat']); ?>"
                    <?php echo $veyraIsModal ? '' : 'hidden'; ?>
                >
                    <span aria-hidden="true">×</span>
                </button>
            </div>
        </header>

        <div class="veyra-chat__connection" data-veyra-connection role="status" aria-live="polite" aria-atomic="true" hidden></div>

        <div class="veyra-chat__main" data-veyra-scroll>
            <div class="veyra-chat__history-control">
                <button type="button" class="veyra-chat__secondary-button" data-veyra-load-older hidden>
                    <?php echo esc_html($veyraStrings['load_older']); ?>
                </button>
            </div>

            <ol
                class="veyra-chat__timeline"
                data-veyra-timeline
                role="log"
                aria-live="polite"
                aria-relevant="additions text"
                aria-label="<?php echo esc_attr($veyraStrings['timeline']); ?>"
            >
                <li class="veyra-chat__empty" data-veyra-empty>
                    <span class="veyra-chat__empty-mark" aria-hidden="true">✦</span>
                    <strong><?php echo esc_html($veyraStrings['empty_title']); ?></strong>
                    <span><?php echo esc_html($veyraStrings['empty_body']); ?></span>
                </li>
            </ol>

            <button type="button" class="veyra-chat__jump" data-veyra-jump hidden>
                <?php echo esc_html($veyraStrings['jump_latest']); ?>
            </button>
        </div>

        <div class="veyra-chat__activity" data-veyra-activity role="status" aria-live="polite" aria-atomic="true"></div>

        <div class="veyra-chat__quick-replies" data-veyra-quick-replies aria-label="<?php echo esc_attr($veyraStrings['quick_replies']); ?>" hidden></div>

        <div class="veyra-chat__draft-context" data-veyra-draft-context hidden>
            <div class="veyra-chat__draft-quote" data-veyra-draft-quote hidden>
                <span class="veyra-chat__draft-context-copy">
                    <strong><?php echo esc_html($veyraStrings['reply']); ?></strong>
                    <span data-veyra-draft-quote-text dir="auto"></span>
                    <small><?php echo esc_html($veyraStrings['quote_pending']); ?></small>
                </span>
                <button type="button" class="veyra-chat__icon-button" data-veyra-remove-quote aria-label="<?php echo esc_attr($veyraStrings['remove_reply']); ?>">×</button>
            </div>
            <ul class="veyra-chat__draft-references" data-veyra-draft-references aria-label="<?php echo esc_attr($veyraStrings['product_references']); ?>"></ul>
        </div>

        <form class="veyra-chat__composer" data-veyra-composer novalidate>
            <label class="veyra-chat__sr-only" for="<?php echo esc_attr($veyraInstanceId); ?>-message">
                <?php echo esc_html($veyraStrings['message_label']); ?>
            </label>
            <textarea
                id="<?php echo esc_attr($veyraInstanceId); ?>-message"
                class="veyra-chat__textarea"
                data-veyra-input
                name="message"
                rows="1"
                dir="auto"
                autocomplete="off"
                enterkeyhint="send"
                aria-describedby="<?php echo esc_attr($veyraInstanceId); ?>-error"
                placeholder="<?php echo esc_attr($veyraStrings['message_placeholder']); ?>"
            ></textarea>
            <button type="button" class="veyra-chat__stop" data-veyra-stop hidden>
                <span aria-hidden="true">■</span>
                <?php echo esc_html($veyraStrings['stop']); ?>
            </button>
            <button type="submit" class="veyra-chat__send" data-veyra-send aria-label="<?php echo esc_attr($veyraStrings['send']); ?>">
                <span class="veyra-chat__send-label"><?php echo esc_html($veyraStrings['send']); ?></span>
                <span class="veyra-chat__send-icon" aria-hidden="true">↑</span>
            </button>
        </form>
        <div id="<?php echo esc_attr($veyraInstanceId); ?>-error" class="veyra-chat__error" data-veyra-error role="alert" aria-live="assertive"></div>

        <dialog class="veyra-chat__confirm" data-veyra-confirm-dialog aria-labelledby="<?php echo esc_attr($veyraInstanceId); ?>-confirm-title">
            <form method="dialog" class="veyra-chat__confirm-card">
                <h3 id="<?php echo esc_attr($veyraInstanceId); ?>-confirm-title"><?php echo esc_html($veyraStrings['confirm_title']); ?></h3>
                <p data-veyra-confirm-summary dir="auto"></p>
                <div class="veyra-chat__confirm-actions">
                    <button type="submit" value="cancel" class="veyra-chat__secondary-button"><?php echo esc_html($veyraStrings['cancel_action']); ?></button>
                    <button type="submit" value="confirm" class="veyra-chat__primary-button" data-veyra-confirm-submit><?php echo esc_html($veyraStrings['confirm_action']); ?></button>
                </div>
            </form>
        </dialog>
    </section>
</div>
