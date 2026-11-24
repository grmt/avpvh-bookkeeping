<?php
defined('ABSPATH') || exit;

/** Date and time as the reminders page shows them. */
const AVBK_REMINDER_DATETIME = 'd-m-Y H:i';

/** A link to the reminders page, with extra query arguments. */
function avbk_reminders_url(array $args = []): string {
    return add_query_arg(['page' => 'avbk-reminders'] + $args, admin_url('admin.php'));
}

/** Renders the admin/reminders.php page: choose who gets a reminder, or follow a round. */
function avbk_render_reminders(): void {
    if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
        wp_die('Geen toegang.');
    }
    $round_id = (int) ($_GET['round'] ?? 0);
    ?>
<div class="wrap">
    <h1>Herinneringen</h1>
    <?php
    if ($round_id) {
        avbk_render_reminder_round($round_id);
    } elseif (sanitize_key(wp_unslash($_GET['tab'] ?? '')) === 'rounds') {
        avbk_render_reminder_tabs('rounds');
        avbk_render_reminder_rounds();
    } else {
        avbk_render_reminder_tabs('send');
        avbk_render_reminder_selection();
    }
    ?>
</div>
<?php
}

function avbk_render_reminder_tabs(string $active): void {
    $base = avbk_reminders_url();
    ?>
    <nav class="nav-tab-wrapper">
        <a href="<?php echo esc_url($base); ?>" class="nav-tab<?php echo $active === 'send' ? ' nav-tab-active' : ''; ?>">Versturen</a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'rounds', $base)); ?>" class="nav-tab<?php echo $active === 'rounds' ? ' nav-tab-active' : ''; ?>">Eerdere rondes</a>
    </nav>
    <?php
}

