<?php
defined('ABSPATH') || exit;

/**
 * Berichten on the member's page in the admin: this member's conversations
 * (each links to Berichten) and a form to start one; see AVBK_Messages.
 *
 * @param object[] $fee_items The member's items, to refer to.
 */
function avbk_render_admin_member_messages(object $member, array $fee_items): void {
    $threads = AVBK_DB::get_member_threads([(int) $member->id]);
    $started = (int) ($_GET['message_started'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice only.
    $failed = sanitize_key(wp_unslash($_GET['message_start_failed'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice only.
    $failures = [
        'empty_message'  => 'Vul een bericht in.',
        'missing_email'  => 'Dit lid heeft geen geldig e-mailadres. Het bericht staat wel klaar in Berichten, maar is niet gemaild.',
        'save_failed'    => 'Het bericht is niet opgeslagen en niet verstuurd. Probeer opnieuw.',
        'invalid_action' => 'Het formulier was verlopen. Herlaad de pagina en probeer opnieuw.',
        'forbidden'      => 'Geen toegang.',
    ];
    ?>
    <h3 id="berichten">Berichten</h3>
    <?php if ($started) : ?>
        <div class="notice notice-success inline"><p>Bericht verstuurd. Het staat op de ledenpagina van het lid en is gemaild. <a href="<?php echo esc_url(avbk_admin_thread_url($started)); ?>">Bekijk het gesprek</a>.</p></div>
    <?php elseif ($failed !== '') : ?>
        <div class="notice notice-error inline"><p><?php echo esc_html($failures[$failed] ?? 'Het bericht is niet verstuurd.'); ?></p></div>
    <?php endif; ?>
    <?php avbk_render_admin_member_threads($threads); ?>
    <?php avbk_render_admin_message_start_form($member, $fee_items, $failed !== ''); ?>
    <?php
}

/** @param object[] $threads */
function avbk_render_admin_member_threads(array $threads): void {
    if ($threads) : ?>
        <table class="widefat striped" style="max-width:60em">
            <thead><tr><th scope="col">Onderwerp</th><th scope="col">Laatste bericht</th><th scope="col">Status</th></tr></thead>
            <tbody>
                <?php foreach ($threads as $thread) : ?>
                    <tr>
                        <td><a href="<?php echo esc_url(avbk_admin_thread_url((int) $thread->id)); ?>"><?php echo esc_html(trim((string) $thread->subject) !== '' ? $thread->subject : 'Bericht #' . (int) $thread->id); ?></a></td>
                        <td><?php echo esc_html(mysql2date('d-m-Y H:i', (string) ($thread->last_message_at ?: $thread->created_at))); ?></td>
                        <td><?php echo esc_html(AVBK_Messages::admin_state($thread)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="description">Nog geen berichten met dit lid.</p>
    <?php endif;
}

/** "Bericht sturen": the penningmeester starts a conversation. */
function avbk_render_admin_message_start_form(object $member, array $fee_items, bool $open): void {
    ?>
    <details style="margin:1rem 0;max-width:60em"<?php echo $open ? ' open' : ''; ?>>
        <summary>Bericht sturen</summary>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('avbk_message_start'); ?>
            <input type="hidden" name="action" value="avbk_message_start">
            <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
            <input type="hidden" name="request_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
            <p><label for="avbk-message-start-subject">Onderwerp</label><br>
                <input type="text" name="subject" id="avbk-message-start-subject" class="large-text" maxlength="190"></p>
            <?php if ($fee_items) : ?>
                <p><label for="avbk-message-start-item">Over een post (optioneel)</label><br>
                    <select name="fee_item_id" id="avbk-message-start-item" style="max-width:40em">
                        <option value="0">&mdash; geen &mdash;</option>
                        <?php foreach ($fee_items as $item) : ?>
                            <option value="<?php echo esc_attr($item->id); ?>"><?php echo esc_html($item->description . ' — € ' . number_format((float) $item->amount_due, 2, ',', '.')); ?></option>
                        <?php endforeach; ?>
                    </select></p>
            <?php endif; ?>
            <p><label for="avbk-message-start-text">Bericht</label><br>
                <textarea name="message" id="avbk-message-start-text" rows="5" class="large-text" required></textarea></p>
            <p class="description">Het bericht komt op de ledenpagina van het lid te staan en gaat met de hele tekst per mail<?php echo is_email((string) ($member->email ?? '')) ? ' naar ' . esc_html($member->email) : ''; ?>. Het lid reageert op de site.</p>
            <button type="submit" class="button button-primary">Versturen</button>
        </form>
    </details>
    <?php
}

function avbk_admin_thread_url(int $thread_id): string {
    return add_query_arg(['page' => 'avbk-disputes', 'dispute_id' => $thread_id], admin_url('admin.php')) . '#avbk-dispute-' . $thread_id;
}
