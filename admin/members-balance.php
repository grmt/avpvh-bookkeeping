<?php
defined('ABSPATH') || exit;

/** Renders the admin/members-balance.php page. */
function avbk_render_members_balance(): void {
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$detail_member_id = (int) ($_GET['member_id'] ?? 0);
$members = AVPVH_DB::get_members(['status' => 'active']);
$closed_through_year = (int) get_option('avbk_closed_through_year', 0);
$show_all_years = !empty($_GET['show_all_years']);
$per_year = !empty($_GET['per_year']);
$current_book_year = (int) current_time('Y');
$settings = compact('closed_through_year', 'show_all_years', 'per_year', 'current_book_year');
?>
<div class="wrap">
    <h1>Ledenoverzicht</h1>

    <?php avbk_render_members_balance_notices(); ?>

    <?php if ($detail_member_id) :
        $member = AVPVH_DB::get_member($detail_member_id);
        if ($member) :
            ?>
            <?php avbk_render_member_balance_detail($member, $settings); ?>
        <?php endif;
    else : ?>
        <p class="description">Zoek of filter in de velden onder de kolomkoppen. Klik op <strong>Naam</strong>, <strong>Openstaand saldo</strong> of <strong>Status</strong> om te sorteren.</p>
        <?php avbk_render_members_overview_closed_years($closed_through_year, $show_all_years); ?>
        <?php avbk_render_members_overview_per_year($per_year, $current_book_year); ?>
        <div data-avpvh-sortable>
        <div class="avpvh-table-tools">
            <button type="button" class="button button-small avpvh-col-toggle-btn">Kolommen</button>
            <span class="avpvh-col-toggle-panel" hidden></span>
        </div>
        <div class="avpvh-table-wrap">
        <table data-storage-key="avbk_members_hidden_cols" class="widefat striped avpvh-sortable-table">
            <thead><tr>
                <th data-col="naam">Naam</th>
                <?php if ($per_year) : ?>
                    <th data-col="huidig" data-type="number">Huidig boekjaar</th>
                    <th data-col="vorig" data-type="number">Vorig boekjaar</th>
                    <th data-col="totaal" data-type="number">Totaal</th>
                <?php else : ?>
                    <th data-col="saldo" data-type="number">Openstaand saldo</th>
                <?php endif; ?>
                <th data-col="tegoed" data-type="number">Tegoed</th>
                <th data-col="status" data-filter="select">Status</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($members as $m) : ?>
                <?php avbk_render_members_overview_row($m, $per_year, $show_all_years); ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
    <?php endif; ?>
</div>
<?php
}

/**
 * One member's account: household credit, combinable household members,
 * the fee items with payments, and the payment request buttons.
 *
 * @param array<string, mixed> $settings $closed_through_year, $show_all_years, $per_year, $current_book_year.
 */