/** Notices after a test mail or a failed start. */
function avbk_render_reminder_notices(): void {
    if (isset($_GET['test_sent'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Testmail verstuurd naar je eigen adres.</p></div>
    <?php elseif (isset($_GET['test_failed'])) : ?>
        <div class="notice notice-error"><p>De testmail is niet verstuurd. Kies minstens één lid met openstaande posten, en controleer je eigen e-mailadres in je profiel.</p></div>
    <?php elseif (isset($_GET['start_failed'])) : ?>
        <div class="notice notice-error"><p><?php echo esc_html(sanitize_key(wp_unslash($_GET['start_failed'])) === 'no_recipients'
            ? 'Er is niemand verstuurd: geen van de aangevinkte leden heeft nog iets open en een geldig e-mailadres.'
            : 'De ronde is niet opgeslagen. Probeer het opnieuw.'); ?></p></div>
    <?php endif;
}

/** Filters, the members with something open, the text and the buttons. */
function avbk_render_reminder_selection(): void {
    $filters = AVBK_Reminder_Rules::filters_from_request();
    $today = current_time('Y-m-d');
    $rows = array_values(array_filter(AVBK_Reminder_Rules::candidates(), static fn($row) => AVBK_Reminder_Rules::matches($row, $filters, $today)));
    avbk_render_reminder_notices();
    ?>
    <p class="description">Iedereen met een openstaand bedrag buiten afgesloten jaren. Aangevinkt zijn de leden die in de laatste <?php echo esc_html($filters['not_reminded_days']); ?> dagen geen betaalverzoek of herinnering kregen en geen waarschuwing hebben. Vink aan of uit wie een mail krijgt.</p>
    <p class="description">Zoek of filter in de velden onder de kolomkoppen; klik op een kolomkop om te sorteren. Alleen de aangevinkte leden die je ziet krijgen een mail. De herinnering noemt alles wat een lid open heeft, niet alleen de gefilterde posten.</p>
    <form method="get" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;margin:1rem 0">
        <input type="hidden" name="page" value="avbk-reminders">
        <label>Minimaal openstaand (&euro;)<br><input type="text" name="min_amount" value="<?php echo esc_attr(number_format($filters['min_amount'], 2, ',', '')); ?>" size="6" inputmode="decimal"></label>
        <label>Oudste post minstens (dagen)<br><input type="number" name="older_than_days" value="<?php echo esc_attr($filters['older_than_days']); ?>" min="0" style="width:6em"></label>
        <label>Niet herinnerd in de laatste (dagen)<br><input type="number" name="not_reminded_days" value="<?php echo esc_attr($filters['not_reminded_days']); ?>" min="0" style="width:6em"></label>
        <button type="submit" class="button">Toon</button>
    </form>
    <?php if (!$rows) : ?>
        <p>Niemand heeft (met deze filters) iets open.</p>
        <?php return;
    endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="avbk-reminder-form">
        <?php wp_nonce_field('avbk_reminders_send'); ?>
        <input type="hidden" name="request_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
        <div data-avpvh-sortable>
        <div class="avpvh-table-tools">
            <input type="search" class="avpvh-table-search" placeholder="Zoek" aria-label="Zoek in de lijst">
            <span class="avpvh-table-count" aria-live="polite"></span>
            <button type="button" class="button button-small avpvh-col-toggle-btn">Kolommen</button>
            <span class="avpvh-col-toggle-panel" hidden></span>
        </div>
        <div class="avpvh-table-wrap">
        <table class="widefat striped avpvh-sortable-table" data-storage-key="avbk_reminders_hidden_cols">
            <thead><tr>
                <td class="check-column"><input type="checkbox" id="avbk-reminder-all" aria-label="Alle zichtbare leden aan- of uitvinken"></td>
                <th scope="col" data-col="lid">Lid</th>
                <th scope="col" data-col="openstaand" data-type="number">Openstaand</th>
                <th scope="col" data-col="posten" data-filter="select" data-filter-sep="|">Posten</th>
                <th scope="col" data-col="aantal" data-type="number" class="avpvh-col-optional">Aantal</th>
                <th scope="col" data-col="oudste">Oudste post</th>
                <th scope="col" data-col="herinnerd" data-filter="select" data-type="number">Herinnerd</th>
                <th scope="col" data-col="gevraagd">Laatst gevraagd</th>
                <th scope="col" data-col="letop" data-filter="select" data-filter-sep="|">Let op</th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $row) {
                    avbk_render_reminder_row($row, AVBK_Reminder_Rules::selected_by_default($row, $filters, $today));
                } ?>
            </tbody>
        </table>
        </div>
        </div>
        <p>
            <label for="avbk-reminder-intro"><strong>Tekst na &ldquo;Dag [voornaam],&rdquo;</strong></label><br>
            <textarea name="intro" id="avbk-reminder-intro" rows="5" class="large-text"><?php echo esc_textarea(AVBK_Reminder_Rules::intro()); ?></textarea>
            <label><input type="checkbox" name="save_intro" value="1"> Bewaar als standaardtekst</label>
        </p>
        <p class="description">Daarna volgen, per lid, de openstaande posten, het bedrag, de betaalgegevens met omschrijving en de link naar de ledenpagina met QR-code.</p>
        <p>
            <button type="submit" name="action" value="avbk_reminders_preview" class="button" formtarget="_blank">Voorbeeld (eerste aangevinkte lid)</button>
            <button type="submit" name="action" value="avbk_reminders_test" class="button">Testmail naar mij</button>
            <button type="submit" name="action" value="avbk_reminders_start" class="button button-primary" id="avbk-reminder-send">Herinneringen versturen (<span id="avbk-reminder-count">0</span>)</button>
        </p>
    </form>
    <?php
}

/** @param array<string, mixed> $row From AVBK_Reminder_Rules::candidate(). */
function avbk_render_reminder_row(array $row, bool $selected): void {
    $member = $row['member'];
    $field = 'avbk-reminder-' . (int) $row['member_id'];
    $member_url = add_query_arg(['page' => 'avbk-members', 'member_id' => (int) $row['member_id']], admin_url('admin.php'));
    $last_request = $row['last_request'] ? wp_date('d-m-Y', strtotime($row['last_request'])) : '—';
    ?>
                <tr>
                    <th scope="row" class="check-column"><input type="checkbox" name="member_ids[]" value="<?php echo esc_attr($row['member_id']); ?>" id="<?php echo esc_attr($field); ?>" class="avbk-reminder-member"<?php checked($selected); ?>></th>
                    <td><label for="<?php echo esc_attr($field); ?>"><a href="<?php echo esc_url($member_url); ?>"><?php echo esc_html($member ? avpvh_format_name($member, 'list') : 'onbekend lid #' . $row['member_id']); ?></a></label></td>
                    <td data-sort-value="<?php echo esc_attr(number_format($row['amount'], 2, '.', '')); ?>">&euro; <?php echo esc_html(number_format($row['amount'], 2, ',', '.')); ?></td>
                    <td data-filter-value="<?php echo esc_attr(implode('|', $row['labels'])); ?>"><?php echo esc_html(implode(', ', $row['labels'])); ?></td>
                    <td><?php echo esc_html($row['count']); ?></td>
                    <td data-sort-value="<?php echo esc_attr($row['oldest']); ?>"><?php echo $row['oldest'] !== '' ? esc_html(wp_date('d-m-Y', strtotime($row['oldest']))) : '—'; ?></td>
                    <td data-sort-value="<?php echo esc_attr($row['reminders']); ?>" data-filter-value="<?php echo esc_attr($row['reminders'] ? $row['reminders'] . '×' : 'nog niet'); ?>"><?php echo $row['reminders'] ? esc_html($row['reminders'] . '× (laatst ' . wp_date('d-m-Y', strtotime($row['last_reminder'])) . ')') : 'nog niet'; ?></td>
                    <td data-sort-value="<?php echo esc_attr($row['last_request'] ? substr((string) $row['last_request'], 0, 10) : ''); ?>"><?php echo esc_html($last_request); ?></td>
                    <td data-filter-value="<?php echo esc_attr($row['warnings'] ? implode('|', $row['warnings']) : 'geen'); ?>" style="color:#b32d2e"><?php echo esc_html(implode(', ', $row['warnings'])); ?></td>
                </tr>
    <?php
}

/** A round: its progress while it is being sent, and the outcome per member. */
function avbk_render_reminder_round(int $round_id): void {
    $round = AVBK_DB::get_reminder_round($round_id);
    $back = avbk_reminders_url(['tab' => 'rounds']);
    if (!$round) : ?>
        <p>Deze ronde bestaat niet. <a href="<?php echo esc_url($back); ?>">Eerdere rondes</a></p>
        <?php return;
    endif;
    $counts = $round->counts;
    $total = array_sum($counts);
    $creator = $round->created_by ? get_userdata((int) $round->created_by) : null;
    ?>
    <p><a href="<?php echo esc_url($back); ?>">&larr; Eerdere rondes</a></p>
    <h2>Ronde van <?php echo esc_html(wp_date(AVBK_REMINDER_DATETIME, strtotime($round->created_at))); ?><?php echo $creator ? ' door ' . esc_html($creator->display_name) : ''; ?></h2>
    <div id="avbk-reminder-progress" data-round="<?php echo esc_attr($round_id); ?>" data-pending="<?php echo esc_attr($counts['pending']); ?>">
        <p>
            <progress max="<?php echo esc_attr($total); ?>" value="<?php echo esc_attr($total - $counts['pending'] - $counts['sending']); ?>" style="width:20em"></progress>
            <span class="avbk-reminder-status"><?php echo esc_html(sprintf('%d verstuurd, %d mislukt, %d overgeslagen, %d te gaan', $counts['sent'], $counts['failed'], $counts['skipped'], $counts['pending'])); ?></span>
        </p>
        <?php if ($counts['pending']) : ?>
            <p class="description avbk-reminder-hint">Laat deze pagina open tot alles verstuurd is. Sluit je haar, dan gaat het verder zodra je de ronde weer opent.</p>
            <noscript>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('avbk_reminders_batch'); ?>
                    <input type="hidden" name="action" value="avbk_reminders_batch">
                    <input type="hidden" name="round" value="<?php echo esc_attr($round_id); ?>">
                    <button type="submit" class="button button-primary">Volgende <?php echo esc_html(AVBK_Reminders::BATCH_SIZE); ?> versturen</button>
                </form>
            </noscript>
        <?php endif; ?>
    </div>
    <?php if ($counts['sending']) : ?>
        <p class="description"><?php echo esc_html($counts['sending']); ?> herinnering(en) werden verstuurd toen de verbinding wegviel; kijk bij Verzonden mails of ze aankwamen.</p>
    <?php endif; ?>
    <h3>Tekst</h3>
    <div style="max-width:700px;background:#fff;padding:.5rem 1rem;border:1px solid #dcdcde"><?php echo wp_kses_post(wpautop(esc_html($round->intro))); ?></div>
    <h3>Per lid</h3>
    <?php avbk_render_reminder_round_members($round_id); ?>
    <?php
}

function avbk_render_reminder_round_members(int $round_id): void {
    $labels = ['pending' => 'te gaan', 'sending' => 'bezig', 'sent' => 'verstuurd', 'failed' => 'mislukt', 'skipped' => 'overgeslagen'];
    ?>
    <table class="widefat striped" style="max-width:900px">
        <thead><tr><th scope="col">Lid</th><th scope="col">Bedrag</th><th scope="col">Status</th><th scope="col">Verstuurd</th></tr></thead>
        <tbody>
            <?php foreach (AVBK_DB::get_reminders_for_round($round_id) as $reminder) :
                $member = AVPVH_DB::get_member((int) $reminder->member_id); ?>
                <tr>
                    <td><a href="<?php echo esc_url(add_query_arg(['page' => 'avbk-members', 'member_id' => (int) $reminder->member_id], admin_url('admin.php'))); ?>"><?php echo esc_html($member ? avpvh_format_name($member, 'list') : '#' . $reminder->member_id); ?></a></td>
                    <td>&euro; <?php echo esc_html(number_format((float) $reminder->amount, 2, ',', '.')); ?></td>
                    <td<?php echo $reminder->status === 'failed' ? ' style="color:#b32d2e"' : ''; ?>><?php echo esc_html(($labels[$reminder->status] ?? $reminder->status) . ($reminder->error !== '' ? ': ' . $reminder->error : '')); ?></td>
                    <td><?php echo $reminder->sent_at ? esc_html(wp_date(AVBK_REMINDER_DATETIME, strtotime($reminder->sent_at))) : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/** The latest rounds with their totals. */
function avbk_render_reminder_rounds(): void {
    $rounds = AVBK_DB::get_reminder_rounds();
    if (!$rounds) : ?>
        <p>Nog geen herinneringen verstuurd.</p>
        <?php return;
    endif; ?>
    <table class="widefat striped" style="max-width:900px;margin-top:1rem">
        <thead><tr><th scope="col">Datum</th><th scope="col">Door</th><th scope="col">Verstuurd</th><th scope="col">Mislukt</th><th scope="col">Overgeslagen</th><th scope="col">Te gaan</th></tr></thead>
        <tbody>
            <?php foreach ($rounds as $round) :
                $creator = $round->created_by ? get_userdata((int) $round->created_by) : null; ?>
                <tr>
                    <td><a href="<?php echo esc_url(avbk_reminders_url(['round' => (int) $round->id])); ?>"><?php echo esc_html(wp_date(AVBK_REMINDER_DATETIME, strtotime($round->created_at))); ?></a></td>
                    <td><?php echo esc_html($creator ? $creator->display_name : '—'); ?></td>
                    <td><?php echo esc_html($round->counts['sent']); ?></td>
                    <td><?php echo esc_html($round->counts['failed']); ?></td>
                    <td><?php echo esc_html($round->counts['skipped']); ?></td>
                    <td><?php echo esc_html($round->counts['pending'] + $round->counts['sending']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
