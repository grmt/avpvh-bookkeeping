<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

// Every activity — camps, "Contributie" (year in its own column), "Congres" (year in its own column), drank-
// afrekeningen, ... — everything a lid can owe a bijdrage for lives in the
// same AVPVH_DB::get_activities() list (renamed from a camp-only concept; see
// AV-PvH Leden -> Activiteiten).
$activities = AVPVH_DB::get_activities();
$activity_id = (int) ($_GET['activity_id'] ?? 0);
if (!$activity_id) {
    $current = AVPVH_DB::get_current_activity();
    $activity_id = $current ? (int) $current->id : ($activities ? (int) $activities[0]->id : 0);
}
$rates = $activity_id ? AVBK_DB::get_activity_rates($activity_id) : [];
$selected_activity = $activity_id ? AVPVH_DB::get_activity($activity_id) : null;
$is_contribution = $selected_activity && $selected_activity->type_name === 'Contributie';
$rate_copy_sources = array_values(array_filter($activities, static function ($activity) use ($activity_id) {
    return (int) $activity->id !== $activity_id && (bool) AVBK_DB::get_activity_rates((int) $activity->id);
}));
?>
<div class="wrap">
    <h1>Tarieven &amp; instellingen</h1>

    <?php if (isset($_GET['rate_saved']) || isset($_GET['rate_deleted']) || isset($_GET['settings_saved'])) : ?>
        <div class="notice notice-success"><p>Opgeslagen.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['contribution_fees_generated'])) : ?>
        <div class="notice notice-success"><p>Contributiebijdragen gegenereerd/bijgewerkt voor <?php echo esc_html($_GET['year'] ?? ''); ?>.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['camp_fees_generated'])) : ?>
        <div class="notice notice-success"><p><?php echo esc_html((int) $_GET['camp_fees_generated']); ?> bijdrage(n) gegenereerd/bijgewerkt.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['rates_copy'])) : ?>
        <?php if ($_GET['rates_copy'] === 'copied') : ?>
            <div class="notice notice-success"><p>Activiteittarieven overgenomen. Controleer ze hieronder en genereer daarna de bijdragen.</p></div>
        <?php elseif ($_GET['rates_copy'] === 'target_has_rates') : ?>
            <div class="notice notice-error"><p>Niet overgenomen: deze activiteit heeft al tarieven. Verwijder die eerst als je ze volledig wilt vervangen.</p></div>
        <?php elseif ($_GET['rates_copy'] === 'source_empty') : ?>
            <div class="notice notice-error"><p>Niet overgenomen: de gekozen bronactiviteit heeft geen tarieven.</p></div>
        <?php else : ?>
            <div class="notice notice-error"><p>De tarieven konden niet worden overgenomen.</p></div>
        <?php endif; ?>
    <?php endif; ?>

    <h2>Activiteittarieven</h2>
    <p class="description">Leeftijd wordt bepaald op 1 januari van het jaar (contributie) of op de startdatum (kamp/activiteit met datum). Laat min/max leeg voor &ldquo;geen ondergrens&rdquo; / &ldquo;geen bovengrens&rdquo;. &ldquo;Voor scholieren/studenten&rdquo; is een status (ingesteld per lid op het profiel), geen leeftijdsgrens &mdash; die rij wint voor gemarkeerde leden, ongeacht leeftijd.</p>
    <form method="get" style="margin-bottom:1rem">
        <input type="hidden" name="page" value="avbk-rates">
        <label>Activiteit:
            <select name="activity_id" onchange="this.form.submit()">
                <?php foreach ($activities as $activity) : ?>
                    <option value="<?php echo esc_attr($activity->id); ?>" <?php selected($activity_id, (int) $activity->id); ?>>
                        <?php echo esc_html($activity->name . ' (' . $activity->year . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <noscript><?php submit_button('Wisselen', 'secondary', '', false); ?></noscript>
    </form>

    <?php if (!$activity_id) : ?>
        <p>Nog geen activiteit aangemaakt in AV-PvH Leden &rarr; Activiteiten.</p>
    <?php else : ?>
    <?php if ($rate_copy_sources) : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0 0 1rem;padding:.75rem 1rem;background:#fff;border-left:4px solid #72aee6;max-width:768px">
            <?php wp_nonce_field('avbk_copy_activity_rates'); ?>
            <input type="hidden" name="action" value="avbk_copy_activity_rates">
            <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
            <label><strong>Tarieven overnemen van:</strong>
                <select name="source_activity_id" required>
                    <option value="">&mdash; kies eerdere activiteit &mdash;</option>
                    <?php foreach ($rate_copy_sources as $source_activity) : ?>
                        <option value="<?php echo esc_attr($source_activity->id); ?>">
                            <?php echo esc_html($source_activity->name . ' (' . $source_activity->year . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="button" onclick="return confirm('Alle tarieven van de gekozen activiteit overnemen?');">Tarieven overnemen</button>
            <p class="description">Dit kan alleen zolang de huidige activiteit nog geen tarieven heeft. Labels, leeftijdsgrenzen, studentstatus en bedragen worden gekopieerd.</p>
        </form>
    <?php endif; ?>
    <table class="wp-list-table widefat striped" style="max-width:800px">
        <thead><tr><th>Label</th><th>Min. leeftijd</th><th>Max. leeftijd</th><th>Scholieren/studenten</th><th>Tarief</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rates as $rate) : ?>
            <tr>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('avbk_save_activity_rate'); ?>
                    <input type="hidden" name="action" value="avbk_save_activity_rate">
                    <input type="hidden" name="id" value="<?php echo esc_attr($rate->id); ?>">
                    <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
                    <td><input type="text" name="label" value="<?php echo esc_attr($rate->label); ?>" style="width:100%"></td>
                    <td><input type="number" name="min_age" value="<?php echo esc_attr($rate->min_age); ?>" style="width:5em"></td>
                    <td><input type="number" name="max_age" value="<?php echo esc_attr($rate->max_age); ?>" style="width:5em"></td>
                    <td style="text-align:center"><input type="checkbox" name="for_students" value="1" <?php checked(!empty($rate->for_students)); ?>></td>
                    <td>&euro; <input type="text" name="rate" value="<?php echo esc_attr(number_format((float) $rate->rate, 2, ',', '')); ?>" style="width:6em"></td>
                    <td>
                        <button type="submit" class="button button-small">Opslaan</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                    <?php wp_nonce_field('avbk_delete_activity_rate'); ?>
                    <input type="hidden" name="action" value="avbk_delete_activity_rate">
                    <input type="hidden" name="id" value="<?php echo esc_attr($rate->id); ?>">
                    <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
                    <button type="submit" class="button button-small" onclick="return confirm('Tarief verwijderen?');">Verwijderen</button>
                </form>
                    </td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avbk_save_activity_rate'); ?>
                <input type="hidden" name="action" value="avbk_save_activity_rate">
                <input type="hidden" name="id" value="0">
                <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
                <td><input type="text" name="label" placeholder="bijv. Volwassenen" style="width:100%"></td>
                <td><input type="number" name="min_age" style="width:5em"></td>
                <td><input type="number" name="max_age" style="width:5em"></td>
                <td style="text-align:center"><input type="checkbox" name="for_students" value="1"></td>
                <td>&euro; <input type="text" name="rate" placeholder="0,00" style="width:6em"></td>
                <td><button type="submit" class="button button-small button-primary">Toevoegen</button></td>
            </form>
        </tr>
        </tbody>
    </table>

    <?php if ($is_contribution) : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0.75rem 0">
            <?php wp_nonce_field('avbk_generate_contribution_fees_now'); ?>
            <input type="hidden" name="action" value="avbk_generate_contribution_fees_now">
            <input type="hidden" name="year" value="<?php echo esc_attr($selected_activity->year); ?>">
            <button type="submit" class="button">Contributiebijdragen nu genereren/bijwerken voor <?php echo esc_html($selected_activity->year); ?></button>
            <span class="description">Draait normaal automatisch elke nacht &mdash; gebruik dit om meteen bij te werken na een tariefwijziging.</span>
        </form>
    <?php else : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0.75rem 0">
            <?php wp_nonce_field('avbk_generate_camp_fees_now'); ?>
            <input type="hidden" name="action" value="avbk_generate_camp_fees_now">
            <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
            <button type="submit" class="button">Bijdragen genereren/bijwerken voor deze activiteit</button>
            <span class="description">Nodig na het instellen/wijzigen van tarieven &mdash; bestaande deelnameregistraties genereren anders pas een bijdrage bij hun eerstvolgende wijziging. (Zonder gekoppelde deelnameregistraties, zoals bij Congres, is dit een no-op &mdash; die bijdragen ontstaan al bij aanmelding.)</span>
        </form>
    <?php endif; ?>
    <?php endif; ?>

    <h2>Instellingen</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('avbk_save_settings'); ?>
        <input type="hidden" name="action" value="avbk_save_settings">
        <table class="form-table" style="max-width:600px">
            <tr>
                <th><label for="club_iban">IBAN vereniging</label></th>
                <td><input type="text" id="club_iban" name="club_iban" class="regular-text" value="<?php echo esc_attr(get_option('avbk_club_iban', '')); ?>"></td>
            </tr>
            <tr>
                <th><label for="club_name">Naam op rekening</label></th>
                <td><input type="text" id="club_name" name="club_name" class="regular-text" value="<?php echo esc_attr(get_option('avbk_club_name', "Archeologische Vereniging Philips van Horne")); ?>"></td>
            </tr>
            <tr>
                <th><label for="reference_prefix">Betalingskenmerk-prefix</label></th>
                <td>
                    <input type="text" id="reference_prefix" name="reference_prefix" value="<?php echo esc_attr(get_option('avbk_reference_prefix', 'PVH')); ?>" style="width:8em">
                    <p class="description">Wordt gebruikt in de QR-code, bijv. &ldquo;PVH-42&rdquo;. Betalingen met dit kenmerk in de omschrijving worden automatisch gekoppeld.</p>
                </td>
            </tr>
            <tr>
                <th><label for="penningmeester_email">E-mail penningmeester</label></th>
                <td>
                    <input type="email" id="penningmeester_email" name="penningmeester_email" class="regular-text" value="<?php echo esc_attr(get_option('avbk_penningmeester_email', 'info@avphilipsvanhorne.nl')); ?>">
                    <p class="description">Hier komt een melding binnen als een lid bezwaar maakt tegen zijn/haar overzicht.</p>
                </td>
            </tr>
            <tr>
                <th><label for="penningmeester_name">Naam penningmeester</label></th>
                <td>
                    <input type="text" id="penningmeester_name" name="penningmeester_name" class="regular-text" value="<?php echo esc_attr(get_option('avbk_penningmeester_name', 'de penningmeester')); ?>">
                    <p class="description">Ondertekening van een "Vraag om betaling"-e-mail, bijv. "Nina".</p>
                </td>
            </tr>
            <tr>
                <th><label for="payment_email_login_help">Inloguitleg in betaalmail</label></th>
                <td>
                    <label><input type="checkbox" id="payment_email_login_help" name="payment_email_login_help" value="1" <?php checked((bool) get_option('avbk_payment_email_login_help', 1)); ?>> Voeg uitleg over wachtwoord, Google en Microsoft toe</label>
                    <textarea name="payment_email_login_text" rows="8" class="large-text" style="margin-top:.5rem"><?php echo esc_textarea(get_option('avbk_payment_email_login_text', AVBK_Admin::DEFAULT_PAYMENT_EMAIL_LOGIN_TEXT)); ?></textarea>
                    <p class="description">Gebruik <code>[wachtwoord-link]</code> voor de klikbare link “hier” naar het scherm om in te loggen of een wachtwoord in te stellen. De QR-code en profiel-link blijven altijd in de betaalmail staan.</p>
                </td>
            </tr>
        </table>
        <?php submit_button('Instellingen opslaan'); ?>
    </form>
</div>