function avbk_render_member_balance_detail(object $member, array $settings): void {
    ['closed_through_year' => $closed_through_year, 'show_all_years' => $show_all_years, 'per_year' => $per_year, 'current_book_year' => $current_book_year] = $settings;
    $detail_member_id = (int) $member->id;
    ['household' => $household, 'also_ids' => $also_ids, 'also_members' => $also_members, 'household_members' => $household_members] = avbk_member_household_selection($member);
    $combined_members = [$detail_member_id => $member] + $also_members;
    $show_member_column = (bool) $also_members;
    ['items' => $shown_items, 'total' => $shown_total, 'current' => $combined_current, 'other' => $combined_other]
        = avbk_member_combined_balance($detail_member_id, $also_members, $settings);
?><h2><?php echo esc_html(avpvh_format_name($member)); ?></h2>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=avbk-members')); ?>">&larr; Terug naar ledenoverzicht</a>
                &nbsp;|&nbsp;
                <a href="<?php echo esc_url(AVBK_DB::member_edit_url($detail_member_id)); ?>" target="_blank">Bewerk lid (o.a. scholier/student, geboortedatum)</a>
                &nbsp;|&nbsp;
                <a href="<?php echo esc_url(add_query_arg(['page' => 'avbk-audit-log', 'member_id' => $detail_member_id], admin_url('admin.php'))); ?>">Logboek van dit lid</a>
            </p>
            <?php require_once AVBK_PLUGIN_DIR . 'admin/member-household-credit.php'; avbk_render_member_household_credit($detail_member_id, $household_members); ?>

            <?php avbk_render_member_credit_notices(); ?>
            <?php $available_credit = AVBK_DB::get_total_credit_for_member($detail_member_id); ?>

            <?php avbk_render_member_household_form($household, $detail_member_id, $show_all_years, $per_year, $also_ids); ?>

            <h3>Rekening van dit lid<?php echo $show_member_column ? ' en geselecteerde huisgenoten/familie' : ''; ?></h3>
            <?php avbk_render_member_balance_closed_years($closed_through_year, $show_all_years); ?>
            <?php avbk_render_member_balance_per_year($per_year, $current_book_year); ?>
            <?php avbk_render_member_student_years($detail_member_id); ?>
            <p class="description">Zoek of filter in de velden onder de kolomkoppen. Klik op een kolomkop om te sorteren.</p>
            <div data-avpvh-sortable>
            <div class="avpvh-table-tools">
                <button type="button" class="button button-small avpvh-col-toggle-btn">Kolommen</button>
                <span class="avpvh-col-toggle-panel" hidden></span>
            </div>
            <div class="avpvh-table-wrap">
            <table data-storage-key="avbk_member_detail_hidden_cols" class="widefat striped avpvh-sortable-table">
                <thead><tr>
                    <th>Kies</th>
                    <?php if ($show_member_column) : ?><th data-col="lid" data-filter="select">Lid</th><?php endif; ?>
                    <th data-col="omschrijving">Omschrijving</th>
                    <th data-col="bedrag" data-type="number">Bedrag</th>
                    <th data-col="betaald" data-type="number">Betaald</th>
                    <th>Betaling(en)</th>
                    <th data-col="openstaand" data-type="number">Openstaand</th>
                    <th data-col="status" data-filter="select">Status</th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php if ($available_credit > 0.005) :
                    $credit_transactions = AVBK_DB::get_credit_transactions_for_member($detail_member_id);
                    ?>
                    <?php avbk_render_member_credit_row($show_member_column, $member, $available_credit, $credit_transactions, $detail_member_id); ?>
                <?php endif; ?>
                <?php if (!$shown_items && $available_credit <= 0.005) : ?>
                    <tr><td colspan="<?php echo $show_member_column ? 9 : 8; ?>">Nog geen bijdragen geregistreerd.</td></tr>
                <?php elseif ($shown_items) : foreach ($shown_items as $item) : ?>
                    <?php avbk_render_member_fee_row($item, $show_member_column, $combined_members, $member); ?>
                <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <?php avbk_render_member_balance_totals($show_member_column, $per_year, $combined_current, $combined_other, $shown_total); ?>
                </tfoot>
            </table>
            </div>
            </div>
            <?php avbk_render_member_balance_actions($shown_total, $available_credit, $detail_member_id); ?>
<?php
}

/**
 * "Rekening combineren met" — same household-selection function as
 * [avpvh_bk_balance]'s own "Betaal ook voor" on the member's profile page
 * (AVPVH_DB::get_extended_household() + ?also[]=), so the treasurer can fold
 * a household/family member's open posten into this same table and
 * selection instead of handling each person's rekening one at a time.
 *
 * household_members is the full (unfiltered) household, keyed by id — needed
 * by member-household-credit.php, independent of which members are currently
 * checked into the combined rekening.
 *
 * @return array{household: object[], also_ids: int[], also_members: array<int, object>, household_members: array<int, object>}
 */
function avbk_member_household_selection(object $member): array {
    $detail_member_id = (int) $member->id;
    $household = array_values(array_filter(
        AVPVH_DB::get_extended_household($detail_member_id),
        fn($m) => (int) $m->id !== $detail_member_id
    ));
    $requested_also = array_map('intval', (array) ($_GET['also'] ?? []));
    $also_ids = array_values(array_intersect($requested_also, wp_list_pluck($household, 'id')));
    $also_members = [];
    $household_members = [$detail_member_id => $member];
    foreach ($household as $hm) {
        if (in_array((int) $hm->id, $also_ids, true)) {
            $also_members[(int) $hm->id] = $hm;
        }
        $household_members[(int) $hm->id] = $hm;
    }
    return ['household' => $household, 'also_ids' => $also_ids, 'also_members' => $also_members, 'household_members' => $household_members];
}

