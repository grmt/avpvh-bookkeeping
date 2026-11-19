<?php
defined('ABSPATH') || exit;

/** Rows per page of the mail log. */
const AVBK_MAIL_LOG_PAGE_SIZE = 50;

/** A link to the mail log, with extra query arguments (a mail id, a member filter). */
function avbk_mail_log_url(array $args = []): string {
    return add_query_arg(['page' => 'avbk-mail-log'] + $args, admin_url('admin.php'));
}

/** Renders the admin/mail-log.php page: every mail the plugin sent, filterable, with the mail itself on request. */
function avbk_render_mail_log(): void {
    if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
        wp_die('Geen toegang.');
    }
    $view_id = (int) ($_GET['mail'] ?? 0);
    if ($view_id) {
        avbk_render_logged_mail($view_id);
        return;
    }
    $filters = avbk_mail_log_filters();
    $page = max(1, (int) ($_GET['paged'] ?? 1));
    $total = AVBK_DB::count_mail_log($filters);
    $mails = AVBK_DB::get_mail_log($filters, AVBK_MAIL_LOG_PAGE_SIZE, ($page - 1) * AVBK_MAIL_LOG_PAGE_SIZE);
    $member = !empty($filters['member_id']) ? AVPVH_DB::get_member((int) $filters['member_id']) : null;
    ?>
<div class="wrap">
    <h1>Verzonden mails</h1>
    <p class="description">Alle mails die de boekhouding verstuurt: betaalverzoeken, bevestigingen, antwoorden op berichten en meldingen aan de penningmeester. Inlogcodes en andere mails van de website staan hier niet. Mails van vóór 18 november 2026 zijn overgenomen uit het logboek van FluentSMTP (&ldquo;uit FluentSMTP&rdquo;).</p>
    <?php if ($member) : ?>
        <p>Mails aan <strong><?php echo esc_html(avpvh_format_name($member, 'list')); ?></strong>. <a href="<?php echo esc_url(avbk_mail_log_url()); ?>">Toon alle mails</a>.</p>
    <?php endif; ?>
    <?php avbk_render_mail_log_filter_form($filters); ?>
    <p><?php echo esc_html(sprintf('%d %s', $total, $total === 1 ? 'mail' : 'mails')); ?></p>
    <?php if ($mails) : ?>
    <table class="widefat striped">
        <thead><tr><th scope="col">Verstuurd</th><th scope="col">Soort</th><th scope="col">Aan</th><th scope="col">Onderwerp</th><th scope="col">Status</th><th scope="col">Door</th></tr></thead>
        <tbody>
            <?php foreach ($mails as $mail) {
                avbk_render_mail_log_row($mail);
            } ?>
        </tbody>
    </table>
    <?php avbk_render_mail_log_pagination($total, $page); ?>
    <?php endif; ?>
</div>
<?php
}

/**
 * The filters from the query string.
 *
 * @return array{kind: string, member_id: int, status: string, search: string, month: string}
 */
function avbk_mail_log_filters(): array {
    $kind = sanitize_key(wp_unslash($_GET['kind'] ?? ''));
    $status = sanitize_key(wp_unslash($_GET['status'] ?? ''));
    return [
        'kind'      => isset(AVBK_MAIL_KINDS[$kind]) ? $kind : '',
        'member_id' => (int) ($_GET['member_id'] ?? 0),
        'status'    => in_array($status, ['sent', 'failed'], true) ? $status : '',
        'search'    => sanitize_text_field(wp_unslash($_GET['s'] ?? '')),
        'month'     => preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : '',
    ];
}

/** @param array{kind: string, member_id: int, status: string, search: string, month: string} $filters */
function avbk_render_mail_log_filter_form(array $filters): void {
    ?>
    <form method="get" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end;margin:1rem 0">
        <input type="hidden" name="page" value="avbk-mail-log">
        <?php if ($filters['member_id']) : ?><input type="hidden" name="member_id" value="<?php echo esc_attr($filters['member_id']); ?>"><?php endif; ?>
        <label>Soort<br>
            <select name="kind">
                <option value="">Alle</option>
                <?php foreach (AVBK_MAIL_KINDS as $kind => $label) : ?>
                    <option value="<?php echo esc_attr($kind); ?>" <?php selected($filters['kind'], $kind); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Status<br>
            <select name="status">
                <option value="">Alle</option>
                <option value="sent" <?php selected($filters['status'], 'sent'); ?>>Verstuurd</option>
                <option value="failed" <?php selected($filters['status'], 'failed'); ?>>Mislukt</option>
            </select>
        </label>
        <label>Maand<br><input type="month" name="month" value="<?php echo esc_attr($filters['month']); ?>"></label>
        <label>Zoek in ontvanger of onderwerp<br><input type="search" name="s" value="<?php echo esc_attr($filters['search']); ?>"></label>
        <button type="submit" class="button">Filter</button>
    </form>
    <?php
}

