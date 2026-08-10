<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$year = (int) ($_GET['year'] ?? current_time('Y'));
$rates = AVBK_DB::get_contribution_rates($year);

$camps = AVPVH_DB::get_camps();
$camp_id = (int) ($_GET['camp_id'] ?? 0);
if (!$camp_id) {
    $current_camp = AVPVH_DB::get_current_camp();
    $camp_id = $current_camp ? (int) $current_camp->id : ($camps ? (int) $camps[0]->id : 0);
}
$camp_rates = $camp_id ? AVBK_DB::get_camp_rates_for_camp($camp_id) : [];
?>
<div class="wrap">
    <h1>Tarieven &amp; instellingen</h1>

    <?php if (isset($_GET['rate_saved']) || isset($_GET['rate_deleted']) || isset($_GET['camp_rate_saved']) || isset($_GET['camp_rate_deleted']) || isset($_GET['settings_saved'])) : ?>
        <div class="notice notice-success"><p>Opgeslagen.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['contribution_fees_generated'])) : ?>
        <div class="notice notice-success"><p>Contributiebijdragen gegenereerd/bijgewerkt voor <?php echo esc_html($_GET['year'] ?? ''); ?>.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['camp_fees_generated'])) : ?>
        <div class="notice notice-success"><p><?php echo esc_html((int) $_GET['camp_fees_generated']); ?> kampbijdrage(n) gegenereerd/bijgewerkt.</p></div>
    <?php endif; ?>

    <h2>Contributietarieven</h2>
    <p class="description">Leeftijd wordt bepaald op 1 januari van het gekozen jaar. Laat min/max leeg voor &ldquo;geen ondergrens&rdquo; / &ldquo;geen bovengrens&rdquo;.</p>
    <form method="get" style="margin-bottom:1rem">
        <input type="hidden" name="page" value="avbk-rates">
        <label>Jaar: <input type="number" name="year" value="<?php echo esc_attr($year); ?>" style="width:6em"></label>
        <?php submit_button('Wisselen', 'secondary', '', false); ?>
    </form>

    <table class="wp-list-table widefat striped" style="max-width:700px">
        <thead><tr><th>Label</th><th>Min. leeftijd</th><th>Max. leeftijd</th><th>Bedrag</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rates as $rate) : ?>
            <tr>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('avbk_save_contribution_rate'); ?>
                    <input type="hidden" name="action" value="avbk_save_contribution_rate">
                    <input type="hidden" name="id" value="<?php echo esc_attr($rate->id); ?>">
                    <input type="hidden" name="year" value="<?php echo esc_attr($year); ?>">
                    <td><input type="text" name="label" value="<?php echo esc_attr($rate->label); ?>" style="width:100%"></td>
                    <td><input type="number" name="min_age" value="<?php echo esc_attr($rate->min_age); ?>" style="width:5em"></td>
                    <td><input type="number" name="max_age" value="<?php echo esc_attr($rate->max_age); ?>" style="width:5em"></td>
                    <td>&euro; <input type="text" name="amount" value="<?php echo esc_attr(number_format((float) $rate->amount, 2, ',', '')); ?>" style="width:6em"></td>
                    <td>
                        <button type="submit" class="button button-small">Opslaan</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                    <?php wp_nonce_field('avbk_delete_contribution_rate'); ?>
                    <input type="hidden" name="action" value="avbk_delete_contribution_rate">
                    <input type="hidden" name="id" value="<?php echo esc_attr($rate->id); ?>">
                    <input type="hidden" name="year" value="<?php echo esc_attr($year); ?>">
                    <button type="submit" class="button button-small" onclick="return confirm('Tarief verwijderen?');">Verwijderen</button>
                </form>
                    </td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avbk_save_contribution_rate'); ?>
                <input type="hidden" name="action" value="avbk_save_contribution_rate">
                <input type="hidden" name="id" value="0">
                <input type="hidden" name="year" value="<?php echo esc_attr($year); ?>">
                <td><input type="text" name="label" placeholder="bijv. Jeugd" style="width:100%"></td>
                <td><input type="number" name="min_age" style="width:5em"></td>
                <td><input type="number" name="max_age" style="width:5em"></td>
                <td>&euro; <input type="text" name="amount" placeholder="0,00" style="width:6em"></td>
                <td><button type="submit" class="button button-small button-primary">Toevoegen</button></td>
            </form>
        </tr>
        </tbody>
    </table>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0.75rem 0">
        <?php wp_nonce_field('avbk_generate_contribution_fees_now'); ?>
        <input type="hidden" name="action" value="avbk_generate_contribution_fees_now">
        <input type="hidden" name="year" value="<?php echo esc_attr($year); ?>">
        <button type="submit" class="button">Contributiebijdragen nu genereren/bijwerken voor <?php echo esc_html($year); ?></button>
        <span class="description">Draait normaal automatisch elke nacht &mdash; gebruik dit om meteen bij te werken na een tariefwijziging.</span>
    </form>

    <h2>Kampbijdrage per nacht</h2>
    <p class="description">Leeftijd wordt bepaald op de startdatum van het kamp. Net als bij contributie: meerdere leeftijdsgroepen per kamp mogelijk, bijv. kinderen 0 t/m 3 gratis, 4 t/m 12 &euro;10/nacht, overige deelnemers &euro;20/nacht. Laat min/max leeg voor &ldquo;geen ondergrens&rdquo; / &ldquo;geen bovengrens&rdquo;.</p>
    <form method="get" style="margin-bottom:1rem">
        <input type="hidden" name="page" value="avbk-rates">
        <label>Kamp:
            <select name="camp_id" onchange="this.form.submit()">
                <?php foreach ($camps as $camp) : ?>
                    <option value="<?php echo esc_attr($camp->id); ?>" <?php selected($camp_id, (int) $camp->id); ?>>
                        <?php echo esc_html($camp->name . ' (' . $camp->year . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <noscript><?php submit_button('Wisselen', 'secondary', '', false); ?></noscript>
    </form>

    <?php if (!$camp_id) : ?>
        <p>Nog geen kamp aangemaakt in AV-PvH Leden.</p>
    <?php else : ?>
    <table class="wp-list-table widefat striped" style="max-width:700px">
        <thead><tr><th>Label</th><th>Min. leeftijd</th><th>Max. leeftijd</th><th>Tarief per nacht</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($camp_rates as $rate) : ?>
            <tr>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('avbk_save_camp_rate'); ?>
                    <input type="hidden" name="action" value="avbk_save_camp_rate">
                    <input type="hidden" name="id" value="<?php echo esc_attr($rate->id); ?>">
                    <input type="hidden" name="camp_id" value="<?php echo esc_attr($camp_id); ?>">
                    <td><input type="text" name="label" value="<?php echo esc_attr($rate->label); ?>" style="width:100%"></td>
                    <td><input type="number" name="min_age" value="<?php echo esc_attr($rate->min_age); ?>" style="width:5em"></td>
                    <td><input type="number" name="max_age" value="<?php echo esc_attr($rate->max_age); ?>" style="width:5em"></td>
                    <td>&euro; <input type="text" name="day_rate" value="<?php echo esc_attr(number_format((float) $rate->day_rate, 2, ',', '')); ?>" style="width:6em"></td>
                    <td>
                        <button type="submit" class="button button-small">Opslaan</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                    <?php wp_nonce_field('avbk_delete_camp_rate'); ?>
                    <input type="hidden" name="action" value="avbk_delete_camp_rate">
                    <input type="hidden" name="id" value="<?php echo esc_attr($rate->id); ?>">
                    <input type="hidden" name="camp_id" value="<?php echo esc_attr($camp_id); ?>">
                    <button type="submit" class="button button-small" onclick="return confirm('Tarief verwijderen?');">Verwijderen</button>
                </form>
                    </td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avbk_save_camp_rate'); ?>
                <input type="hidden" name="action" value="avbk_save_camp_rate">
                <input type="hidden" name="id" value="0">
                <input type="hidden" name="camp_id" value="<?php echo esc_attr($camp_id); ?>">
                <td><input type="text" name="label" placeholder="bijv. Kinderen 4-12" style="width:100%"></td>
                <td><input type="number" name="min_age" style="width:5em"></td>
                <td><input type="number" name="max_age" style="width:5em"></td>
                <td>&euro; <input type="text" name="day_rate" placeholder="0,00" style="width:6em"></td>
                <td><button type="submit" class="button button-small button-primary">Toevoegen</button></td>
            </form>
        </tr>
        </tbody>
    </table>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0.75rem 0">
        <?php wp_nonce_field('avbk_generate_camp_fees_now'); ?>
        <input type="hidden" name="action" value="avbk_generate_camp_fees_now">
        <input type="hidden" name="camp_id" value="<?php echo esc_attr($camp_id); ?>">
        <button type="submit" class="button">Kampbijdragen genereren/bijwerken voor dit kamp</button>
        <span class="description">Nodig na het instellen/wijzigen van tarieven &mdash; bestaande deelnameregistraties genereren anders pas een bijdrage bij hun eerstvolgende wijziging.</span>
    </form>
    <?php endif; ?>

    <h2>Instellingen</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('avbk_save_settings'); ?>
        <input type="hidden" name="action" value="avbk_save_settings">
        <table class="form-table" style="max-width:600px">
            <tr>
                <th><label for="club_iban">IBAN vereniging</label></th>
                <td><input type="text" id="club_iban" name="club_iban" class="regular-text" value="<?php echo esc_attr(get_option('avbk_club_iban', 'NL35INGB0674059859')); ?>"></td>
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
        </table>
        <?php submit_button('Instellingen opslaan'); ?>
    </form>
</div>