/**
 * The shown fee items and totals of a member plus the combined household
 * members.
 *
 * Default view: this book year only. A not-yet-closed previous year's items
 * only appear once "per boekjaar" is switched on — an already-closed year
 * stays hidden either way (that's the separate "toon oudere jaren" toggle).
 *
 * @param array<int, object>   $also_members
 * @param array<string, mixed> $settings $show_all_years, $per_year, $current_book_year.
 * @return array{items: object[], total: float, current: float, other: float}
 */
function avbk_member_combined_balance(int $member_id, array $also_members, array $settings): array {
    ['show_all_years' => $show_all_years, 'per_year' => $per_year, 'current_book_year' => $current_book_year] = $settings;
    $shown = static fn(array $by_year) => $per_year
        ? $by_year['items']
        : array_values(array_filter($by_year['items'], fn($item) => AVBK_DB::fee_item_book_year($item) === $current_book_year));
    $by_year = AVBK_DB::get_member_balance_by_year($member_id, $show_all_years);
    $shown_items = $shown($by_year);
    $shown_total = $per_year ? $by_year['total'] : $by_year['current'];
    $combined_current = $by_year['current'];
    $combined_other = $by_year['other'];
    foreach (array_keys($also_members) as $also_id) {
        $also_by_year = AVBK_DB::get_member_balance_by_year($also_id, $show_all_years);
        $shown_items = array_merge($shown_items, $shown($also_by_year));
        $shown_total = round($shown_total + ($per_year ? $also_by_year['total'] : $also_by_year['current']), 2);
        $combined_current = round($combined_current + $also_by_year['current'], 2);
        $combined_other = round($combined_other + $also_by_year['other'], 2);
    }
    return ['items' => $shown_items, 'total' => $shown_total, 'current' => $combined_current, 'other' => $combined_other];
}

/** Result notices of waiving, amounts and payment requests. */
function avbk_render_members_balance_notices(): void {
    if (isset($_GET['waived'])) : ?>
        <div class="notice notice-success"><p>Bijdrage kwijtgescholden.</p></div>
    <?php elseif (isset($_GET['amount_updated'])) : ?>
        <div class="notice notice-success"><p>Bedrag bijgewerkt.</p></div>
    <?php elseif (isset($_GET['payment_requested'])) : ?>
        <div class="notice notice-success"><p>Betaalverzoek verstuurd.</p></div>
    <?php elseif (isset($_GET['payment_request_failed'])) :
        $failure = sanitize_key(wp_unslash($_GET['payment_request_failed']));
        $failure_messages = [
            'no_member'     => 'Het lid is niet gevonden.',
            'no_selection'  => 'Er is geen openstaande post aangevinkt (de selectie kwam niet bij de server aan).',
            'not_open'      => 'De aangevinkte posten staan niet (meer) open, of horen niet bij dit lid of de gekozen huisgenoten.',
            'unknown_mode'  => 'Onbekende actie; gebruik een van de knoppen onder de tabel.',
            'invalid_email' => 'Dit lid heeft geen geldig e-mailadres.',
        ];
        if ($failure === 'mail_failed') {
            $mail_error = (string) get_transient('avbk_payment_mail_error_' . get_current_user_id());
            delete_transient('avbk_payment_mail_error_' . get_current_user_id());
            $failure_message = 'De e-mail kon niet worden verstuurd' . ($mail_error !== '' ? ': ' . $mail_error : '.');
        } else {
            $failure_message = $failure_messages[$failure] ?? 'Het betaalverzoek is niet verstuurd.';
        }
        ?>
        <div class="notice notice-error"><p><?php echo esc_html($failure_message); ?></p></div>
    <?php endif; ?>
<?php
}

/** Result notices of settling or donating credit. */
function avbk_render_member_credit_notices(): void {
    if (isset($_GET['credit_applied'])) : ?>
                <div class="notice notice-success inline"><p>Tegoed verrekend. Het lid krijgt hierna automatisch een mailtje.</p></div>
            <?php elseif (isset($_GET['credit_donated'])) : ?>
                <div class="notice notice-success inline"><p>Tegoed geboekt als schenking. Het lid krijgt hierna automatisch een mailtje.</p></div>
            <?php elseif (isset($_GET['credit_apply_failed'])) : ?>
                <div class="notice notice-error inline"><p>Niets verrekend: vink minimaal één openstaande post én het tegoed aan.</p></div>
            <?php endif; ?>
<?php
}

