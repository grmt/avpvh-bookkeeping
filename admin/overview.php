<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$review_queue = AVBK_DB::get_review_queue();
$batches = AVBK_DB::get_import_batches(10);
$camps_without_rate = AVBK_DB::get_camps_without_rate();

global $wpdb;
$members = AVPVH_DB::get_members(['status' => 'active']);
$current_book_year = (int) current_time('Y');
$previous_book_year = $current_book_year - 1;
$shown_book_years = [$current_book_year, $previous_book_year];
$open_by_year = array_fill_keys($shown_book_years, 0.0);
$open_by_year_activity = array_fill_keys($shown_book_years, []);
$members_with_balance = 0;
$activity_names = [];
$activity_years = [];
$activity_links = [];
foreach (AVPVH_DB::get_activities() as $activity) {
    $activity_id = (int) $activity->id;
    $activity_names[$activity_id] = trim((string) $activity->name . (!empty($activity->year) ? ' (' . (int) $activity->year . ')' : ''));
    $activity_years[$activity_id] = (int) ($activity->year ?? 0);
    $activity_links[$activity_names[$activity_id]] = add_query_arg(['page' => 'avbk-activity-payments', 'activity_id' => $activity_id], admin_url('admin.php'));
}
foreach ($members as $m) {
    $balance = AVBK_DB::get_member_balance((int) $m->id);
    $member_open_current_year = 0.0;
    foreach ($balance['items'] as $item) {
        if ($item->status === 'waived' || abs((float) $item->remaining) <= 0.005) {
            continue;
        }
        $activity_id = (int) ($item->activity_id ?? 0);
        $book_year = (int) ($item->year ?? 0);
        if (!$book_year && $activity_id) {
            $book_year = $activity_years[$activity_id] ?? 0;
        }
        if (!$book_year && !empty($item->created_at)) {
            $book_year = (int) wp_date('Y', strtotime($item->created_at));
        }
        if (!in_array($book_year, $shown_book_years, true)) {
            continue;
        }
        $label = $activity_id && isset($activity_names[$activity_id])
            ? $activity_names[$activity_id]
            : 'Overige / niet aan activiteit gekoppeld';
        $remaining = (float) $item->remaining;
        $open_by_year[$book_year] += $remaining;
        $open_by_year_activity[$book_year][$label] = ($open_by_year_activity[$book_year][$label] ?? 0.0) + $remaining;
        if ($book_year === $current_book_year) {
            $member_open_current_year += $remaining;
        }
    }
    if ($member_open_current_year > 0.005) {
        $members_with_balance++;
    }
}
foreach ($open_by_year_activity as &$activity_amounts) {
    uasort($activity_amounts, static fn($a, $b) => abs($b) <=> abs($a));
}
unset($activity_amounts);
// Contribution and camp fee items both need an age bracket. A birth *year*
// alone (no exact date) still gets a real, if approximate, age — only a
// member with neither falls back to the "assume adult" estimate this
// warning is really about.
$members_without_birth_date = array_values(array_filter($members, fn($m) => empty($m->birth_date) && empty($m->birth_year)));
$last_payment_dates = AVBK_DB::get_last_payment_dates(wp_list_pluck($members_without_birth_date, 'id'));
$stale_fee_items = AVBK_Fee_Generation::find_stale_fee_items();
$closed_through_year = (int) get_option('avbk_closed_through_year', 0);
$assigned_payment_counts = AVBK_DB::get_assigned_payment_counts_by_year();
$transaction_date_ranges = AVBK_DB::get_transaction_date_ranges_by_year();
?>
<div class="wrap">
    <h1>AV-PvH Boekhouding &mdash; Overzicht</h1>

    <div class="avbk-stat-grid">
        <a class="avbk-stat-tile" href="<?php echo esc_url(admin_url('admin.php?page=avbk-members')); ?>" style="color:inherit;text-decoration:none">
            <span class="avbk-stat-value">&euro; <?php echo esc_html(number_format($open_by_year[$current_book_year], 2, ',', '.')); ?></span>
            <span class="avbk-stat-label">Openstaand <?php echo esc_html($current_book_year); ?></span>
        </a>
        <a class="avbk-stat-tile" href="<?php echo esc_url(admin_url('admin.php?page=avbk-members')); ?>" style="color:inherit;text-decoration:none">
            <span class="avbk-stat-value"><?php echo esc_html($members_with_balance); ?></span>
            <span class="avbk-stat-label">Leden met openstaand saldo <?php echo esc_html($current_book_year); ?></span>
        </a>
        <a class="avbk-stat-tile" href="<?php echo esc_url(admin_url('admin.php?page=avbk-review')); ?>" style="color:inherit;text-decoration:none">
            <span class="avbk-stat-value"><?php echo esc_html(count($review_queue)); ?></span>
            <span class="avbk-stat-label">Transacties te controleren</span>
        </a>
    </div>

    <h2>Openstaand per boekjaar en activiteit</h2>
    <?php foreach ($shown_book_years as $book_year) : ?>
        <h3><?php echo esc_html($book_year); ?></h3>
        <?php
        $transaction_range = $transaction_date_ranges[$book_year] ?? null;
        $range_from = $transaction_range ? ($transaction_range->covered_from ?? $transaction_range->first_date) : null;
        $range_until = $transaction_range ? ($transaction_range->covered_until ?? $transaction_range->last_date) : null;
        $has_export_coverage = $transaction_range && !empty($transaction_range->covered_from);
        $starts_at_year_beginning = $range_from === $book_year . '-01-01';
        ?>
        <div style="max-width:728px;margin:.5rem 0;padding:.65rem .9rem;border-left:4px solid <?php echo $starts_at_year_beginning ? '#00a32a' : '#d63638'; ?>;background:<?php echo $starts_at_year_beginning ? '#edfaef' : '#fcf0f1'; ?>">
            <?php if ($range_from && $range_until) : ?>
                <?php echo $has_export_coverage ? 'Bankexport verwerkt voor de periode' : 'Transacties aanwezig van'; ?>
                <strong><?php echo esc_html(wp_date('d-m-Y', strtotime($range_from))); ?></strong>
                tot en met <strong><?php echo esc_html(wp_date('d-m-Y', strtotime($range_until))); ?></strong>.
                <?php if (!$starts_at_year_beginning) : ?>
                    <strong>Dit boekjaar is niet vanaf 1 januari verwerkt.</strong>
                <?php endif; ?>
            <?php else : ?>
                <strong>Voor dit boekjaar zijn nog geen transacties verwerkt.</strong>
            <?php endif; ?>
        </div>
        <table class="wp-list-table widefat striped" style="max-width:760px;margin-bottom:1rem">
            <thead><tr><th>Activiteit</th><th style="width:12em">Openstaand</th></tr></thead>
            <tbody>
            <?php if (!$open_by_year_activity[$book_year]) : ?>
                <tr><td colspan="2">Niets openstaand.</td></tr>
            <?php else : foreach ($open_by_year_activity[$book_year] as $label => $amount) : ?>
                <tr><td><?php if (isset($activity_links[$label])) : ?><a href="<?php echo esc_url($activity_links[$label]); ?>"><?php echo esc_html($label); ?></a><?php else : echo esc_html($label); endif; ?></td><td>&euro; <?php echo esc_html(number_format((float) $amount, 2, ',', '.')); ?></td></tr>
            <?php endforeach; endif; ?>
            </tbody>
            <tfoot><tr><th>Totaal <?php echo esc_html($book_year); ?></th><th>&euro; <?php echo esc_html(number_format($open_by_year[$book_year], 2, ',', '.')); ?></th></tr></tfoot>
        </table>
    <?php endforeach; ?>

    <?php if ($review_queue) : ?>
        <div class="notice notice-warning">
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=avbk-review')); ?>">
                <?php echo esc_html(count($review_queue)); ?> transactie(s) wachten op controle &rarr;
            </a></p>
        </div>
    <?php endif; ?>

    <?php if ($members_without_birth_date) : ?>
        <div class="notice notice-warning">
            <details>
                <summary><?php echo esc_html(count($members_without_birth_date)); ?> <?php echo count($members_without_birth_date) === 1 ? 'lid' : 'leden'; ?> zonder geboortedatum &mdash; hun contributie/kampbijdrage wordt gegenereerd met het volwassen tarief als aanname (gemarkeerd in rood bij hun bijdrage). Voeg de geboortedatum toe en genereer opnieuw om dit te corrigeren.</summary>
                <table class="wp-list-table widefat striped" style="margin-top:.75rem;max-width:700px">
                    <thead><tr><th>Naam</th><th>E-mail</th><th>Laatste betaling</th></tr></thead>
                    <tbody>
                    <?php foreach ($members_without_birth_date as $m) :
                        $last_payment = $last_payment_dates[(int) $m->id] ?? null;
                        ?>
                        <tr>
                            <td><a href="<?php echo esc_url(admin_url('admin.php?page=avpvh-member-detail&id=' . $m->id)); ?>" target="_blank"><?php echo esc_html(avpvh_format_name($m, 'list')); ?></a></td>
                            <td><?php echo $m->email ? esc_html($m->email) : '&mdash;'; ?></td>
                            <td><?php echo $last_payment ? esc_html(wp_date('d-m-Y', strtotime($last_payment))) : '&mdash;'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        </div>
    <?php endif; ?>

    <?php if ($stale_fee_items) : ?>
        <div class="notice notice-warning">
            <p><?php echo esc_html(count($stale_fee_items)); ?> bijdrage-regel(s) zijn mogelijk verouderd &mdash; het bedrag in het systeem komt niet meer overeen met een verse berekening op basis van de huidige gegevens (bijv. geboortedatum of aantal nachten gewijzigd nadat de bijdrage werd berekend). Genereer opnieuw via <a href="<?php echo esc_url(admin_url('admin.php?page=avbk-rates')); ?>">Tarieven</a> om te corrigeren:</p>
            <ul style="margin-left:1.5em;list-style:disc">
                <?php foreach ($stale_fee_items as $s) : ?>
                    <li>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=avpvh-member-detail&id=' . $s->member->id)); ?>" target="_blank"><?php echo esc_html(avpvh_format_name($s->member, 'list')); ?></a>
                        &mdash; <?php echo esc_html($s->item->description); ?>:
                        systeem &euro;<?php echo esc_html(number_format((float) $s->item->amount_due, 2, ',', '.')); ?>,
                        nu zou dat &euro;<?php echo esc_html(number_format($s->current_amount, 2, ',', '.')); ?> zijn
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($camps_without_rate) : ?>
        <div class="notice notice-warning">
            <p>Geen kampbijdrage-tarief ingesteld voor:
                <?php echo esc_html(implode(', ', array_map(fn($c) => "{$c->name} ({$c->year})", $camps_without_rate))); ?>
                &mdash; <a href="<?php echo esc_url(admin_url('admin.php?page=avbk-rates')); ?>">tarief instellen</a>.
                Kampbijdragen voor deze kampen worden niet gegenereerd totdat dit is ingesteld.
            </p>
        </div>
    <?php endif; ?>

    <h2>Recente imports</h2>
    <table class="wp-list-table widefat striped">
        <thead><tr><th>Bestand</th><th>Datum</th><th>Rijen</th><th>Automatisch gematcht</th></tr></thead>
        <tbody>
        <?php if (!$batches) : ?>
            <tr><td colspan="4">Nog geen bankexport geüpload. <a href="<?php echo esc_url(admin_url('admin.php?page=avbk-import')); ?>">Upload er een</a>.</td></tr>
        <?php else : foreach ($batches as $b) : ?>
            <tr>
                <td><?php echo esc_html($b->filename); ?></td>
                <td><?php echo esc_html(wp_date('D d M Y H:i', strtotime($b->uploaded_at))); ?></td>
                <td><?php echo esc_html($b->row_count); ?></td>
                <td><?php echo esc_html($b->matched_count); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <h2>Boekjaar afsluiten</h2>
    <?php if (isset($_GET['year_closed'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Opgeslagen.</p></div>
    <?php endif; ?>
    <p class="description">
        <?php if ($closed_through_year) : ?>
            Boekjaren tot en met <strong><?php echo esc_html($closed_through_year); ?></strong> zijn afgesloten &mdash;
            betalingen uit die jaren worden niet meer standaard getoond bij "Alle transacties" en op de profielpagina
            van leden. Er wordt niets vergrendeld of verwijderd; je kunt dit altijd terugzetten of verder ophogen.
        <?php else : ?>
            Nog geen boekjaar afgesloten &mdash; alle jaren worden overal getoond.
        <?php endif; ?>
    </p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('avbk_set_closed_through_year'); ?>
        <input type="hidden" name="action" value="avbk_set_closed_through_year">
        <label>Afgesloten tot en met jaar:
            <input type="number" name="closed_through_year" value="<?php echo esc_attr($closed_through_year ?: ''); ?>" placeholder="bijv. 2025" style="width:6em">
        </label>
        <?php submit_button('Opslaan', 'secondary', 'submit', false); ?>
    </form>

    <h2>Betalingen opnieuw toewijzen</h2>
    <?php if (isset($_GET['reset_error'])) : ?>
        <div class="notice notice-error"><p>Niet teruggezet: vul ter bevestiging exact hetzelfde jaartal in.</p></div>
    <?php endif; ?>
    <p class="description">
        Hiermee verwijder je alle bestaande toewijzingen en eerste/tweede goedkeuringen van inkomende betalingen uit één jaar.
        De banktransacties zelf blijven bewaard en verschijnen daarna opnieuw bij <em>Te controleren</em>, oudste eerst.
        Uitgaande betalingen en als duplicaat gemarkeerde imports blijven onaangetast.
    </p>
    <?php if (!$assigned_payment_counts) : ?>
        <p>Er zijn momenteel geen toegewezen inkomende betalingen.</p>
    <?php else : ?>
        <?php foreach ($assigned_payment_counts as $payment_year => $payment_count) : ?>
            <details style="margin-top:.75rem;max-width:760px">
                <summary><strong><?php echo esc_html($payment_year); ?></strong> &mdash; <?php echo esc_html($payment_count); ?> toegewezen betaling(en)</summary>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:1rem 0;padding:1rem;border-left:4px solid #d63638;background:#fff">
                    <?php wp_nonce_field('avbk_revert_year_payments_to_review'); ?>
                    <input type="hidden" name="action" value="avbk_revert_year_payments_to_review">
                    <input type="hidden" name="payment_year" value="<?php echo esc_attr($payment_year); ?>">
                    <p><strong>Dit maakt alle <?php echo esc_html($payment_count); ?> toewijzingen uit <?php echo esc_html($payment_year); ?> ongedaan.</strong></p>
                    <label>Typ <strong><?php echo esc_html($payment_year); ?></strong> om te bevestigen:
                        <input type="number" name="confirm_payment_year" required autocomplete="off" style="width:7em">
                    </label>
                    <?php submit_button('Alle betalingen uit ' . $payment_year . ' opnieuw toewijzen', 'delete', 'submit', false); ?>
                </form>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
