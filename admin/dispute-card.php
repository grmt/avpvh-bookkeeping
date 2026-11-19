<?php
defined('ABSPATH') || exit;

/** One conversation on the Berichten page. */
function avbk_render_dispute_card(object $dispute): void {
$member = AVPVH_DB::get_member((int) $dispute->member_id);
$recipient = AVBK_Disputes::recipient($dispute);
$email = $recipient ? (string) ($recipient->email ?? '') : '';
$can_reply = (bool) is_email($email);
$events = AVBK_DB::get_dispute_events((int) $dispute->id);
$fee_item = !empty($dispute->fee_item_id) ? AVBK_DB::get_fee_item((int) $dispute->fee_item_id) : null;
$draft = get_transient('avbk_dispute_draft_' . get_current_user_id() . '_' . $dispute->id);
$draft = is_array($draft) ? $draft : [];
$focused = absint(wp_unslash($_GET['dispute_id'] ?? 0)) === (int) $dispute->id;
$event_labels = ['reply' => 'Antwoord', 'member_message' => 'Bericht van', 'note' => 'Interne notitie', 'resolved' => 'Afgehandeld', 'reopened' => 'Heropend'];
?>
<article id="avbk-dispute-<?php echo esc_attr($dispute->id); ?>" class="avbk-dispute-card">
    <header class="avbk-dispute-header">
        <h3><?php echo esc_html(trim((string) ($dispute->subject ?? '')) !== '' ? $dispute->subject : 'Bericht #' . $dispute->id); ?> —
            <?php if ($member) : ?>
                <a href="<?php echo esc_url(add_query_arg(['page' => 'avbk-members', 'member_id' => $member->id], admin_url('admin.php'))); ?>"><?php echo esc_html(avpvh_format_name($member, 'list')); ?></a>
            <?php else : ?>Onbekend lid<?php endif; ?>
        </h3>
        <span class="avbk-dispute-status"><?php echo esc_html(AVBK_Messages::admin_state($dispute)); ?></span>
    </header>
    <p class="description">#<?php echo esc_html($dispute->id); ?> · <?php echo ($dispute->started_by ?? 'member') === 'treasurer' ? 'Begonnen door de penningmeester' : 'Ontvangen'; ?> op <?php echo esc_html(mysql2date('d-m-Y H:i', $dispute->created_at)); ?>
        <?php if ($fee_item) : ?> · Over: <?php echo esc_html($fee_item->description); ?><?php endif; ?>
        <?php if (!empty($dispute->submitted_by_member_id) && $recipient && (int) $recipient->id !== (int) $dispute->member_id) : ?>
            · Ingediend door <?php echo esc_html(avpvh_format_name($recipient, 'list')); ?>
        <?php endif; ?>
    </p>
    <?php avbk_render_dispute_history($dispute, $events, $event_labels); ?>

    <?php avbk_render_dispute_actions($focused, $draft, $can_reply, $recipient, $email, $dispute); ?>
</article>
<?php
}

/** The whole conversation, with internal notes and status changes, oldest first. */
function avbk_render_dispute_history(object $dispute, array $events, array $event_labels): void {
?><ol class="avbk-dispute-conversation">
        <?php foreach (AVBK_Messages::conversation($dispute, $events, true) as $entry) : ?>
            <li class="avbk-dispute-entry avbk-dispute-entry--<?php echo esc_attr($entry['kind'] === 'message' ? $entry['author'] : $entry['kind']); ?>">
                <?php if ($entry['event'] === null) : ?>
                    <strong><?php echo esc_html(avbk_dispute_member_label($entry['member_id'])); ?></strong>
                    <span class="description">· <?php echo esc_html(mysql2date('d-m-Y H:i', $entry['at'])); ?></span>
                    <div class="avbk-dispute-event-message"><?php echo nl2br(esc_html($entry['text'])); ?></div>
                <?php else :
                    $event = $entry['event'];
                    $actor = $event->actor_id ? get_userdata((int) $event->actor_id) : null;
                    $actor_label = $event->event_type === 'member_message'
                        ? avbk_dispute_member_label($entry['member_id'])
                        : ($actor ? $actor->display_name : ($event->actor_id ? 'Gebruiker #' . $event->actor_id : 'Onbekende behandelaar'));
                    avbk_render_dispute_event($event_labels, $event, $actor_label);
                endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
<?php
}

/** The name of the member who wrote a message. */
function avbk_dispute_member_label(?int $member_id): string {
    $member = $member_id ? AVPVH_DB::get_member($member_id) : null;
    return $member ? avpvh_format_name($member, 'list') : 'Lid';
}

