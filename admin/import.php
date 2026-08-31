<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}
$layout = AVBK_Bank_Import_Layout::get_config();
?>
<div class="wrap">
    <h1>Bankexport uploaden</h1>

    <?php if (isset($_GET['import_error'])) : ?>
        <div class="notice notice-error">
            <p>Uploaden mislukt<?php if (!empty($_GET['import_error_message'])) : ?>: <?php echo esc_html(rawurldecode($_GET['import_error_message'])); ?><?php endif; ?></p>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['layout_saved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Bankimportprofiel opgeslagen.</p></div>
    <?php endif; ?>

    <p>Upload een .xlsx- of .csv-export van de bank. Al eerder geïmporteerde transacties worden automatisch overgeslagen, dus een overlappende periode nogmaals uploaden is veilig.</p>

    <h2>Importprofiel</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="avbk-bank-layout-form">
        <?php wp_nonce_field('avbk_save_bank_import_layout'); ?>
        <input type="hidden" name="action" value="avbk_save_bank_import_layout">
        <table class="form-table">
            <tr>
                <th><label for="avbk-bank-preset">Sjabloon</label></th>
                <td>
                    <select id="avbk-bank-preset" name="preset">
                        <option value="auto" <?php selected($layout['preset'], 'auto'); ?>>Automatisch — ING Nederlands/Engels</option>
                        <option value="ing_nl" <?php selected($layout['preset'], 'ing_nl'); ?>>ING Nederlands — CSV of XLSX</option>
                        <option value="ing_en" <?php selected($layout['preset'], 'ing_en'); ?>>ING English — CSV or XLSX</option>
                        <option value="custom" <?php selected($layout['preset'], 'custom'); ?>>Aangepast profiel</option>
                    </select>
                    <p class="description">Automatisch behoudt het bestaande gedrag. Kies Aangepast voor een andere bank of exportindeling.</p>
                </td>
            </tr>
            <?php
            $column_fields = [
                'date_column' => 'Datumkolom', 'name_column' => 'Naam/tegenpartij-kolom',
                'iban_column' => 'IBAN/tegenrekening-kolom', 'amount_column' => 'Bedragkolom',
                'direction_column' => 'Bij/af-kolom (optioneel bij bedragen met teken)',
                'description_column' => 'Omschrijving/mededelingen-kolom',
            ];
            foreach ($column_fields as $key => $label) : ?>
                <tr class="avbk-custom-layout">
                    <th><label for="avbk-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                    <td><input type="text" class="regular-text" id="avbk-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($layout[$key]); ?>"></td>
                </tr>
            <?php endforeach; ?>
            <tr class="avbk-custom-layout">
                <th><label for="avbk-date-format">Datumformaat</label></th>
                <td><select id="avbk-date-format" name="date_format">
                    <?php foreach (['auto' => 'Automatisch', 'Ymd' => 'JJJJMMDD', 'Y-m-d' => 'JJJJ-MM-DD', 'd-m-Y' => 'DD-MM-JJJJ', 'd/m/Y' => 'DD/MM/JJJJ', 'm/d/Y' => 'MM/DD/JJJJ'] as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($layout['date_format'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select></td>
            </tr>
            <tr class="avbk-custom-layout">
                <th><label for="avbk-decimal-separator">Decimaalnotatie</label></th>
                <td><select id="avbk-decimal-separator" name="decimal_separator">
                    <option value="auto" <?php selected($layout['decimal_separator'], 'auto'); ?>>Automatisch</option>
                    <option value="comma" <?php selected($layout['decimal_separator'], 'comma'); ?>>Komma (1.234,56)</option>
                    <option value="dot" <?php selected($layout['decimal_separator'], 'dot'); ?>>Punt (1,234.56)</option>
                </select></td>
            </tr>
            <tr class="avbk-custom-layout">
                <th><label for="avbk-credit-values">Waarden voor “bij”</label></th>
                <td><input type="text" class="regular-text" id="avbk-credit-values" name="credit_values" value="<?php echo esc_attr($layout['credit_values']); ?>"><p class="description">Komma-gescheiden, bijvoorbeeld <code>bij,credit,C</code>.</p></td>
            </tr>
            <tr class="avbk-custom-layout">
                <th><label for="avbk-debit-values">Waarden voor “af”</label></th>
                <td><input type="text" class="regular-text" id="avbk-debit-values" name="debit_values" value="<?php echo esc_attr($layout['debit_values']); ?>"></td>
            </tr>
            <tr class="avbk-custom-layout">
                <th><label for="avbk-csv-delimiter">CSV-scheidingsteken</label></th>
                <td><select id="avbk-csv-delimiter" name="csv_delimiter">
                    <option value="auto" <?php selected($layout['csv_delimiter'], 'auto'); ?>>Automatisch</option>
                    <option value="semicolon" <?php selected($layout['csv_delimiter'], 'semicolon'); ?>>Puntkomma</option>
                    <option value="comma" <?php selected($layout['csv_delimiter'], 'comma'); ?>>Komma</option>
                    <option value="tab" <?php selected($layout['csv_delimiter'], 'tab'); ?>>Tab</option>
                </select></td>
            </tr>
        </table>
        <?php submit_button('Importprofiel opslaan', 'secondary'); ?>
    </form>

    <h2>Bestand verwerken</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
        <?php wp_nonce_field('avbk_upload_import'); ?>
        <input type="hidden" name="action" value="avbk_upload_import">
        <table class="form-table">
            <tr>
                <th><label for="bank_export">Bestand</label></th>
                <td>
                    <div class="avbk-dropzone" id="avbk-import-dropzone" tabindex="0">
                        <span class="avbk-dropzone-text" id="avbk-import-dropzone-text">Sleep een bestand hierheen, of klik om te kiezen</span>
                        <input type="file" id="bank_export" name="bank_export" accept=".xlsx,.csv" required>
                    </div>
                </td>
            </tr>
        </table>
        <?php submit_button('Uploaden en verwerken'); ?>
    </form>

    <h2>Geschiedenis</h2>
    <?php $batches = AVBK_DB::get_import_batches(); ?>
    <?php if (!$batches) : ?>
        <p>Nog geen bankexport geüpload.</p>
    <?php else : ?>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th>Geüpload</th>
                    <th>Bestand</th>
                    <th>Periode</th>
                    <th>Rijen</th>
                    <th>Automatisch gekoppeld</th>
                    <th>Door</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($batches as $batch) :
                    $uploader = $batch->uploaded_by ? get_userdata((int) $batch->uploaded_by) : false;
                    ?>
                    <tr>
                        <td><?php echo esc_html(wp_date('D d M Y H:i', strtotime($batch->uploaded_at))); ?></td>
                        <td><?php echo esc_html($batch->filename); ?></td>
                        <td>
                            <?php if ($batch->first_transaction_date && $batch->last_transaction_date) : ?>
                                <?php echo esc_html(wp_date('D d M Y', strtotime($batch->first_transaction_date))); ?>
                                &ndash;
                                <?php echo esc_html(wp_date('D d M Y', strtotime($batch->last_transaction_date))); ?>
                            <?php else : ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($batch->row_count); ?></td>
                        <td><?php echo esc_html($batch->matched_count); ?></td>
                        <td><?php echo esc_html($uploader ? $uploader->display_name : '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