/** Checkboxes to combine household members' open items into this account. */
function avbk_render_member_household_form($household, $detail_member_id, $show_all_years, $per_year, $also_ids): void {
    if ($household) : ?>
                <form method="get" style="margin:.5rem 0 1rem">
                    <input type="hidden" name="page" value="avbk-members">
                    <input type="hidden" name="member_id" value="<?php echo esc_attr($detail_member_id); ?>">
                    <?php if ($show_all_years) : ?><input type="hidden" name="show_all_years" value="1"><?php endif; ?>
                    <?php if ($per_year) : ?><input type="hidden" name="per_year" value="1"><?php endif; ?>
                    <fieldset>
                        <legend><strong>Rekening combineren met:</strong></legend>
                        <?php foreach ($household as $hm) : ?>
                            <label style="margin-right:1rem">
                                <input type="checkbox" name="also[]" value="<?php echo esc_attr($hm->id); ?>" <?php checked(in_array((int) $hm->id, $also_ids, true)); ?> onchange="this.form.requestSubmit()">
                                <?php echo esc_html(avpvh_format_name($hm)); ?>
                            </label>
                        <?php endforeach; ?>
                        <noscript><button type="submit" class="button button-small">Toepassen</button></noscript>
                    </fieldset>
                    <p class="description">Open posten van geselecteerde huisgenoten/familie verschijnen hieronder in dezelfde tabel en kunnen samen geselecteerd worden voor een betaalverzoek/QR. Het tegoed hierboven blijft dat van dit lid zelf.</p>
                </form>
            <?php endif; ?>
<?php
}

/** Notice about closed years in the account. */
function avbk_render_member_balance_closed_years($closed_through_year, $show_all_years): void {
    if ($closed_through_year) : ?>
                <p class="description">
                    <?php if ($show_all_years) : ?>
                        Toont ook bijdragen tot en met <?php echo esc_html($closed_through_year); ?> (afgesloten).
                        <a href="<?php echo esc_url(remove_query_arg('show_all_years')); ?>">Verberg afgesloten jaren</a>.
                    <?php else : ?>
                        Bijdragen tot en met <?php echo esc_html($closed_through_year); ?> zijn afgesloten en worden hier verborgen.
                        <a href="<?php echo esc_url(add_query_arg('show_all_years', '1')); ?>">Toon oudere jaren</a>.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
<?php
}

/** Notice about the per-book-year view of the account. */
function avbk_render_member_balance_per_year($per_year, $current_book_year): void {
?><p class="description">
                <?php if ($per_year) : ?>
                    Toont <?php echo esc_html($current_book_year); ?> en het (nog niet afgesloten) vorige boekjaar apart.
                    <a href="<?php echo esc_url(remove_query_arg('per_year')); ?>">Verberg per boekjaar</a>.
                <?php else : ?>
                    Toont alleen boekjaar <?php echo esc_html($current_book_year); ?>.
                    <a href="<?php echo esc_url(add_query_arg('per_year', '1')); ?>">Toon per boekjaar</a>.
                <?php endif; ?>
            </p>
<?php
}

/** Recorded student status on 1 January per year, with add and delete forms. */
function avbk_render_member_student_years($detail_member_id): void {
    $student_years = AVBK_DB::get_member_student_years($detail_member_id); ?>
            <?php if (isset($_GET['student_year_saved'])) : ?><div class="notice notice-success inline"><p>Studentstatus op 1 januari opgeslagen. Genereer de betreffende bijdrage opnieuw om het bedrag bij te werken.</p></div><?php endif; ?>
            <details style="margin:1rem 0"<?php echo isset($_GET['student_year_saved']) ? ' open' : ''; ?>>
                <summary><strong>Historische studentstatus op 1 januari</strong></summary>
                <p class="description">Een vastgelegde jaarstatus gaat voor op het huidige studentvinkje en wordt gebruikt voor alle activiteiten in dat kalenderjaar.</p>
                <?php if ($student_years) : ?>
                    <table class="widefat striped" style="max-width:520px;margin:.5rem 0"><thead><tr><th>Jaar</th><th>Status op 1 januari</th><th></th></tr></thead><tbody>
                    <?php foreach ($student_years as $student_year) : ?><tr>
                        <td><?php echo esc_html($student_year->year); ?></td>
                        <td><?php echo $student_year->is_student ? 'Scholier/student' : 'Geen scholier/student'; ?></td>
                        <td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('avbk_delete_student_year'); ?>
                            <input type="hidden" name="action" value="avbk_delete_student_year"><input type="hidden" name="member_id" value="<?php echo esc_attr($detail_member_id); ?>"><input type="hidden" name="year" value="<?php echo esc_attr($student_year->year); ?>">
                            <button class="button button-small" type="submit">Verwijderen</button>
                        </form></td>
                    </tr><?php endforeach; ?>
                    </tbody></table>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('avbk_save_student_year'); ?>
                    <input type="hidden" name="action" value="avbk_save_student_year"><input type="hidden" name="member_id" value="<?php echo esc_attr($detail_member_id); ?>">
                    <label>Jaar <input type="number" name="year" min="1900" max="2200" value="<?php echo esc_attr(current_time('Y')); ?>" required style="width:7em"></label>
                    <label style="margin-left:1rem"><input type="checkbox" name="is_student" value="1"> Scholier/student op 1 januari</label>
                    <button class="button" type="submit">Jaarstatus opslaan</button>
                </form>
            </details>
<?php
}

