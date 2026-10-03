<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$formats = AVBK_DB::get_iban_country_formats();
$selected_country = strtoupper(sanitize_text_field(wp_unslash($_GET['country'] ?? '')));
$known_country_codes = array_map(fn($format) => $format->country_code, $formats);
if (!in_array($selected_country, $known_country_codes, true)) {
    $selected_country = $formats ? $formats[0]->country_code : '';
}
$bank_codes = $selected_country ? AVBK_DB::get_iban_bank_codes($selected_country) : [];
$selected_format = null;
foreach ($formats as $format) {
    if ($format->country_code === $selected_country) {
        $selected_format = $format;
        break;
    }
}
?>
<div class="wrap">
    <h1>IBAN-bankcodes</h1>
    <p class="description">
        Stel per IBAN-land in waar de bankcode staat en koppel codes of codebereiken aan een banknaam.
        Hiermee worden de kolommen Bank en Land in de boekhouding opgebouwd; de gegevens worden niet gebruikt om betalingen automatisch te koppelen.
    </p>

    <?php if (isset($_GET['country_saved'], $_GET['bank_saved'], $_GET['country_deleted'], $_GET['bank_deleted'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Opgeslagen.</p></div>
    <?php endif; ?>
    <?php if (isset($_GET['iban_error'])) : ?>
        <div class="notice notice-error"><p>Niet opgeslagen. Controleer de landcode, positie, codelengte en overlappende codebereiken.</p></div>
    <?php endif; ?>

    <h2>Landindelingen</h2>
    <p class="description">
        Positie telt vanaf 1 in het volledige IBAN. Alle bankcodes van een land hebben de ingestelde lengte.
        Verwijderen wist ook de bankcodes van dat land.
    </p>
    <table class="wp-list-table widefat striped" style="max-width:900px">
        <thead><tr><th>Landcode</th><th>Landnaam</th><th>Positie bankcode</th><th>Lengte</th><th>Acties</th></tr></thead>
        <tbody>
        <?php foreach ($formats as $format) :
            $form_id = 'avbk-country-' . strtolower($format->country_code); ?>
            <tr>
                <td><code><?php echo esc_html($format->country_code); ?></code></td>
                <td><input form="<?php echo esc_attr($form_id); ?>" type="text" name="country_name" value="<?php echo esc_attr($format->country_name); ?>" required></td>
                <td><input form="<?php echo esc_attr($form_id); ?>" type="number" name="bank_code_position" min="1" max="34" value="<?php echo esc_attr($format->bank_code_position); ?>" required style="width:6em"></td>
                <td><input form="<?php echo esc_attr($form_id); ?>" type="number" name="bank_code_length" min="1" max="16" value="<?php echo esc_attr($format->bank_code_length); ?>" required style="width:6em"></td>
                <td style="white-space:nowrap">
                    <form id="<?php echo esc_attr($form_id); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <?php wp_nonce_field('avbk_save_iban_country_format'); ?>
                        <input type="hidden" name="action" value="avbk_save_iban_country_format">
                        <input type="hidden" name="country_code" value="<?php echo esc_attr($format->country_code); ?>">
                        <button type="submit" class="button button-small">Opslaan</button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <?php wp_nonce_field('avbk_delete_iban_country_format'); ?>
                        <input type="hidden" name="action" value="avbk_delete_iban_country_format">
                        <input type="hidden" name="country_code" value="<?php echo esc_attr($format->country_code); ?>">
                        <button type="submit" class="button button-small" onclick="return confirm('Landindeling en alle bankcodes voor dit land verwijderen?');">Verwijderen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td><input form="avbk-new-country" type="text" name="country_code" maxlength="2" pattern="[A-Za-z]{2}" placeholder="DE" required style="width:5em;text-transform:uppercase"></td>
            <td><input form="avbk-new-country" type="text" name="country_name" placeholder="Landnaam" required></td>
            <td><input form="avbk-new-country" type="number" name="bank_code_position" min="1" max="34" required style="width:6em"></td>
            <td><input form="avbk-new-country" type="number" name="bank_code_length" min="1" max="16" required style="width:6em"></td>
            <td>
                <form id="avbk-new-country" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('avbk_save_iban_country_format'); ?>
                    <input type="hidden" name="action" value="avbk_save_iban_country_format">
                    <button type="submit" class="button button-primary button-small">Land toevoegen</button>
                </form>
            </td>
        </tr>
        </tbody>
    </table>

    <h2>Bankcodes</h2>
    <?php if (!$formats) : ?>
        <p>Voeg eerst een landindeling toe.</p>
    <?php else : ?>
        <form method="get" style="margin-bottom:1rem">
            <input type="hidden" name="page" value="avbk-iban-bank-codes">
            <label>Land:
                <select name="country" onchange="this.form.submit()">
                    <?php foreach ($formats as $format) : ?>
                        <option value="<?php echo esc_attr($format->country_code); ?>" <?php selected($selected_country, $format->country_code); ?>>
                            <?php echo esc_html($format->country_code . ' — ' . $format->country_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><?php submit_button('Tonen', 'secondary', '', false); ?></noscript>
        </form>
        <p class="description">
            Codes voor <?php echo esc_html($selected_country); ?> zijn exact <?php echo esc_html($selected_format->bank_code_length); ?> tekens lang.
            Laat Eindcode leeg voor één exacte code. Bereiken mogen niet overlappen.
        </p>
        <table class="wp-list-table widefat striped" style="max-width:900px">
            <thead><tr><th>Begincode</th><th>Eindcode</th><th>Banknaam</th><th>Acties</th></tr></thead>
            <tbody>
            <?php foreach ($bank_codes as $bank_code) :
                $form_id = 'avbk-bank-code-' . (int) $bank_code->id; ?>
                <tr>
                    <td><input form="<?php echo esc_attr($form_id); ?>" type="text" name="code_start" maxlength="<?php echo esc_attr($selected_format->bank_code_length); ?>" value="<?php echo esc_attr($bank_code->code_start); ?>" required style="width:9em;text-transform:uppercase"></td>
                    <td><input form="<?php echo esc_attr($form_id); ?>" type="text" name="code_end" maxlength="<?php echo esc_attr($selected_format->bank_code_length); ?>" value="<?php echo esc_attr($bank_code->code_end); ?>" style="width:9em;text-transform:uppercase"></td>
                    <td><input form="<?php echo esc_attr($form_id); ?>" type="text" name="bank_name" value="<?php echo esc_attr($bank_code->bank_name); ?>" required style="width:100%;max-width:24em"></td>
                    <td style="white-space:nowrap">
                        <form id="<?php echo esc_attr($form_id); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                            <?php wp_nonce_field('avbk_save_iban_bank_code'); ?>
                            <input type="hidden" name="action" value="avbk_save_iban_bank_code">
                            <input type="hidden" name="id" value="<?php echo esc_attr($bank_code->id); ?>">
                            <input type="hidden" name="country_code" value="<?php echo esc_attr($selected_country); ?>">
                            <button type="submit" class="button button-small">Opslaan</button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                            <?php wp_nonce_field('avbk_delete_iban_bank_code'); ?>
                            <input type="hidden" name="action" value="avbk_delete_iban_bank_code">
                            <input type="hidden" name="id" value="<?php echo esc_attr($bank_code->id); ?>">
                            <input type="hidden" name="country_code" value="<?php echo esc_attr($selected_country); ?>">
                            <button type="submit" class="button button-small" onclick="return confirm('Bankcode verwijderen?');">Verwijderen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td><input form="avbk-new-bank-code" type="text" name="code_start" maxlength="<?php echo esc_attr($selected_format->bank_code_length); ?>" required style="width:9em;text-transform:uppercase"></td>
                <td><input form="avbk-new-bank-code" type="text" name="code_end" maxlength="<?php echo esc_attr($selected_format->bank_code_length); ?>" style="width:9em;text-transform:uppercase"></td>
                <td><input form="avbk-new-bank-code" type="text" name="bank_name" required style="width:100%;max-width:24em"></td>
                <td>
                    <form id="avbk-new-bank-code" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('avbk_save_iban_bank_code'); ?>
                        <input type="hidden" name="action" value="avbk_save_iban_bank_code">
                        <input type="hidden" name="id" value="0">
                        <input type="hidden" name="country_code" value="<?php echo esc_attr($selected_country); ?>">
                        <button type="submit" class="button button-primary button-small">Bank toevoegen</button>
                    </form>
                </td>
            </tr>
            </tbody>
        </table>
    <?php endif; ?>
</div>
