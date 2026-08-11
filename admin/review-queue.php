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
            <button type="submit" class="button">Suggesties opnieuw berekenen</button>
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
        // mentions, not just one.
        $known_shares = [];
        $known_nights = [];
        $known_dates = [];
        $known_edit_url = [];
        $known_ages = [];
        $known_estimated = [];
        foreach ($suggested_ids as $member_id) {
            foreach ($suggested_types as $type) {
                $item = AVBK_DB::find_relevant_open_fee_item($member_id, $type);
                if (!$item) {
                    continue;
                }
                $known_shares[$member_id] = ($known_shares[$member_id] ?? 0)
                    + round((float) $item->amount_due - AVBK_DB::get_fee_item_paid((int) $item->id), 2);
                if (!empty($item->is_estimated)) {
                    $known_estimated[$member_id] = $item->estimate_reason ?: 'Geschat bedrag.';
                }

                if ($item->type === 'contribution') {
                    $member = AVPVH_DB::get_member($member_id);
                    // Student is a status, not an age bracket — showing an
                    // age next to a student-rate amount would misleadingly
                    // imply age is what set the price.
                    if ($member && !empty($member->is_student)) {
                        $known_ages[$member_id] = 'scholier/student';
                    } elseif ($member && $member->birth_date) {
                        $year = (int) ($item->year ?: current_time('Y'));
                        $known_ages[$member_id] = 'leeftijd: ' . AVBK_Fee_Generation::age_on((string) $member->birth_date, "$year-01-01") . ' jaar';
                    }
                }
                if ($item->type === 'camp' && $item->camp_id) {
                    $participation = AVPVH_DB::get_participation($member_id, (int) $item->camp_id);
                    if ($participation && $participation->nights) {
                        $known_nights[$member_id] = (int) $participation->nights;
                        // Actual dates present (not just the night count) —
                        // same "non-empty status = present" rule the
                        // Kampdeelname list itself uses for "Dagen aanwezig",
                        // so this always matches what that screen shows.
                        $days = AVPVH_DB::get_participation_days((int) $participation->id);
                        $present_dates = array_keys(array_filter($days, fn($status) => $status !== ''));
                        sort($present_dates);
                        if ($present_dates) {
                            $known_dates[$member_id] = [reset($present_dates), end($present_dates)];
                        }
                        $known_edit_url[$member_id] = add_query_arg([
                            'page' => 'avpvh-kampdeelname-detail',
                            'camp_id' => (int) $item->camp_id,
                            'id' => (int) $participation->id,
                        ], admin_url('admin.php'));
                    }
                }
            }
            if (isset($known_shares[$member_id])) {
                $known_shares[$member_id] = round($known_shares[$member_id], 2);
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
                        ?>
                        <tr>
                            <td><?php avbk_member_select("member_id[$row_index]", $all_members, $member_id); ?></td>
                            <td>&euro; <input type="text" name="amount[<?php echo esc_attr($row_index); ?>]" value="<?php echo esc_attr(number_format($share, 2, ',', '')); ?>" size="6">
                                <?php if (isset($known_shares[$member_id])) :
                                    // Both fragments can apply at once ("kamp en contributie" for the same person) — show each labeled separately rather than one label overwriting the other.
                                    $fragments = [];
                                    if (isset($known_ages[$member_id])) {
                                        $fragments[] = esc_html($known_ages[$member_id]);
                                    }
                                    if (isset($known_nights[$member_id])) {
                                        $nights_parts = [esc_html($known_nights[$member_id]) . ' nacht' . ($known_nights[$member_id] === 1 ? '' : 'en')];
                                        if (isset($known_dates[$member_id])) {
                                            $nights_parts[] = esc_html(wp_date('D d M', strtotime($known_dates[$member_id][0]))) . '&ndash;' . esc_html(wp_date('D d M', strtotime($known_dates[$member_id][1])));
                                        }
                                        $fragments[] = 'inschrijving: ' . implode(', ', $nights_parts);
                                    }
                                    ?>
                                    <?php if ($fragments) : ?>
                                        <span class="description"><?php echo implode(' &middot; ', $fragments); ?></span>
                                    <?php endif; ?>
                                    <?php if (isset($known_estimated[$member_id])) : ?>
                                        <br><span style="color:#b32d2e;font-weight:600">&#9888; <?php echo esc_html($known_estimated[$member_id]); ?></span>
                                    <?php endif; ?>
                                    <?php if (isset($known_edit_url[$member_id])) : ?>
                                        <a href="<?php echo esc_url($known_edit_url[$member_id]); ?>" target="_blank" class="description">wijzig overnachtingen</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php $row_index++; endforeach;

                    // A few blank slots to add members the suggestion missed, or to split a payment across more people than guessed.
                    for ($extra = 0; $extra < 3; $extra++, $row_index++) : ?>
                        <tr>
                            <td><?php avbk_member_select("member_id[$row_index]", $all_members); ?></td>
                            <td>&euro; <input type="text" name="amount[<?php echo esc_attr($row_index); ?>]" value="" size="6" placeholder="0,00"></td>
                        </tr>
                    <?php endfor; ?>
                </table>

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