/** The available credit as the first row of the account, with its transactions and the donate form. */
function avbk_render_member_credit_row($show_member_column, $member, $available_credit, $credit_transactions, $detail_member_id): void {
?><tr style="background:#f0f6fc">
                        <td><label class="screen-reader-text" for="avbk-apply-credit">Tegoed verrekenen</label><input type="checkbox" name="apply_credit" id="avbk-apply-credit" value="1" form="avbk-payment-selection"></td>
                        <?php if ($show_member_column) : ?><td><?php echo esc_html(avpvh_format_name($member)); ?></td><?php endif; ?>
                        <td><strong>Tegoed</strong> <span class="description">(eerdere betaling was meer dan op dat moment openstond)</span></td>
                        <td>&euro; <?php echo esc_html(number_format($available_credit, 2, ',', '.')); ?></td>
                        <td>&mdash;</td>
                        <td>
                            <?php foreach ($credit_transactions as $credit_tx) :
                                $credit_tx_url = add_query_arg(
                                    ['page' => 'avbk-transactions', 'show_all_years' => '1'],
                                    admin_url('admin.php')
                                ) . '#tx-' . (int) $credit_tx->id;
                                ?>
                                <div>
                                    <a href="<?php echo esc_url($credit_tx_url); ?>">
                                        <?php echo esc_html(wp_date('d-m-Y', strtotime($credit_tx->transaction_date))); ?>
                                        &mdash; transactie #<?php echo esc_html($credit_tx->id); ?>
                                    </a>
                                    (&euro; <?php echo esc_html(number_format((float) $credit_tx->credit, 2, ',', '.')); ?>)
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <td style="color:#2271b1;font-weight:bold">&euro; <?php echo esc_html(number_format($available_credit, 2, ',', '.')); ?></td>
                        <td>Beschikbaar</td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                <?php wp_nonce_field('avbk_donate_credit'); ?>
                                <input type="hidden" name="action" value="avbk_donate_credit">
                                <input type="hidden" name="member_id" value="<?php echo esc_attr($detail_member_id); ?>">
                                <input type="hidden" name="amount" value="<?php echo esc_attr(number_format($available_credit, 2, ',', '')); ?>">
                                <button type="submit" class="button button-small" onclick="return confirm('Dit tegoed boeken als schenking aan de vereniging? Dit kan niet ongedaan gemaakt worden.');">Schenking</button>
                            </form>
                        </td>
                    </tr>
<?php
}

/** One fee item in the member's account. */
function avbk_render_member_fee_row(object $item, bool $show_member_column, array $combined_members, object $member): void {
    $payments = AVBK_DB::get_payments_for_fee_item((int) $item->id);
    $item_owner = $combined_members[(int) $item->member_id] ?? $member;
?><tr>
                        <td>
                            <?php avbk_render_member_fee_select($item); ?>
                        </td>
                        <?php if ($show_member_column) : ?><td><?php echo esc_html(avpvh_format_name($item_owner)); ?></td><?php endif; ?>
                        <td>
                            <?php avbk_render_member_fee_description($item); ?>
                        </td>
                        <td>
                            <?php avbk_render_member_fee_amount($item); ?>
                        </td>
                        <td>&euro; <?php echo esc_html(number_format((float) $item->paid, 2, ',', '.')); ?></td>
                        <td>
                            <?php avbk_render_member_fee_payments($payments); ?>
                        </td>
                        <td>&euro; <?php echo esc_html(number_format((float) $item->remaining, 2, ',', '.')); ?></td>
                        <td><?php echo $item->status === 'waived' ? 'Kwijtgescholden' : ($item->remaining <= 0.005 ? 'Betaald' : 'Open'); ?></td>
                        <td>
                            <?php avbk_render_member_fee_waive($item); ?>
                        </td>
                    </tr>
<?php
}

