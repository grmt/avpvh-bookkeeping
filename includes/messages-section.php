<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/interface-component.php';
require_once __DIR__ . '/class-messages.php';

/**
 * Berichten on the member page: the conversations about this member with
 * the penningmeester, an answer form under each, and a form for a new one.
 * See AVBK_Messages.
 *
 * @param object[] $fee_items The items shown on the page, to refer to.
 */
function avbk_render_member_messages(object $own_member, object $target_member, array $fee_items, string $page_url): void {
    $threads = AVBK_DB::get_member_threads([(int) $target_member->id]);
    ?>
    <section class="avbk-messages" id="berichten">
        <h3>Berichten</h3>
        <?php foreach (AVBK_Messages::NOTICES as $key => $text) :
            if (!empty($_GET[$key])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- picks a fixed notice. ?>
                <p class="avbk-balance-notice<?php echo $key === 'message_failed' ? ' avbk-balance-notice--error' : ''; ?>"><?php echo esc_html($text); ?></p>
            <?php endif;
        endforeach; ?>
        <p class="description">Vragen over je bijdrage, een bestelling of iets anders? Stuur hier een bericht aan de penningmeester. Het antwoord komt hier te staan, en je krijgt er een mail van.</p>
        <?php foreach ($threads as $thread) {
            avbk_render_member_thread($thread, $own_member, $page_url);
        } ?>
        <?php avbk_render_member_new_message($target_member, $fee_items, $page_url, (bool) $threads); ?>
    </section>
    <?php
}

/** One conversation on the member page, with the answer form. */
function avbk_render_member_thread(object $thread, object $own_member, string $page_url): void {
    $entries = AVBK_Messages::conversation($thread, AVBK_DB::get_dispute_events((int) $thread->id), false);
    $fee_item = !empty($thread->fee_item_id) ? AVBK_DB::get_fee_item((int) $thread->fee_item_id) : null;
    $open = $thread->status === 'open';
    ?>
        <details class="avbk-message-thread"<?php echo $open && ($thread->awaiting ?? '') === 'member' ? ' open' : ''; ?>>
            <summary>
                <strong><?php echo esc_html(trim((string) $thread->subject) !== '' ? $thread->subject : 'Bericht #' . (int) $thread->id); ?></strong>
                <span class="avbk-message-state"><?php echo esc_html(avbk_member_thread_state($thread)); ?></span>
            </summary>
            <?php if ($fee_item) : ?><p class="description">Over: <?php echo esc_html($fee_item->description); ?></p><?php endif; ?>
            <ol class="avbk-message-list">
                <?php foreach ($entries as $entry) {
                    avbk_render_message_entry($entry, $own_member);
                } ?>
            </ol>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-message-form">
                <?php wp_nonce_field('avbk_message_reply'); ?>
                <input type="hidden" name="action" value="avbk_message_reply">
                <input type="hidden" name="thread_id" value="<?php echo esc_attr($thread->id); ?>">
                <input type="hidden" name="request_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
                <input type="hidden" name="redirect_url" value="<?php echo esc_url($page_url); ?>">
                <label for="avbk-message-reply-<?php echo esc_attr($thread->id); ?>"><?php echo $open ? 'Reageren' : 'Toch nog een vraag? Dan gaat het gesprek weer open.'; ?></label>
                <textarea name="message" id="avbk-message-reply-<?php echo esc_attr($thread->id); ?>" rows="3" required></textarea>
                <button type="submit" class="button">Versturen</button>
            </form>
        </details>
    <?php
}

/** "Wacht op antwoord", "Nieuw antwoord" or "Afgehandeld". */
function avbk_member_thread_state(object $thread): string {
    if ($thread->status !== 'open') {
        return 'Afgehandeld';
    }
    return ($thread->awaiting ?? '') === 'member' ? 'Antwoord van de penningmeester' : 'Wacht op antwoord';
}

/** @param array{author: string, member_id: ?int, text: string, at: string, kind: string, event: ?object} $entry */
function avbk_render_message_entry(array $entry, object $own_member): void {
    $at = wp_date('d-m-Y H:i', strtotime($entry['at']));
    if ($entry['kind'] === 'status') : ?>
                <li class="avbk-message avbk-message--status"><?php echo esc_html(($entry['event']->event_type === 'resolved' ? 'Afgehandeld' : 'Heropend') . ' · ' . $at); ?></li>
        <?php return;
    endif;
    $member = $entry['member_id'] ? AVPVH_DB::get_member($entry['member_id']) : null;
    $who = match (true) {
        $entry['author'] === 'treasurer'                         => 'Penningmeester',
        $entry['member_id'] === (int) $own_member->id            => 'Jij',
        $member !== null                                         => avpvh_format_name($member),
        default                                                  => 'Lid',
    };
    ?>
                <li class="avbk-message avbk-message--<?php echo esc_attr($entry['author']); ?>">
                    <span class="avbk-message-meta"><?php echo esc_html($who . ' · ' . $at); ?></span>
                    <div class="avbk-message-text"><?php echo nl2br(esc_html($entry['text'])); ?></div>
                </li>
    <?php
}

/** The form for a new conversation; folded away when there are conversations already. */
function avbk_render_member_new_message(object $target_member, array $fee_items, string $page_url, bool $has_threads): void {
    ?>
        <details class="avbk-message-new"<?php echo $has_threads ? '' : ' open'; ?>>
            <summary>Nieuw bericht aan de penningmeester</summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-message-form">
                <?php wp_nonce_field('avbk_message_new'); ?>
                <input type="hidden" name="action" value="avbk_message_new">
                <input type="hidden" name="member_id" value="<?php echo esc_attr($target_member->id); ?>">
                <input type="hidden" name="request_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
                <input type="hidden" name="redirect_url" value="<?php echo esc_url($page_url); ?>">
                <label for="avbk-message-subject">Onderwerp</label>
                <input type="text" name="subject" id="avbk-message-subject" maxlength="190" placeholder="Bijv.: betaling kamp, bestelling, adreswijziging">
                <?php if ($fee_items) : ?>
                    <label for="avbk-message-item">Gaat het over een post op je rekening? (optioneel)</label>
                    <select name="fee_item_id" id="avbk-message-item">
                        <option value="0">&mdash; nee &mdash;</option>
                        <?php foreach ($fee_items as $item) : ?>
                            <option value="<?php echo esc_attr($item->id); ?>"><?php echo esc_html($item->description . ' — € ' . number_format((float) $item->amount_due, 2, ',', '.')); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <label for="avbk-message-text">Je bericht</label>
                <textarea name="message" id="avbk-message-text" rows="4" required placeholder="Bijv.: ik heb dit al betaald, of dit bedrag klopt volgens mij niet, ..."></textarea>
                <button type="submit" class="button">Versturen</button>
            </form>
        </details>
    <?php
}
