<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$review_order = get_user_meta(get_current_user_id(), 'avbk_review_order', true) === 'desc' ? 'desc' : 'asc';
$queue = AVBK_DB::get_review_queue($review_order);
$duplicate_candidates = array_values(array_filter(
    AVBK_DB::get_transactions(),
    fn($candidate) => $candidate->direction === 'in' && empty($candidate->duplicate_of)
));
$all_members = AVBK_DB::get_payable_members();

// Every regel picks its own activiteit — no transactie-brede "Type" meer.
// Contributie/Kamp/Congres (AVBK_DB::activity_fee_type_map()'s keys) match
// against an existing, already-generated bijdrage-regel — but "Kamp" alone
// is ambiguous once there's more than one kamp in the list (this year's,
// last year's, ...), so the dropdown offers the concrete, dated activiteit
// ("Kamp Zonneveld (2026)") instead of the bare type; picking one matches
// unambiguously via that activiteit's own id. Recent = the past two years,
// since older activiteiten are already settled and just clutter the list.
// Every other type name (Drank, Eten, Weekend, ..., Overig) isn't tied to
// a specific dated activiteit at all — it creates a brand new, already-paid
// regel on the spot instead, same as before.
$fee_type_map = AVBK_DB::activity_fee_type_map();
$recent_activity_cutoff_year = (int) current_time('Y') - 1;
$recent_activities = array_values(array_filter(
    AVPVH_DB::get_activities(),
    fn($a) => (int) $a->year >= $recent_activity_cutoff_year
));
$other_activity_type_names = array_values(array_diff(
    wp_list_pluck(AVPVH_DB::get_activity_types(), 'name'),
    array_keys($fee_type_map)
));
$other_activity_type_names[] = 'Overig'; // fixed fallback (auto-vult de omschrijving met de bank-omschrijving), niet uit de tabel


function avbk_member_select(string $name, array $members, int $selected_id = 0): void {
    ?>
    <select name="<?php echo esc_attr($name); ?>">
        <option value="">&mdash; kies lid &mdash;</option>
        <?php foreach ($members as $m) : ?>
            <option value="<?php echo esc_attr($m->id); ?>" <?php selected($selected_id, (int) $m->id); ?>>
                <?php echo esc_html(avpvh_format_name($m, 'list')); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <a href="<?php echo esc_url($selected_id ? AVBK_DB::member_edit_url($selected_id) : '#'); ?>" target="_blank" class="avbk-detail-member-link"<?php echo $selected_id ? '' : ' style="display:none"'; ?>>bewerk lid</a>
    <?php
}

/**
 * $recent_activities: concrete avm_activities rows (id/name/year) from the
 * past two years, one per Contributie/Kamp/Congres instance — value
 * "a<id>", matched unambiguously against that one activiteit's own open fee
 * item. $other_type_names: everything else (Drank/Eten/.../Overig), not
 * tied to a dated activiteit — value is the bare type name, and creates a
 * brand new one-off regel instead of matching an existing one.
 */
function avbk_activity_select(string $name, array $recent_activities, array $other_type_names, string $selected = ''): void {
    ?>
    <select name="<?php echo esc_attr($name); ?>" class="avbk-activity-select">
        <option value="">&mdash; activiteit &mdash;</option>
        <?php if ($recent_activities) : ?>
        <optgroup label="Activiteiten">
            <?php foreach ($recent_activities as $a) :
                $value = 'a' . $a->id;
                ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($selected, $value); ?>>
                    <?php echo esc_html($a->name . ' (' . $a->year . ')'); ?>
                </option>
            <?php endforeach; ?>
        </optgroup>
        <?php endif; ?>
        <optgroup label="Overig">
            <?php foreach ($other_type_names as $type_name) : ?>
                <option value="<?php echo esc_attr($type_name); ?>" <?php selected($selected, $type_name); ?>>
                    <?php echo esc_html($type_name); ?>
                </option>
            <?php endforeach; ?>
        </optgroup>
    </select>
    <?php
}