/** The checkbox that selects an open fee item. */
function avbk_render_member_fee_select($item): void {
    if ($item->status === 'open' && $item->remaining > 0.005) : ?>
                                <label class="screen-reader-text" for="avbk-fee-item-<?php echo esc_attr($item->id); ?>">Kies <?php echo esc_html($item->description); ?></label>
                                <input type="checkbox" name="fee_item_ids[]" value="<?php echo esc_attr($item->id); ?>" id="avbk-fee-item-<?php echo esc_attr($item->id); ?>" form="avbk-payment-selection">
                            <?php else : ?>&mdash;<?php endif; ?>
<?php
}

/** Description of a fee item, with mail and estimate notes. */
function avbk_render_member_fee_description($item): void {
    echo esc_html($item->description); ?>
                            <?php if (!empty($item->donation_email_sent_at)) : ?>
                                <br><span style="color:#646970;font-style:italic">mail verzonden <?php echo esc_html(wp_date('d-m-Y', strtotime($item->donation_email_sent_at))); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item->is_estimated)) : ?>
                                <?php $estimate_reason = $item->estimate_reason ?: 'Geschat bedrag.';
                                $estimate_is_warning = !str_starts_with($estimate_reason, 'Alleen geboortejaar '); ?>
                                <br><span<?php echo $estimate_is_warning ? ' style="color:#b32d2e;font-weight:600"' : ' style="color:#646970"'; ?>><?php echo $estimate_is_warning ? '&#9888; ' : ''; ?><?php echo esc_html($estimate_reason); ?></span>
                            <?php endif; ?>
<?php
}

/** The amount of a fee item, editable while it is open. */
function avbk_render_member_fee_amount($item): void {
    if ($item->status === 'open') :
                                $rate_options = !empty($item->activity_id) ? AVBK_DB::get_activity_rates((int) $item->activity_id) : [];
                                $datalist_id = 'avbk-rates-' . (int) $item->id;
                            ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:.3rem;align-items:center">
                                    <?php wp_nonce_field('avbk_update_fee_item_amount'); ?>
                                    <input type="hidden" name="action" value="avbk_update_fee_item_amount">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($item->id); ?>">
                                    <input type="hidden" name="member_id" value="<?php echo esc_attr($item->member_id); ?>">
                                    <label class="screen-reader-text" for="avbk-amount-due-<?php echo esc_attr($item->id); ?>">Bedrag voor <?php echo esc_html($item->description); ?></label>&euro; <input type="text" name="amount_due" id="avbk-amount-due-<?php echo esc_attr($item->id); ?>" value="<?php echo esc_attr(number_format((float) $item->amount_due, 2, ',', '')); ?>" size="6"<?php echo $rate_options ? ' list="' . esc_attr($datalist_id) . '"' : ''; ?>>
                                    <?php avbk_render_member_fee_rate_options($rate_options, $datalist_id); ?>
                                    <button type="submit" class="button button-small">Opslaan</button>
                                </form>
                            <?php else : ?>
                                &euro; <?php echo esc_html(number_format((float) $item->amount_due, 2, ',', '.')); ?>
                            <?php endif; ?>
<?php
}

/** Suggested amounts from the activity's rates. */
function avbk_render_member_fee_rate_options($rate_options, $datalist_id): void {
    if ($rate_options) : ?>
                                        <datalist id="<?php echo esc_attr($datalist_id); ?>">
                                            <?php foreach ($rate_options as $rate) : ?>
                                                <option value="<?php echo esc_attr(number_format((float) $rate->rate, 2, ',', '')); ?>" label="<?php echo esc_attr(($rate->label !== '' ? $rate->label : '€') . ' (€' . number_format((float) $rate->rate, 2, ',', '.') . ')'); ?>"></option>
                                            <?php endforeach; ?>
                                        </datalist>
                                    <?php endif; ?>
<?php
}

