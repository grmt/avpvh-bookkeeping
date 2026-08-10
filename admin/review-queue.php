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
    <?php endif; ?>

    <?php if (!$queue) : ?>
        <p>Niets te controleren &mdash; alles is automatisch gekoppeld of er is nog niets geïmporteerd.</p>
    <?php endif; ?>

    <?php foreach ($queue as $tx) :
        $suggested_ids = array_filter(array_map('intval', explode(',', $tx->suggested_member_ids)));

        // Default each candidate's split to what they actually owe (nights
        // x day-rate for a camp, their age-bracket rate for contribution)
        // rather than blindly splitting the payment evenly — only members
        // with no determinable fee item fall back to an even share of
        // whatever's left.
        $known_shares = [];
        $known_nights = [];
        $known_dates = [];
        $known_edit_url = [];
        foreach ($suggested_ids as $member_id) {
            $item = $tx->suggested_type ? AVBK_DB::find_relevant_open_fee_item($member_id, $tx->suggested_type) : null;
            if ($item) {
                $known_shares[$member_id] = round((float) $item->amount_due - AVBK_DB::get_fee_item_paid((int) $item->id), 2);
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
        }
        $unknown_ids = array_values(array_diff($suggested_ids, array_keys($known_shares)));
        $remaining_for_unknown = round((float) $tx->amount - array_sum($known_shares), 2);
        $even_share = $unknown_ids ? round($remaining_for_unknown / count($unknown_ids), 2) : 0.0;
        ?>
        <div class="avbk-review-row">
            <div class="avbk-review-row-header">
                <strong><?php echo esc_html(wp_date('d M Y', strtotime($tx->transaction_date))); ?></strong>
                &mdash; <strong>&euro; <?php echo esc_html(number_format((float) $tx->amount, 2, ',', '.')); ?></strong>
                &mdash; <?php echo esc_html($tx->counterparty_name); ?>
                <?php if ($tx->status === 'unmatched') : ?><span class="avbk-badge avbk-badge-warn">geen suggestie</span><?php endif; ?>
            </div>
            <p class="description"><?php echo avbk_format_description($tx->description); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avbk-review-form">
                <?php wp_nonce_field('avbk_confirm_transaction'); ?>
                <input type="hidden" name="action" value="avbk_confirm_transaction">
                <input type="hidden" name="transaction_id" value="<?php echo esc_attr($tx->id); ?>">

                <label>
                    Type:
                    <select name="type">
                        <option value="">&mdash;</option>
                        <option value="contribution" <?php selected($tx->suggested_type, 'contribution'); ?>>Contributie</option>
                        <option value="camp" <?php selected($tx->suggested_type, 'camp'); ?>>Kamp</option>
                    </select>
                </label>

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
                                    $nights_text = isset($known_nights[$member_id])
                                        ? ', ' . esc_html($known_nights[$member_id]) . ' nacht' . ($known_nights[$member_id] === 1 ? '' : 'en')
                                        : '';
                                    $dates_text = isset($known_dates[$member_id])
                                        ? ' (' . esc_html(wp_date('d M', strtotime($known_dates[$member_id][0]))) . '&ndash;' . esc_html(wp_date('d M', strtotime($known_dates[$member_id][1]))) . ')'
                                        : '';
                                    ?>
                                    <span class="description">(eigen bijdrage<?php echo $nights_text . $dates_text; ?>)</span>
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
