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
$total_open = 0.0;
$members_with_balance = 0;
foreach ($members as $m) {
    $balance = AVBK_DB::get_member_balance((int) $m->id);
    if ($balance['balance'] > 0.005) {
        $total_open += $balance['balance'];
        $members_with_balance++;
    }
}
// Contribution and camp fee items both need an age bracket. A birth *year*
// alone (no exact date) still gets a real, if approximate, age — only a
// member with neither falls back to the "assume adult" estimate this
// warning is really about.
$members_without_birth_date = array_values(array_filter($members, fn($m) => empty($m->birth_date) && empty($m->birth_year)));
$stale_fee_items = AVBK_Fee_Generation::find_stale_fee_items();
?>
<div class="wrap">
    <h1>AV-PvH Boekhouding &mdash; Overzicht</h1>

    <div class="avbk-stat-grid">
        <div class="avbk-stat-tile">
            <span class="avbk-stat-value">&euro; <?php echo esc_html(number_format($total_open, 2, ',', '.')); ?></span>
            <span class="avbk-stat-label">Totaal openstaand</span>
        </div>
        <div class="avbk-stat-tile">
            <span class="avbk-stat-value"><?php echo esc_html($members_with_balance); ?></span>
            <span class="avbk-stat-label">Leden met openstaand saldo</span>
        </div>
        <div class="avbk-stat-tile">
            <span class="avbk-stat-value"><?php echo esc_html(count($review_queue)); ?></span>
            <span class="avbk-stat-label">Transacties te controleren</span>
        </div>
    </div>

    <?php if ($review_queue) : ?>
        <div class="notice notice-warning">
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=avbk-review')); ?>">
                <?php echo esc_html(count($review_queue)); ?> transactie(s) wachten op controle &rarr;
            </a></p>
        </div>
    <?php endif; ?>

    <?php if ($members_without_birth_date) : ?>
        <div class="notice notice-warning">
            <p><?php echo esc_html(count($members_without_birth_date)); ?> lid/leden zonder geboortedatum &mdash; hun contributie/kampbijdrage wordt gegenereerd met het volwassen tarief als aanname (gemarkeerd in rood bij hun bijdrage). Voeg de geboortedatum toe en genereer opnieuw om dit te corrigeren:</p>
            <ul style="margin-left:1.5em;list-style:disc">
                <?php foreach ($members_without_birth_date as $m) : ?>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=avpvh-member-detail&id=' . $m->id)); ?>" target="_blank"><?php echo esc_html(avpvh_format_name($m, 'list')); ?></a></li>
                <?php endforeach; ?>
            </ul>
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
</div>