/** One entry of a conversation that is an event (answer, member message, note, status change). */
function avbk_render_dispute_event($event_labels, $event, $actor_label): void {
?><strong><?php echo esc_html($event_labels[$event->event_type] ?? 'Actie'); ?></strong>
                    <span class="description">· <?php echo esc_html(mysql2date('d-m-Y H:i', $event->created_at)); ?> · <?php echo esc_html($actor_label); ?></span>
                    <?php if ($event->event_type === 'reply') : ?>
                        <p class="avbk-dispute-delivery <?php echo $event->delivery_status === 'sent' ? '' : 'avbk-dispute-delivery--warning'; ?>">
                            Naar <?php echo esc_html($event->recipient_email); ?> —
                            <?php echo esc_html(match ($event->delivery_status) {
                                'sent' => 'Aangeboden aan de mailserver',
                                'failed' => 'Verzenden mislukt',
                                default => 'Verzending niet bevestigd — controleer voordat je opnieuw verstuurt',
                            }); ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($event->message !== '') : ?><div class="avbk-dispute-event-message"><?php echo nl2br(esc_html($event->message)); ?></div><?php endif; ?>
<?php
}

/** Answer, note and close/reopen forms of a conversation. */
function avbk_render_dispute_actions($focused, $draft, $can_reply, $recipient, $email, $dispute): void {
?><div class="avbk-dispute-actions">
        <?php avbk_render_dispute_reply_form($focused, $draft, $can_reply, $recipient, $email, $dispute); ?>

        <details <?php echo $focused && ($draft['action'] ?? '') === 'note' ? 'open' : ''; ?>>
            <summary>Actie of interne notitie vastleggen</summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avbk_update_dispute_' . $dispute->id); ?>
                <input type="hidden" name="action" value="avbk_update_dispute">
                <input type="hidden" name="id" value="<?php echo esc_attr($dispute->id); ?>">
                <input type="hidden" name="dispute_action" value="note">
                <input type="hidden" name="request_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
                <label for="avbk-dispute-note-<?php echo esc_attr($dispute->id); ?>">Wat heb je gedaan of uitgezocht?</label>
                <textarea id="avbk-dispute-note-<?php echo esc_attr($dispute->id); ?>" name="message" rows="3" required><?php echo esc_textarea(($draft['action'] ?? '') === 'note' ? $draft['message'] : ''); ?></textarea>
                <p class="description">Alleen intern zichtbaar. Er wordt geen e-mail verstuurd.</p>
                <p><button type="submit" class="button">Notitie opslaan</button></p>
            </form>
        </details>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-dispute-status-form">
            <?php wp_nonce_field('avbk_update_dispute_' . $dispute->id); ?>
            <input type="hidden" name="action" value="avbk_update_dispute">
            <input type="hidden" name="id" value="<?php echo esc_attr($dispute->id); ?>">
            <input type="hidden" name="dispute_action" value="<?php echo $dispute->status === 'resolved' ? 'reopen' : 'resolve'; ?>">
            <input type="hidden" name="request_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
            <label for="avbk-dispute-reason-<?php echo esc_attr($dispute->id); ?>">Toelichting bij <?php echo $dispute->status === 'resolved' ? 'heropenen' : 'afhandelen'; ?> (optioneel)</label>
            <textarea id="avbk-dispute-reason-<?php echo esc_attr($dispute->id); ?>" name="message" rows="2"><?php echo esc_textarea(in_array($draft['action'] ?? '', ['resolve', 'reopen'], true) ? $draft['message'] : ''); ?></textarea>
            <p><button type="submit" class="button"><?php echo $dispute->status === 'resolved' ? 'Heropenen' : 'Afhandelen'; ?></button></p>
        </form>
    </div>
<?php
}

/** The answer form of a conversation. */
function avbk_render_dispute_reply_form($focused, $draft, $can_reply, $recipient, $email, $dispute): void {
?><details <?php echo $focused && ($draft['action'] ?? '') === 'reply' ? 'open' : ''; ?>>
            <summary>Antwoorden</summary>
            <?php if ($can_reply) : ?>
                <p>Ontvanger: <strong><?php echo esc_html(avpvh_format_name($recipient, 'list')); ?></strong> &lt;<?php echo esc_html($email); ?>&gt;</p>
                <?php if (empty($dispute->submitted_by_member_id)) : ?>
                    <p class="description">Bij deze oudere melding is de oorspronkelijke indiener niet vastgelegd. Het antwoord gaat naar het lid hierboven; controleer of dat de juiste ontvanger is.</p>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('avbk_update_dispute_' . $dispute->id); ?>
                    <input type="hidden" name="action" value="avbk_update_dispute">
                    <input type="hidden" name="id" value="<?php echo esc_attr($dispute->id); ?>">
                    <input type="hidden" name="dispute_action" value="reply">
                    <input type="hidden" name="request_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
                    <label for="avbk-dispute-reply-<?php echo esc_attr($dispute->id); ?>">Je antwoord</label>
                    <textarea id="avbk-dispute-reply-<?php echo esc_attr($dispute->id); ?>" name="message" rows="5" required><?php echo esc_textarea(($draft['action'] ?? '') === 'reply' ? $draft['message'] : ''); ?></textarea>
                    <p class="description">Je antwoord komt op de ledenpagina van het lid te staan en gaat met de hele tekst per mail. Afhandelen doe je apart.</p>
                    <p><button type="submit" class="button button-primary">Antwoord versturen</button></p>
                </form>
            <?php else : ?>
                <p>Er is geen geldig e-mailadres voor de ontvanger. Werk eerst de contactgegevens bij. Je kunt wel een interne notitie toevoegen.</p>
            <?php endif; ?>
        </details>
<?php
}