function avbk_render_mail_log_row(object $mail): void {
    $member = $mail->member_id ? AVPVH_DB::get_member((int) $mail->member_id) : null;
    $sender = $mail->sent_by ? get_userdata((int) $mail->sent_by) : null;
    $view_url = avbk_mail_log_url(['mail' => (int) $mail->id]);
    ?>
            <tr>
                <td><?php echo esc_html(wp_date('d-m-Y H:i', strtotime($mail->sent_at))); ?></td>
                <td><?php echo esc_html(AVBK_MAIL_KINDS[$mail->kind] ?? $mail->kind); ?></td>
                <td>
                    <?php if ($member) : ?>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'avbk-members', 'member_id' => (int) $member->id], admin_url('admin.php'))); ?>"><?php echo esc_html(avpvh_format_name($member, 'list')); ?></a><br>
                    <?php endif; ?>
                    <span class="description"><?php echo esc_html($mail->recipient); ?></span>
                </td>
                <td><a href="<?php echo esc_url($view_url); ?>"><?php echo esc_html($mail->subject !== '' ? $mail->subject : '(geen onderwerp)'); ?></a></td>
                <td><?php if ($mail->status === 'failed') : ?><span style="color:#b32d2e;font-weight:600" title="<?php echo esc_attr($mail->error); ?>">Mislukt</span><?php else : ?>Verstuurd<?php endif; ?></td>
                <td><?php echo esc_html(avbk_mail_log_sender($mail, $sender)); ?></td>
            </tr>
    <?php
}

/** Who sent a logged mail: the user, "automatisch", or that it came over from FluentSMTP. */
function avbk_mail_log_sender(object $mail, $sender): string {
    if (($mail->source ?? '') === 'fluentsmtp') {
        return 'uit FluentSMTP';
    }
    return $sender ? (string) $sender->display_name : 'automatisch';
}

function avbk_render_mail_log_pagination(int $total, int $page): void {
    $pages = (int) ceil($total / AVBK_MAIL_LOG_PAGE_SIZE);
    if ($pages <= 1) {
        return;
    }
    $links = paginate_links([
        'base'    => add_query_arg('paged', '%#%'),
        'format'  => '',
        'current' => $page,
        'total'   => $pages,
    ]);
    echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post((string) $links) . '</div></div>';
}

/**
 * One mail as it was sent. An HTML body is shown in a sandboxed iframe
 * (no scripts, no same-origin access), plain text as preformatted text.
 */
function avbk_render_logged_mail(int $id): void {
    $mail = AVBK_DB::get_logged_mail($id);
    $back = avbk_mail_log_url();
    ?>
<div class="wrap">
    <h1>Verzonden mail</h1>
    <p><a href="<?php echo esc_url($back); ?>">&larr; Alle verzonden mails</a></p>
    <?php if (!$mail) : ?>
        <p>Deze mail staat niet (meer) in het logboek.</p>
    <?php else : ?>
        <table class="form-table">
            <tr><th scope="row">Verstuurd</th><td><?php echo esc_html(wp_date('d-m-Y H:i', strtotime($mail->sent_at))); ?></td></tr>
            <tr><th scope="row">Soort</th><td><?php echo esc_html(AVBK_MAIL_KINDS[$mail->kind] ?? $mail->kind); ?></td></tr>
            <tr><th scope="row">Aan</th><td><?php echo esc_html($mail->recipient); ?></td></tr>
            <tr><th scope="row">Onderwerp</th><td><?php echo esc_html($mail->subject); ?></td></tr>
            <tr><th scope="row">Status</th><td><?php echo $mail->status === 'failed' ? esc_html('Mislukt: ' . $mail->error) : 'Verstuurd'; ?></td></tr>
        </table>
        <?php if ($mail->is_html) : ?>
            <iframe sandbox="" srcdoc="<?php echo esc_attr((string) $mail->body); ?>" title="Inhoud van de mail" style="width:100%;max-width:800px;height:70vh;background:#fff;border:1px solid #dcdcde"></iframe>
        <?php else : ?>
            <pre style="white-space:pre-wrap;max-width:800px;background:#fff;padding:1rem;border:1px solid #dcdcde"><?php echo esc_html((string) $mail->body); ?></pre>
        <?php endif; ?>
        <p class="description">Afbeeldingen die als bijlage in de mail zaten (zoals de QR-code) worden hier niet getoond.</p>
    <?php endif; ?>
</div>
<?php
}