/** The payments allocated to a fee item. */
function avbk_render_member_fee_payments($payments): void {
    if (!$payments) : ?>
                                &mdash;
                            <?php else : foreach ($payments as $payment) :
                                $transaction_url = add_query_arg(
                                    ['page' => 'avbk-transactions', 'show_all_years' => '1'],
                                    admin_url('admin.php')
                                ) . '#tx-' . (int) $payment->transaction_id;
                                ?>
                                <div>
                                    <a href="<?php echo esc_url($transaction_url); ?>">
                                        <?php echo esc_html(wp_date('d-m-Y', strtotime($payment->transaction_date))); ?>
                                        &mdash; transactie #<?php echo esc_html($payment->transaction_id); ?>
                                    </a>
                                    (&euro; <?php echo esc_html(number_format((float) $payment->allocated_amount, 2, ',', '.')); ?>)
                                </div>
                            <?php endforeach; endif; ?>
<?php
}

/** The waive button of an open fee item. */
function avbk_render_member_fee_waive($item): void {
    if ($item->status === 'open' && $item->remaining > 0.005) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                    <?php wp_nonce_field('avbk_waive_fee_item'); ?>
                                    <input type="hidden" name="action" value="avbk_waive_fee_item">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($item->id); ?>">
                                    <input type="hidden" name="member_id" value="<?php echo esc_attr($item->member_id); ?>">
                                    <button type="submit" class="button button-small" onclick="return confirm('Deze bijdrage kwijtschelden?');">Kwijtschelden</button>
                                </form>
                            <?php endif; ?>
<?php
}

/** Total open of the member's account, per book year or in one sum. */
function avbk_render_member_balance_totals($show_member_column, $per_year, $combined_current, $combined_other, $shown_total): void {
    $foot_colspan = $show_member_column ? 6 : 5; ?>
                    <?php if ($per_year) : ?>
                        <tr>
                            <th scope="row" colspan="<?php echo (int) $foot_colspan; ?>">Totaal openstaand</th>
                            <td><strong>Huidig: &euro; <?php echo esc_html(number_format($combined_current, 2, ',', '.')); ?></strong></td>
                            <td><strong>Vorig: &euro; <?php echo esc_html(number_format($combined_other, 2, ',', '.')); ?></strong></td>
                            <td><strong>Totaal: &euro; <?php echo esc_html(number_format($shown_total, 2, ',', '.')); ?></strong></td>
                        </tr>
                    <?php else : ?>
                        <tr><th scope="row" colspan="<?php echo (int) $foot_colspan; ?>">Totaal openstaand</th><td colspan="3"><strong>&euro; <?php echo esc_html(number_format($shown_total, 2, ',', '.')); ?></strong></td></tr>
                    <?php endif; ?>
<?php
}

/** Payment request, QR and credit settlement buttons for the selection. */
function avbk_render_member_balance_actions($shown_total, $available_credit, $detail_member_id): void {
    if ($shown_total > 0.005 || $available_credit > 0.005) :
                ?>
                <form id="avbk-payment-selection" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('avbk_balance_selection'); ?>
                    <input type="hidden" name="action" value="avbk_balance_selection">
                    <input type="hidden" name="member_id" value="<?php echo esc_attr($detail_member_id); ?>">
                    <p class="description">Vink één of meerdere openstaande posten aan<?php echo $available_credit > 0.005 ? ', en het tegoed als je dat ermee wilt verrekenen' : ''; ?>.</p>
                    <?php if ($shown_total > 0.005) : ?>
                        <button type="submit" name="request_mode" value="show" class="button">Bekijk selectie en maak QR</button>
                        <button type="submit" name="request_mode" value="mail" class="button button-primary">Mail betaalverzoek voor selectie</button>
                    <?php endif; ?>
                    <?php if ($available_credit > 0.005) : ?>
                        <button type="submit" name="do_settle_credit" value="1" class="button button-primary">Verrekenen met tegoed</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
<?php
}

