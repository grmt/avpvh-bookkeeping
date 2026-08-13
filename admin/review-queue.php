<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$queue = AVBK_DB::get_review_queue();
$all_members = AVPVH_DB::get_members(['status' => 'active']);

// The bank description is a flat "Naam: X Omschrijving: Y IBAN: Z ..."
// string — bold the field labels so it reads as a mini key/value list
// instead of a wall of text. Escape first, then bold the (already-safe,
// no HTML-special-character) label text; never the other way round.
function avbk_format_description(string $description): string {
    $escaped = esc_html($description);
    $labels = ['Naam:', 'Omschrijving:', 'IBAN:', 'Datum/Tijd:', 'Valutadatum:', 'Kenmerk:', 'Overige partij:', 'Mutatiesoort:'];
    foreach ($labels as $label) {
        $escaped = preg_replace('/(?<=^|\s)' . preg_quote($label, '/') . '/', '<strong>' . $label . '</strong>', $escaped);
    }
    return $escaped;
}

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
    <?php
}
?>
<script type="application/json" id="avbk-review-config"><?php echo wp_json_encode([
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce'   => wp_create_nonce('avbk_review_queue'),
]); ?></script>
<div class="wrap">
    <h1>Te controleren transacties</h1>

    <?php if (isset($_GET['imported'])) : ?>
        <div class="notice notice-success">
            <p><?php echo esc_html((int) ($_GET['row_count'] ?? 0)); ?> nieuwe transactie(s) geïmporteerd,
               <?php echo esc_html((int) ($_GET['matched_count'] ?? 0)); ?> daarvan automatisch gekoppeld.</p>
        </div>
    <?php elseif (isset($_GET['confirmed'])) : ?>
        <div class="notice notice-success"><p>Transactie bevestigd.</p></div>
    <?php elseif (isset($_GET['ignored'])) : ?>
        <div class="notice notice-success"><p>Transactie genegeerd.</p></div>
    <?php elseif (isset($_GET['recomputed'])) : ?>
        <div class="notice notice-success"><p><?php echo esc_html((int) $_GET['recomputed']); ?> transactie(s) opnieuw beoordeeld.</p></div>
    <?php endif; ?>

    <?php if ($queue) : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1rem">
            <?php wp_nonce_field('avbk_recompute_suggestions'); ?>
            <input type="hidden" name="action" value="avbk_recompute_suggestions">
            <button type="submit" class="button button-small">Suggesties opnieuw berekenen</button>
            <span class="description">Gebruik dit na een verbetering aan de koppel-logica &mdash; werkt de suggesties hieronder bij zonder het bankbestand opnieuw te hoeven uploaden.</span>
        </form>
    <?php else : ?>
        <p>Niets te controleren &mdash; alles is automatisch gekoppeld of er is nog niets geïmporteerd.</p>
    <?php endif; ?>

    <?php foreach ($queue as $tx) :
        $suggested_ids = array_filter(array_map('intval', explode(',', $tx->suggested_member_ids)));
        $suggested_types = array_values(array_filter(explode(',', $tx->suggested_type)));

        // Default each candidate's split to what they actually owe (nights
        // x day-rate for a camp, their age-bracket rate for contribution)
        // rather than blindly splitting the payment evenly — only members
        // with no determinable fee item fall back to an even share of
        // whatever's left. A description can name both fee types at once
        // ("KAMP EN CONTRIBUTIE 2026"), so sum across every type it
        // mentions, not just one. AVBK_DB::get_member_fee_detail() is the
        // same lookup the AJAX endpoint uses to refresh this when the
        // treasurer changes the selected member after page load.
        $known_detail = [];
        $known_shares = [];
        foreach ($suggested_ids as $member_id) {
            $known_detail[$member_id] = AVBK_DB::get_member_fee_detail($member_id, $suggested_types);
            if ($known_detail[$member_id]['found']) {
                $known_shares[$member_id] = $known_detail[$member_id]['share'];
            }
        }
        $unknown_ids = array_values(array_diff($suggested_ids, array_keys($known_shares)));
        $remaining_for_unknown = round((float) $tx->amount - array_sum($known_shares), 2);
        $even_share = $unknown_ids ? round($remaining_for_unknown / count($unknown_ids), 2) : 0.0;
        ?>
        <div class="avbk-review-row">
            <div class="avbk-review-row-header">
                <strong><?php echo esc_html(wp_date('D d M Y', strtotime($tx->transaction_date))); ?></strong>
                &mdash; <strong>&euro; <?php echo esc_html(number_format((float) $tx->amount, 2, ',', '.')); ?></strong>
                &mdash; <?php echo esc_html($tx->counterparty_name); ?>
                <?php if ($tx->status === 'unmatched') : ?><span class="avbk-badge avbk-badge-warn">geen suggestie</span><?php endif; ?>
            </div>
            <p class="description"><?php echo avbk_format_description($tx->description); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-review-form">
                <?php wp_nonce_field('avbk_confirm_transaction'); ?>
                <input type="hidden" name="action" value="avbk_confirm_transaction">
                <input type="hidden" name="transaction_id" value="<?php echo esc_attr($tx->id); ?>">

                <label>Type:</label>
                <label><input type="checkbox" name="type[]" value="contribution" <?php checked(in_array('contribution', $suggested_types, true)); ?>> Contributie</label>
                <label><input type="checkbox" name="type[]" value="camp" <?php checked(in_array('camp', $suggested_types, true)); ?>> Kamp</label>

                <table class="avbk-review-split">
                    <?php
                    $row_index = 0;
                    foreach ($suggested_ids as $member_id) :
                        $share = $known_shares[$member_id] ?? $even_share;
                        $d = $known_detail[$member_id];
                        ?>
                        <tr>
                            <td><?php avbk_member_select("member_id[$row_index]", $all_members, $member_id); ?></td>
                            <td>
                                &euro; <input type="text" name="amount[<?php echo esc_attr($row_index); ?>]" class="avbk-amount-input" value="<?php echo esc_attr(number_format($share, 2, ',', '')); ?>" size="6">
                                <span class="avbk-detail-fragments description"><?php echo $d['fragments_html']; ?></span>
                                <span class="avbk-detail-estimated"><?php echo esc_html($d['estimated_text']); ?></span>
                                <a href="<?php echo esc_url($d['nights_edit_url'] ?: '#'); ?>" target="_blank" class="avbk-detail-nights-link description"<?php echo $d['nights_edit_url'] ? '' : ' style="display:none"'; ?>>wijzig overnachtingen</a>
                                <a href="<?php echo esc_url($d['member_edit_url']); ?>" target="_blank" class="avbk-detail-member-link description">bewerk lid (o.a. scholier/student, geboortedatum)</a>
                            </td>
                        </tr>
                    <?php $row_index++; endforeach;

                    // A few blank slots to add members the suggestion missed, or to split a payment across more people than guessed — same live-updating markup, just empty until a member is picked.
                    for ($extra = 0; $extra < 3; $extra++, $row_index++) : ?>
                        <tr>
                            <td><?php avbk_member_select("member_id[$row_index]", $all_members); ?></td>
                            <td>
                                &euro; <input type="text" name="amount[<?php echo esc_attr($row_index); ?>]" class="avbk-amount-input" value="" size="6" placeholder="0,00">
                                <span class="avbk-detail-fragments description"></span>
                                <span class="avbk-detail-estimated"></span>
                                <a href="#" target="_blank" class="avbk-detail-nights-link description" style="display:none">wijzig overnachtingen</a>
                                <a href="#" target="_blank" class="avbk-detail-member-link description" style="display:none">bewerk lid (o.a. scholier/student, geboortedatum)</a>
                            </td>
                        </tr>
                    <?php endfor; ?>
                </table>

                <div class="avbk-review-extra">
                    <label>Overige regel (optioneel):</label>
                    <select name="extra_category">
                        <option value="">&mdash; geen &mdash;</option>
                        <?php foreach (['Drank', 'Eten', 'Boek', 'T-shirt', 'Congres'] as $cat) : ?>
                            <option value="<?php echo esc_attr($cat); ?>"><?php echo esc_html($cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="extra_description" placeholder="Omschrijving (optioneel)">
                    &euro; <input type="text" name="extra_amount" class="avbk-amount-input" placeholder="0,00" size="6">
                    <?php avbk_member_select('extra_member_id', $all_members); ?>
                    <p class="description">Voor een losse post buiten contributie/kamp om (drank, eten, boek, t-shirt, congres, ...) &mdash; wordt meteen als betaald geregistreerd voor het gekozen lid.</p>
                </div>

                <?php submit_button('Bevestigen', 'primary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-review-ignore-form">
                <?php wp_nonce_field('avbk_ignore_transaction'); ?>
                <input type="hidden" name="action" value="avbk_ignore_transaction">
                <input type="hidden" name="transaction_id" value="<?php echo esc_attr($tx->id); ?>">
                <?php submit_button('Negeren (geen bijdrage)', 'secondary', 'submit', false); ?>
            </form>
        </div>
    <?php endforeach; ?>
</div>