/**
 * One row's "detail" — the live fragments (age/nights, estimated-amount
 * warning) shown next to its amount. A concrete avm_activities pick
 * ("a<id>") gets the full tarief-based detail (bedrag + age/nights); a
 * one-off category (Drank, Weekend, Overig, ...) has no tarief to compute
 * from, but still gets the member's scholier/student status as a hint —
 * see AVBK_DB::get_member_status_detail().
 */
function avbk_row_detail(array $row): ?array {
    $member_id = (int) ($row['member_id'] ?? 0);
    if (!$member_id) {
        return null;
    }
    $activity = (string) ($row['activity'] ?? '');
    if (preg_match('/^a(\d+)$/', $activity, $m)) {
        return AVBK_DB::get_member_fee_detail_for_activity($member_id, (int) $m[1]);
    }
    return AVBK_DB::get_member_status_detail($member_id);
}
?>
<script type="application/json" id="avbk-review-config"><?php echo wp_json_encode([
    'ajaxUrl'          => admin_url('admin-ajax.php'),
    'nonce'            => wp_create_nonce('avbk_review_queue'),
    'memberDetailUrl'  => admin_url('admin.php?page=avpvh-member-detail&id='),
]); ?></script>
<div class="wrap">
    <h1>Te controleren transacties</h1>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0.75rem 0 1rem">
        <?php wp_nonce_field('avbk_save_review_order'); ?>
        <input type="hidden" name="action" value="avbk_save_review_order">
        <label for="avbk-review-order"><strong>Volgorde:</strong></label>
        <select id="avbk-review-order" name="review_order" onchange="this.form.submit()">
            <option value="asc" <?php selected($review_order, 'asc'); ?>>Oudste eerst</option>
            <option value="desc" <?php selected($review_order, 'desc'); ?>>Nieuwste eerst</option>
        </select>
        <noscript><button type="submit" class="button">Toepassen</button></noscript>
        <span class="description">Oudste eerst voorkomt dat een oudere betaling per ongeluk wordt afgeboekt op posten die pas later zijn ontstaan.</span>
    </form>

    <?php if (isset($_GET['reset_year'])) : ?>
        <div class="notice notice-success">
            <p><?php echo esc_html((int) ($_GET['reset_count'] ?? 0)); ?> betaling(en) uit <?php echo esc_html((int) $_GET['reset_year']); ?> zijn teruggezet en kunnen hieronder opnieuw worden toegewezen.</p>
        </div>
    <?php elseif (isset($_GET['imported'])) : ?>
        <div class="notice notice-success">
            <p><?php echo esc_html((int) ($_GET['row_count'] ?? 0)); ?> nieuwe transactie(s) geïmporteerd,
               <?php echo esc_html((int) ($_GET['matched_count'] ?? 0)); ?> daarvan automatisch gekoppeld.</p>
        </div>
    <?php elseif (isset($_GET['confirmed'])) : ?>
        <?php if (!empty($_GET['underpaid'])) : ?>
            <div class="notice notice-error is-dismissible"><p>
                <strong>Onderbetaling verwerkt.</strong>
                Ontvangen &euro; <?php echo esc_html(number_format((float) $_GET['requested_total'] - (float) $_GET['underpaid'], 2, ',', '.')); ?>;
                geselecteerde openstaande bijdragen &euro; <?php echo esc_html(number_format((float) $_GET['requested_total'], 2, ',', '.')); ?>.
                Er blijft &euro; <?php echo esc_html(number_format((float) $_GET['underpaid'], 2, ',', '.')); ?> openstaan.
                Het ontvangen bedrag is van boven naar beneden over de gekozen regels verdeeld.
            </p></div>
        <?php elseif (!empty($_GET['remaining_open']) && !empty($_GET['unassigned'])) : ?>
            <div class="notice notice-error is-dismissible"><p>
                <strong>Gedeeltelijke verwerking.</strong>
                Van de ontvangen betaling is &euro; <?php echo esc_html(number_format((float) $_GET['unassigned'], 2, ',', '.')); ?> niet toegewezen;
                op de gekozen bijdragen blijft &euro; <?php echo esc_html(number_format((float) $_GET['remaining_open'], 2, ',', '.')); ?> openstaan.
            </p></div>
        <?php elseif (!empty($_GET['remaining_open'])) : ?>
            <div class="notice notice-error is-dismissible"><p>
                <strong>Gedeeltelijke betaling verwerkt.</strong>
                De ontvangen betaling is volledig toegewezen, maar van de geselecteerde bijdragen blijft
                &euro; <?php echo esc_html(number_format((float) $_GET['remaining_open'], 2, ',', '.')); ?> openstaan.
            </p></div>
        <?php elseif (!empty($_GET['unassigned'])) : ?>
            <div class="notice notice-error is-dismissible"><p>
                <strong>Overbetaling verwerkt.</strong>
                De gekozen bijdragen zijn afgeboekt; van de ontvangen betaling kon
                &euro; <?php echo esc_html(number_format((float) $_GET['unassigned'], 2, ',', '.')); ?> niet aan een bijdrage worden toegewezen.
            </p></div>
        <?php else : ?>
            <div class="notice notice-success"><p>Transactie bevestigd.</p></div>
        <?php endif; ?>
    <?php elseif (isset($_GET['confirm_failed'])) :
        $confirm_errors = get_transient('avbk_confirm_errors_' . get_current_user_id());
        delete_transient('avbk_confirm_errors_' . get_current_user_id());
        ?>
        <div class="notice notice-error">
            <p>Niet bevestigd — je invoer is bewaard als concept, maar er is nog niets verwerkt:</p>
            <ul style="list-style:disc;margin-left:1.5em">
                <?php foreach ((array) $confirm_errors as $error) : ?>
                    <li><?php echo wp_kses((string) $error, [
                        'a' => ['href' => true, 'target' => true, 'rel' => true],
                    ]); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php elseif (isset($_GET['draft_saved'])) : ?>
        <div class="notice notice-success"><p>Concept opgeslagen.</p></div>
    <?php elseif (isset($_GET['draft_cleared'])) : ?>
        <div class="notice notice-success"><p>Concept gewist.</p></div>
    <?php elseif (isset($_GET['ignored'])) : ?>
        <div class="notice notice-success"><p>Transactie genegeerd.</p></div>
    <?php elseif (isset($_GET['duplicate_marked'])) : ?>
        <div class="notice <?php echo $_GET['duplicate_marked'] === '1' ? 'notice-success' : 'notice-error'; ?>"><p><?php echo $_GET['duplicate_marked'] === '1' ? 'Transactie als dubbele betaling gemarkeerd.' : 'Transactie kon niet als dubbele betaling worden gemarkeerd.'; ?></p></div>
    <?php elseif (isset($_GET['restored'])) : ?>
        <div class="notice notice-success"><p>Genegeerde transactie is teruggezet voor controle.</p></div>
    <?php elseif (isset($_GET['recomputed'])) : ?>
        <div class="notice notice-success"><p><?php echo esc_html((int) $_GET['recomputed']); ?> transactie(s) opnieuw beoordeeld.</p></div>
    <?php endif; ?>

    <?php if ($queue) : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1rem">
            <?php wp_nonce_field('avbk_recompute_suggestions'); ?>
            <input type="hidden" name="action" value="avbk_recompute_suggestions">
            <button type="submit" class="button button-small">Suggesties opnieuw berekenen</button>
            <span class="description">Gebruik dit na een verbetering aan de koppel-logica &mdash; werkt de suggesties hieronder bij zonder het bankbestand opnieuw te hoeven uploaden. Een opgeslagen concept blijft ongewijzigd.</span>
        </form>
        <p class="description">Tip: klik op "bewerk lid" om iemands gegevens (o.a. scholier/student-status, geboortedatum) te wijzigen, of op de inschrijvingsgegevens ("inschrijving: ... nachten") om de overnachtingen van een kamp te wijzigen.</p>
    <?php else : ?>
        <p>Niets te controleren &mdash; alles is automatisch gekoppeld of er is nog niets geïmporteerd.</p>
    <?php endif; ?>

    <?php foreach ($queue as $tx) :
        $suggested_ids = array_filter(array_map('intval', explode(',', $tx->suggested_member_ids)));
        $suggested_types = array_values(array_filter(explode(',', $tx->suggested_type))); // activiteit-namen, bijv. ['Kamp','Contributie']
        $draft = AVBK_DB::get_transaction_draft((int) $tx->id);

        if ($draft !== null) {
            // A saved concept is a deliberate choice — never silently
            // replaced by a fresh suggestion computation.
            $rows = $draft;
        } else {
            // Activity names and the matching rules can change after a
            // transaction was imported. Re-evaluate the description when
            // rendering the queue so a stale, empty stored type does not
            // leave an obvious current match (for example "weekend")
            // unselected. Member suggestions remain stored; a saved draft
            // above remains an explicit treasurer choice.
            $suggested_types = AVBK_Matcher::classify_types((string) $tx->description);

            // Default each candidate's split to what they actually owe
            // (nights x day-rate for a camp, hun leeftijdstarief voor
            // contributie) rather than blindly splitting the payment
            // evenly. A description can name more than one activiteit at
            // once ("KAMP EN CONTRIBUTIE 2026") — one row per (lid,
            // activiteit) match, not one blended amount per lid, so kamp
            // en contributie voor dezelfde persoon apart blijven staan. A
            // bare type name ("Kamp") is resolved to that type's current
            // (most recent) concrete activiteit — the same guess the old
            // type-only matching made, just expressed as a specific
            // activiteit now instead of a type. A losse kostenpost-naam
            // (Drank/Eten/...) heeft geen bijdrage-regel om tegen te
            // matchen, dus het bedrag blijft een gok (evenredig deel van
            // het restbedrag) — maar de activiteit zelf hoeft niet leeg te
            // blijven staan als de omschrijving 'm al met naam noemt.
            $rows = [];
            $known_amount_sum = 0.0;
            foreach ($suggested_ids as $member_id) {
                $member_had_a_row = false;
                foreach ($suggested_types as $activity_name) {
                    // Automatic matching is only against a concrete,
                    // registered activity. Loose categories remain in the
                    // dropdown for an explicit human choice, but a mere
                    // activity-type name never creates a suggestion.
                    $activity_obj = AVBK_DB::get_current_activity_for_type_name($activity_name);
                    if (!$activity_obj) {
                        if (AVBK_Matcher::is_personal_one_off_type($activity_name)) {
                            $rows[] = ['member_id' => $member_id, 'activity' => $activity_name, 'description' => '', 'amount' => null];
                            $member_had_a_row = true;
                        }
                        continue;
                    }
                    $activity_value = 'a' . $activity_obj->id;
                    $detail = AVBK_DB::get_member_fee_detail_for_activity($member_id, (int) $activity_obj->id);
                    if ($detail['found']) {
                        // Show the full open charge. When the bank payment
                        // is smaller, the explicit total warning below lets
                        // the treasurer see both what was owed and what was
                        // actually received before reducing the allocation.
                        $rows[] = ['member_id' => $member_id, 'activity' => $activity_value, 'description' => '', 'amount' => $detail['share']];
                        $known_amount_sum += $detail['share'];
                    } else {
                        // A registered Weekend/Feest can intentionally
                        // start without a sheet or participant fee item.
                        // Keep the concrete activity suggestion anyway;
                        // its unknown amount is filled from the remaining
                        // transaction total below, and confirmation creates
                        // the participation + paid fee item. Previously the
                        // activity was blanked here solely because no list
                        // existed yet, even though the matcher had correctly
                        // recognized "Weekend" in the bank description.
                        $rows[] = ['member_id' => $member_id, 'activity' => $activity_value, 'description' => '', 'amount' => null];
                    }
                    $member_had_a_row = true;
                }
                if (!$member_had_a_row) {
                    $rows[] = ['member_id' => $member_id, 'activity' => '', 'description' => '', 'amount' => null];
                }
            }
            $unknown_indexes = array_keys(array_filter($rows, fn($r) => $r['amount'] === null));
            if ($unknown_indexes) {
                $remaining = max(0, round((float) $tx->amount - $known_amount_sum, 2));
                $even_share = round($remaining / count($unknown_indexes), 2);
                foreach ($unknown_indexes as $i) {
                    $rows[$i]['amount'] = $even_share;
                }
            }
        }
        ?>
        <div class="avbk-review-row" id="tx-<?php echo esc_attr($tx->id); ?>">
            <div class="avbk-review-row-header">
                <strong><?php echo esc_html(wp_date('D d M Y', strtotime($tx->transaction_date))); ?></strong>
                &mdash; <strong>&euro; <?php echo esc_html(number_format((float) $tx->amount, 2, ',', '.')); ?></strong>
                &mdash; <?php echo esc_html($tx->counterparty_name); ?>
                <?php if ($tx->import_batch_id) : ?>
                    <span class="description">(import #<?php echo esc_html($tx->import_batch_id); ?><?php echo !empty($tx->import_filename) ? ': ' . esc_html($tx->import_filename) : ''; ?><?php echo $tx->source_row ? ', regel ' . esc_html($tx->source_row) : ''; ?>)</span>
                <?php elseif ($tx->source_row) : ?><span class="description">(regel <?php echo esc_html($tx->source_row); ?>)</span><?php endif; ?>
                <?php if (!$suggested_ids && !$suggested_types && $draft === null) : ?><span class="avbk-badge avbk-badge-warn">geen suggestie</span><?php endif; ?>
                <?php if ($draft !== null) : ?><span class="avbk-badge avbk-badge-draft">concept</span><?php endif; ?>
            </div>
            <p class="description"><?php echo AVBK_Matcher::format_description_html(AVBK_Matcher::strip_name_field($tx->description)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- format_description_html() esc_html()'s the raw text first (see its own docblock), then only wraps already-safe hardcoded labels in <strong>. ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-review-form" data-tx-amount="<?php echo esc_attr(number_format((float) $tx->amount, 2, '.', '')); ?>" data-tx-description="<?php echo esc_attr($tx->description); ?>">
                <?php wp_nonce_field('avbk_transaction_row'); ?>
                <input type="hidden" name="transaction_id" value="<?php echo esc_attr($tx->id); ?>">

                <table class="avbk-review-split">
                    <thead><tr><th>Lid</th><th>Activiteit</th><th>Bedrag</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row) :
                        $d = avbk_row_detail($row);
                        $is_matched_activity = (bool) preg_match('/^a\d+$/', (string) ($row['activity'] ?? ''));
                        $row_amount = (float) ($row['amount'] ?? 0);
                        $open_amount = !empty($d['found']) ? (float) $d['share'] : 0.0;
                        $row_shortfall = max(0, round($open_amount - $row_amount, 2));
                        ?>
                        <tr>
                            <td><?php avbk_member_select('member_id[]', $all_members, (int) $row['member_id']); ?></td>
                            <td><?php avbk_activity_select('activity[]', $recent_activities, $other_activity_type_names, (string) $row['activity']); ?></td>
                            <td>
                                &euro; <input type="text" name="amount[]" class="avbk-amount-input" data-known="<?php echo !empty($d['found']) ? '1' : '0'; ?>" data-open-amount="<?php echo !empty($d['found']) ? esc_attr(number_format($open_amount, 2, '.', '')) : ''; ?>" value="<?php echo esc_attr(number_format($row_amount, 2, ',', '')); ?>" size="6">
                                <input type="text" name="description[]" class="avbk-row-description" placeholder="Omschrijving (optioneel)" value="<?php echo esc_attr($row['description'] ?? ''); ?>"<?php echo $is_matched_activity ? ' style="display:none"' : ''; ?>>
                                <span class="avbk-detail-fragments description"><?php echo $d['fragments_html'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built entirely from esc_html()'d/esc_url()'d fragments in AVBK_DB::get_member_fee_detail() (see that method's own comment), never raw input. ?></span>
                                <span class="avbk-detail-estimated<?php echo !empty($d['estimated_warning']) ? ' avbk-detail-estimated-warning' : ''; ?>"><?php echo esc_html($d['estimated_text'] ?? ''); ?></span>
                                <span class="avbk-detail-shortfall"><?php echo $row_shortfall > 0.005 ? esc_html('⚠ Gedeeltelijke betaling: € ' . number_format($row_shortfall, 2, ',', '.') . ' blijft voor deze bijdrage open.') : ''; ?></span>
                            </td>
                            <td><button type="button" class="button-link avbk-remove-row" title="Verwijder regel &mdash; het bedrag wordt herverdeeld over de overige regels">&times;</button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button button-small avbk-add-row">+ voeg regel toe</button></p>

                <!-- Cloned by review-queue.js when "+ voeg regel toe" is clicked. -->
                <template class="avbk-row-template">
                    <tr>
                        <td><?php avbk_member_select('member_id[]', $all_members); ?></td>
                        <td><?php avbk_activity_select('activity[]', $recent_activities, $other_activity_type_names); ?></td>
                        <td>
                            &euro; <input type="text" name="amount[]" class="avbk-amount-input" data-known="0" value="" size="6" placeholder="0,00">
                            <input type="text" name="description[]" class="avbk-row-description" placeholder="Omschrijving (optioneel)" value="">
                            <span class="avbk-detail-fragments description"></span>
                            <span class="avbk-detail-estimated"></span>
                            <span class="avbk-detail-shortfall"></span>
                        </td>
                        <td><button type="button" class="button-link avbk-remove-row" title="Verwijder regel &mdash; het bedrag wordt herverdeeld over de overige regels">&times;</button></td>
                    </tr>
                </template>

                <p class="avbk-review-total">
                    Totaal ingevuld: <span class="avbk-review-total-sum">&euro; 0,00</span>
                    van <span class="avbk-review-total-tx">&euro; <?php echo esc_html(number_format((float) $tx->amount, 2, ',', '.')); ?></span>
                    <span class="avbk-review-total-diff"></span>
                </p>

                <button type="submit" name="action" value="avbk_save_transaction_draft" class="button">Opslaan</button>
                <button type="submit" name="action" value="avbk_confirm_transaction" class="button button-primary">Bevestigen</button>
            </form>
            <?php if ($draft !== null) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-review-clear-draft-form">
                    <?php wp_nonce_field('avbk_transaction_row'); ?>
                    <input type="hidden" name="action" value="avbk_clear_transaction_draft">
                    <input type="hidden" name="transaction_id" value="<?php echo esc_attr($tx->id); ?>">
                    <?php submit_button('Concept wissen', 'secondary', 'submit', false); ?>
                </form>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-review-ignore-form">
                <?php wp_nonce_field('avbk_ignore_transaction'); ?>
                <input type="hidden" name="action" value="avbk_ignore_transaction">
                <input type="hidden" name="transaction_id" value="<?php echo esc_attr($tx->id); ?>">
                <?php submit_button('Negeren (geen bijdrage)', 'secondary', 'submit', false); ?>
                <span class="description">Markeert deze overschrijving als "hoort niet bij een bijdrage" &mdash; verdwijnt uit deze lijst, er wordt niets aangemaakt of afgeboekt. Gebruik dit voor bijv. een verkeerd bijgeschreven bedrag of iets dat niets met de vereniging te maken heeft.</span>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-review-ignore-form" style="margin-top:.5rem">
                <?php wp_nonce_field('avbk_mark_transaction_duplicate'); ?>
                <input type="hidden" name="action" value="avbk_mark_transaction_duplicate">
                <input type="hidden" name="transaction_id" value="<?php echo esc_attr($tx->id); ?>">
                <label>Dubbele betaling van:
                    <select name="duplicate_of" required>
                        <option value="">&mdash; kies de oorspronkelijke transactie &mdash;</option>
                        <?php foreach ($duplicate_candidates as $candidate) :
                            if ((int) $candidate->id === (int) $tx->id) continue;
                            ?>
                            <option value="<?php echo esc_attr($candidate->id); ?>">
                                #<?php echo esc_html($candidate->id); ?> — <?php echo esc_html(wp_date('d-m-Y', strtotime($candidate->transaction_date))); ?> — &euro; <?php echo esc_html(number_format((float) $candidate->amount, 2, ',', '.')); ?> — <?php echo esc_html($candidate->counterparty_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php submit_button('Markeer als dubbele betaling', 'secondary', 'submit', false); ?>
                <span class="description">Deze betaling verdwijnt uit de controlelijst, maar blijft zichtbaar in Alle transacties als duplicaat.</span>
            </form>
        </div>
    <?php endforeach; ?>
</div>