/** Notice about closed years in the overview. */
function avbk_render_members_overview_closed_years($closed_through_year, $show_all_years): void {
    if ($closed_through_year) : ?>
            <p class="description">
                <?php if ($show_all_years) : ?>
                    Toont ook bijdragen tot en met <?php echo esc_html($closed_through_year); ?> (afgesloten).
                    <a href="<?php echo esc_url(remove_query_arg('show_all_years')); ?>">Verberg afgesloten jaren</a>.
                <?php else : ?>
                    Bijdragen tot en met <?php echo esc_html($closed_through_year); ?> zijn afgesloten en tellen hier niet meer mee.
                    <a href="<?php echo esc_url(add_query_arg('show_all_years', '1')); ?>">Toon oudere jaren</a>.
                <?php endif; ?>
            </p>
        <?php endif; ?>
<?php
}

/** Notice about the per-book-year view in the overview. */
function avbk_render_members_overview_per_year($per_year, $current_book_year): void {
?><p class="description">
            <?php if ($per_year) : ?>
                Toont <?php echo esc_html($current_book_year); ?> en het (nog niet afgesloten) vorige boekjaar apart.
                <a href="<?php echo esc_url(remove_query_arg('per_year')); ?>">Verberg per boekjaar</a>.
            <?php else : ?>
                Toont alleen boekjaar <?php echo esc_html($current_book_year); ?>.
                <a href="<?php echo esc_url(add_query_arg('per_year', '1')); ?>">Toon per boekjaar</a>.
            <?php endif; ?>
        </p>
<?php
}

/** One member in the overview: open balance, credit and status. */
function avbk_render_members_overview_row($m, $per_year, $show_all_years): void {
    $by_year = AVBK_DB::get_member_balance_by_year((int) $m->id, $show_all_years);
    $has_estimate_warning = (bool) array_filter($by_year['items'], fn($i) =>
        !empty($i->is_estimated)
        && $i->status === 'open'
        && !str_starts_with((string) $i->estimate_reason, 'Alleen geboortejaar ')
    );
    $shown_balance = $per_year ? $by_year['total'] : $by_year['current'];
    $balance_status = $shown_balance > 0.005 ? 'Openstaand' : 'Betaald';
    $member_credit = AVBK_DB::get_total_credit_for_member((int) $m->id);
?><tr>
                    <td><?php echo esc_html(avpvh_format_name($m, 'list')); ?></td>
                    <?php avbk_render_members_overview_balance($per_year, $by_year, $has_estimate_warning, $shown_balance); ?>
                    <td<?php echo $member_credit > 0.005 ? ' style="color:#2271b1;font-weight:bold"' : ''; ?>>
                        <?php echo $member_credit > 0.005 ? '&euro; ' . esc_html(number_format($member_credit, 2, ',', '.')) : '&mdash;'; ?>
                    </td>
                    <td><?php echo esc_html($balance_status); ?></td>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=avbk-members&member_id=' . $m->id)); ?>">Details</a></td>
                </tr>
<?php
}

/** The open balance cells of a member: per book year or one sum. */
function avbk_render_members_overview_balance($per_year, $by_year, $has_estimate_warning, $shown_balance): void {
    if ($per_year) : ?>
                        <td<?php echo $by_year['current'] > 0.005 ? ' style="color:#b8600a;font-weight:bold"' : ''; ?>>&euro; <?php echo esc_html(number_format($by_year['current'], 2, ',', '.')); ?></td>
                        <td<?php echo $by_year['other'] > 0.005 ? ' style="color:#b8600a;font-weight:bold"' : ''; ?>>&euro; <?php echo esc_html(number_format($by_year['other'], 2, ',', '.')); ?></td>
                        <td<?php echo $by_year['total'] > 0.005 ? ' style="color:#b8600a;font-weight:bold"' : ''; ?>>
                            &euro; <?php echo esc_html(number_format($by_year['total'], 2, ',', '.')); ?>
                            <?php if ($has_estimate_warning) : ?>
                                <span style="color:#b32d2e" title="Bevat een geschat bedrag (geen geboortedatum bekend)">&#9888;</span>
                            <?php endif; ?>
                        </td>
                    <?php else : ?>
                        <td<?php echo $shown_balance > 0.005 ? ' style="color:#b8600a;font-weight:bold"' : ''; ?>>
                            &euro; <?php echo esc_html(number_format($shown_balance, 2, ',', '.')); ?>
                            <?php if ($has_estimate_warning) : ?>
                                <span style="color:#b32d2e" title="Bevat een geschat bedrag (geen geboortedatum bekend)">&#9888;</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
<?php
}
