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
$all_flags = AVPVH_DB::get_all_flags();
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
    <p class="description">Leeftijd wordt bepaald op 1 januari van het jaar (contributie) of op de startdatum (kamp/activiteit met datum). Laat min/max leeg voor &ldquo;geen ondergrens&rdquo; / &ldquo;geen bovengrens&rdquo;. &ldquo;Voor scholieren/studenten&rdquo; is een status (ingesteld per lid op het profiel), geen leeftijdsgrens &mdash; die rij wint voor gemarkeerde leden, ongeacht leeftijd. Een rij aan een <strong>kenmerk</strong> koppelen (i.p.v. leeftijd/student) wint over alles &mdash; zet dan geen leeftijd/studentvinkje, die worden genegeerd.</p>
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
        <thead><tr><th>Label</th><th>Min. leeftijd</th><th>Max. leeftijd</th><th>Scholieren/studenten</th><th>Kenmerk</th><th>Tarief</th><th></th></tr></thead>
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
                    <td>
                        <select name="flag_id">
                            <option value="">&mdash; geen &mdash;</option>
                            <?php foreach ($all_flags as $flag) : ?>
                                <option value="<?php echo esc_attr($flag->id); ?>" <?php selected((int) ($rate->flag_id ?? 0), (int) $flag->id); ?>><?php echo esc_html($flag->label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
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
                <td>
                    <select name="flag_id">
                        <option value="">&mdash; geen &mdash;</option>
                        <?php foreach ($all_flags as $flag) : ?>
                            <option value="<?php echo esc_attr($flag->id); ?>"><?php echo esc_html($flag->label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
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

    <?php $payment_link = AVBK_DB::get_activity_payment_link($activity_id); ?>
    <details<?php echo (!empty($payment_link->payment_url) || !empty($payment_link->qr_image)) ? ' open' : ''; ?>>
    <summary><h2 style="display:inline">Generiek betaalverzoek (optioneel)</h2></summary>
    <p class="description">Eén gedeelde betaalverzoeklink/QR voor de hierboven gekozen activiteit (bijv. een ING Betaalverzoek- of Tikkie-link) — voor als je liever één link deelt dan voor iedereen apart een QR verstuurt. Wordt nergens automatisch getoond of in de "Vraag om betaling"-mail gezet; puur hier bewaard zodat je 'm makkelijk terugvindt.</p>
    <div style="display:flex; gap:2rem; flex-wrap:wrap; align-items:flex-start; margin-bottom:1.5rem">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="flex:1 1 320px; max-width:500px">
            <?php wp_nonce_field('avbk_save_activity_payment_url'); ?>
            <input type="hidden" name="action" value="avbk_save_activity_payment_url">
            <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
            <label for="avbk-payment-url">Betaalverzoeklink</label><br>
            <input type="text" id="avbk-payment-url" name="payment_url" class="regular-text" style="width:100%; box-sizing:border-box" placeholder="Plak hier de link (voorlooptekst wordt automatisch weggehaald)" value="<?php echo esc_attr($payment_link->payment_url ?? ''); ?>">
            <p>
                <?php submit_button('Opslaan', 'secondary small', 'submit', false); ?>
                <?php if (!empty($payment_link->payment_url)) : ?>
                    <a href="<?php echo esc_url($payment_link->payment_url); ?>" target="_blank" rel="noopener" class="button button-small">Openen</a>
                <?php endif; ?>
            </p>
        </form>
        <div style="flex:1 1 260px; max-width:320px">
            <p style="margin:0 0 .3rem">Betaalverzoek-QR</p>
            <div id="avbk-payment-qr-drop" style="border:2px dashed #999; border-radius:4px; min-height:160px; display:flex; align-items:center; justify-content:center; text-align:center; color:#888; padding:.75rem; box-sizing:border-box">
                <?php if (!empty($payment_link->qr_image)) : ?>
                    <img src="data:<?php echo esc_attr($payment_link->qr_image_mime); ?>;base64,<?php echo base64_encode($payment_link->qr_image); ?>" style="max-width:100%; max-height:220px">
                <?php else : ?>
                    Sleep hier een QR-code-afbeelding naartoe
                <?php endif; ?>
            </div>
            <?php if (!empty($payment_link->qr_image)) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:.4rem">
                    <?php wp_nonce_field('avbk_delete_activity_payment_qr'); ?>
                    <input type="hidden" name="action" value="avbk_delete_activity_payment_qr">
                    <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
                    <?php submit_button('QR verwijderen', 'secondary small', 'submit', false); ?>
                </form>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="avbk-payment-qr-form">
                <?php wp_nonce_field('avbk_save_activity_payment_qr'); ?>
                <input type="hidden" name="action" value="avbk_save_activity_payment_qr">
                <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
                <input type="hidden" name="qr_image_data" id="avbk-payment-qr-data">
                <input type="hidden" name="qr_image_mime" id="avbk-payment-qr-mime">
            </form>
        </div>
    </div>
    <script>
    (function () {
        var urlInput = document.getElementById('avbk-payment-url');
        function stripPrefix() {
            var m = urlInput.value.match(/https?:\/\/\S+/i);
            if (m && m[0] !== urlInput.value) {
                urlInput.value = m[0];
            }
        }
        // Runs on paste (after the pasted text lands) and on blur — not
        // on every keystroke, so someone can still type/edit a URL by
        // hand without each keystroke fighting the regex.
        urlInput.addEventListener('paste', function () { setTimeout(stripPrefix, 0); });
        urlInput.addEventListener('blur', stripPrefix);

        var drop = document.getElementById('avbk-payment-qr-drop');
        var dataInput = document.getElementById('avbk-payment-qr-data');
        var mimeInput = document.getElementById('avbk-payment-qr-mime');
        var qrForm = document.getElementById('avbk-payment-qr-form');
        drop.addEventListener('dragover', function (e) {
            e.preventDefault();
            drop.style.borderColor = '#2271b1';
        });
        drop.addEventListener('dragleave', function () {
            drop.style.borderColor = '#999';
        });
        drop.addEventListener('drop', function (e) {
            e.preventDefault();
            drop.style.borderColor = '#999';
            var file = (e.dataTransfer.files || [])[0];
            if (!file || file.type.indexOf('image') !== 0) {
                return;
            }
            var reader = new FileReader();
            reader.onload = function (ev) {
                var match = ev.target.result.match(/^data:([^;]+);base64,(.*)$/);
                if (!match) {
                    return;
                }
                mimeInput.value = match[1];
                dataInput.value = match[2];
                qrForm.submit();
            };
            reader.readAsDataURL(file);
        });
    })();
    </script>
    </details>
    <?php endif; ?>

    <h2>Instellingen</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('avbk_save_settings'); ?>
        <input type="hidden" name="action" value="avbk_save_settings">
        <table class="form-table" style="max-width:900px">
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
                    <textarea name="payment_email_login_text" rows="8" class="large-text" style="margin-top:.5rem; width:100%; max-width:700px;"><?php echo esc_textarea(get_option('avbk_payment_email_login_text', '') ?: AVBK_Admin::DEFAULT_PAYMENT_EMAIL_LOGIN_TEXT); ?></textarea>
                    <p class="description">Gebruik <code>[wachtwoord-link]</code> voor de klikbare link “hier” naar het scherm om in te loggen of een wachtwoord in te stellen. De QR-code en profiel-link blijven altijd in de betaalmail staan.</p>
                </td>
            </tr>
            <tr>
                <th><label for="qr_caption_text">Uitleg boven QR-code</label></th>
                <td>
                    <textarea id="qr_caption_text" name="qr_caption_text" rows="5" class="large-text" style="width:100%; max-width:700px;"><?php echo esc_textarea(get_option('avbk_qr_caption_text', '') ?: AVBK_Admin::DEFAULT_QR_CAPTION_TEXT); ?></textarea>
                    <p class="description">Tekst boven de QR-code in de "Vraag om betaling"-e-mail.</p>
                </td>
            </tr>
            <tr>
                <th><label for="generic_payment_link_text">Tekst bij generieke betaalverzoeklink</label></th>
                <td>
                    <textarea id="generic_payment_link_text" name="generic_payment_link_text" rows="3" class="large-text" style="width:100%; max-width:700px;"><?php echo esc_textarea(get_option('avbk_generic_payment_link_text', '') ?: AVBK_Admin::DEFAULT_GENERIC_PAYMENT_LINK_TEXT); ?></textarea>
                    <p class="description">Gebruik <code>[link]</code> voor de betaalverzoeklink zelf. Wordt toegevoegd via de knop "Betaalverzoeklink toevoegen" bij het opstellen van een "Vraag om betaling"-e-mail.</p>
                </td>
            </tr>
            <tr>
                <th colspan="2" style="padding-top:2rem;"><h3>Jubileumboek</h3></th>
            </tr>
            <tr>
                <th><label for="book_title">Titel jubileumboek</label></th>
                <td>
                    <input type="text" id="book_title" name="book_title" class="regular-text" style="width:100%; max-width:700px;" value="<?php echo esc_attr(get_option('avbk_book_title', AVBK_Book_Order::DEFAULT_TITLE)); ?>">
                </td>
            </tr>
            <tr>
                <th><label for="book_price">Prijs per exemplaar (&euro;)</label></th>
                <td>
                    <input type="number" step="0.50" id="book_price" name="book_price" class="small-text" value="<?php echo esc_attr(number_format((float) get_option('avbk_book_price', AVBK_Book_Order::DEFAULT_PRICE), 2, '.', '')); ?>">
                </td>
            </tr>
            <tr>
                <th><label for="book_price_note">Prijsnotitie (bijv. BTW)</label></th>
                <td>
                    <input type="text" id="book_price_note" name="book_price_note" class="regular-text" style="width:100%; max-width:700px;" value="<?php echo esc_attr(get_option('avbk_book_price_note', AVBK_Book_Order::DEFAULT_PRICE_NOTE)); ?>">
                    <p class="description">Zichtbaar op de bestelpagina bij de prijs (bijv. <code>Inclusief btw.</code>).</p>
                </td>
            </tr>
            <tr>
                <th><label for="book_distribution_notice">Uitleg distributie / afhalen</label></th>
                <td>
                    <textarea id="book_distribution_notice" name="book_distribution_notice" rows="2" class="large-text" style="width:100%; max-width:700px;"><?php echo esc_textarea(get_option('avbk_book_distribution_notice', AVBK_Book_Order::DEFAULT_DISTRIBUTION_NOTICE)); ?></textarea>
                    <p class="description">Melding over het niet per post verzenden van boeken.</p>
                </td>
            </tr>
            <tr>
                <th><label for="book_presentation_notice">Toelichting boekpresentatie</label></th>
                <td>
                    <textarea id="book_presentation_notice" name="book_presentation_notice" rows="2" class="large-text" style="width:100%; max-width:700px;"><?php echo esc_textarea(get_option('avbk_book_presentation_notice', AVBK_Book_Order::DEFAULT_PRESENTATION_NOTICE)); ?></textarea>
                    <p class="description">Uitleg boven de presentatie-vinkjes voor begin 2027.</p>
                </td>
            </tr>
            <tr>
                <th><label for="book_flaptekst">Flaptekst</label></th>
                <td>
                    <textarea id="book_flaptekst" name="book_flaptekst" rows="8" class="large-text" style="width:100%; max-width:700px;"><?php echo esc_textarea(get_option('avbk_book_flaptekst', AVBK_Book_Order::DEFAULT_FLAPTEKST)); ?></textarea>
                    <p class="description">Tekst die getoond wordt op de bestelpagina ([avpvh_bk_book_order]).</p>
                </td>
            </tr>
        </table>
        <?php submit_button('Instellingen opslaan'); ?>
    </form>
</div>
