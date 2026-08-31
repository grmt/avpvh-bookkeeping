<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$activities = AVPVH_DB::get_activities();
$activity_id = (int) ($_GET['activity_id'] ?? 0);
if (!$activity_id) {
    $current = AVPVH_DB::get_current_activity();
    $activity_id = $current ? (int) $current->id : ($activities ? (int) $activities[0]->id : 0);
}
$activity = $activity_id ? AVPVH_DB::get_activity($activity_id) : null;
$config = $activity_id ? AVBK_Sheet_Import::get_config($activity_id) : AVBK_Sheet_Import::DEFAULT_CONFIG;
$import_result = get_transient(AVBK_Sheet_Import::result_transient_key($activity_id));
$participants = $activity_id ? AVPVH_DB::get_participation_for_activity($activity_id) : [];
$preview_result = $activity_id
    ? AVBK_Sheet_Import::fetch_preview($config['sheet_url'], (int) $config['header_row'])
    : ['headers' => [], 'rows' => [], 'error' => null];
$headers_result = ['headers' => $preview_result['headers'], 'error' => $preview_result['error']];
// A live sheet-link fetch wins when there is one; otherwise fall back to
// whatever the last successful fetch/upload saw, so a file-upload-only
// source (no link to re-fetch from) still gets a real-heading dropdown.
$sheet_headers = $headers_result['headers'] ?: $config['header_cache'];
$raw_preview_rows = $preview_result['raw_rows'] ?: ($import_result['preview']['raw_rows'] ?? []);
$preview_header_candidates = [];
foreach ($raw_preview_rows as $preview_row) {
    $candidate_headers = [];
    foreach (($preview_row['cells'] ?? []) as $index => $cell) {
        $candidate_headers[AVBK_Sheet_Import::index_to_letter((int) $index)] = trim((string) $cell);
    }
    $preview_header_candidates[(int) ($preview_row['row_number'] ?? 0)] = $candidate_headers;
}
?>
<div class="wrap">
    <h1>Activiteit betalingen</h1>
    <p class="description">Voor een activiteit waarvan de aanmeldingen via een extern Google Form binnenkomen (in plaats van via deze plugin) — elke herkende aanmelding wordt verwerkt als een gewone deelname + bijdrage, net als bij Kamp/Weekend/etc.</p>

    <form method="get" style="margin-bottom:1rem">
        <input type="hidden" name="page" value="avbk-activity-payments">
        <label>Activiteit:
            <select name="activity_id" onchange="this.form.submit()">
                <?php foreach ($activities as $a) : ?>
                    <option value="<?php echo esc_attr($a->id); ?>" <?php selected($activity_id, (int) $a->id); ?>>
                        <?php echo esc_html($a->name . ' (' . $a->year . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <noscript><?php submit_button('Wisselen', 'secondary', '', false); ?></noscript>
    </form>

    <?php if (!$activity) : ?>
        <p>Nog geen activiteit aangemaakt in AV-PvH Leden &rarr; Activiteiten.</p>
    <?php else : ?>

        <?php if (isset($_GET['config_saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Instellingen opgeslagen.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['linked'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Gekoppeld en verwerkt.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['source_ignored'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Bronregel genegeerd. Deze wordt bij volgende verversingen niet opnieuw als deelnemer aangeboden.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['email_added'])) : ?>
            <div class="notice notice-success is-dismissible"><p>E-mailadres toegevoegd aan de ledenadministratie.</p></div>
        <?php elseif (isset($_GET['email_add_failed'])) : ?>
            <div class="notice notice-error"><p>E-mailadres kon niet worden toegevoegd. Het adres is mogelijk al aan een ander lid gekoppeld, ongeldig, of dit lid heeft al het maximumaantal adressen.</p></div>
        <?php endif; ?>

        <h2>Aanmeldingen &mdash; bron</h2>
        <p class="description">
            De aanmeldingen komen ofwel uit een <strong>live Google Sheet-link</strong> (kies dit als het Google
            Form/Sheet blijft bestaan — elke keer op "Ververs" klikken haalt de nieuwste stand op), ofwel uit een
            <strong>los Excel-bestand</strong> dat je zelf van iemand krijgt toegestuurd (elke keer dat je een nieuwe
            versie krijgt, upload je die opnieuw). Beide leveren dezelfde soort tabel op; je hoeft er maar één van te gebruiken.
        </p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:0.5rem">
            <?php wp_nonce_field('avbk_save_sheet_url'); ?>
            <input type="hidden" name="action" value="avbk_save_sheet_url">
            <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
            <input type="hidden" name="preview_header_candidates" value="<?php echo esc_attr(wp_json_encode($preview_header_candidates)); ?>">
            <input type="hidden" id="avbk_source_match_activity_id" name="match_activity_id" value="<?php echo esc_attr((int) ($config['match_activity_id'] ?? 0)); ?>">
            <table class="form-table" style="max-width:700px">
                <tr>
                    <th><label for="sheet_url">Sheet-link</label></th>
                    <td><input type="url" id="sheet_url" name="sheet_url" class="regular-text" value="<?php echo esc_attr($config['sheet_url']); ?>" style="width:32em">
                        <p class="description">De "Delen"-link van de Google Sheet (moet op "Iedereen met de link kan bekijken" staan). Leeg laten als je in plaats daarvan Excel-bestanden gaat uploaden.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="header_row">Rij met kolomkoppen</label></th>
                    <td><input type="number" id="header_row" name="header_row" min="1" step="1" value="<?php echo esc_attr(max(1, (int) $config['header_row'])); ?>" style="width:6em">
                        <p class="description">Het rijnummer waarin de kopjes staan, bijvoorbeeld <strong>3</strong> als de eerste twee rijen een titel of uitleg bevatten. Geldt voor zowel Google Sheets als Excel-upload.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="last_data_row">Laatste gegevensrij</label></th>
                    <td><input type="number" id="last_data_row" name="last_data_row" min="0" step="1" value="<?php echo esc_attr((int) ($config['last_data_row'] ?? 0) ?: ''); ?>" style="width:6em" placeholder="automatisch">
                        <p class="description">Optioneel Excel/Sheet-rijnummer. Laat leeg voor automatisch: de import stopt bij de eerste rij met “Totaal”.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Link opslaan', 'secondary', 'submit', false); ?>
            <?php if ($config['sheet_url']) : ?>
                <button type="submit" name="refresh_after_save" value="1" class="button button-primary">Opslaan en verversen vanuit Google Sheet</button>
            <?php endif; ?>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="margin:1rem 0 1.5rem">
            <?php wp_nonce_field('avbk_sheet_import_upload'); ?>
            <input type="hidden" name="action" value="avbk_sheet_import_upload">
            <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
            <p class="description">Of upload een Excel-bestand (.xlsx) met dezelfde kolomindeling als hieronder ingesteld:</p>
            <div class="avbk-dropzone" tabindex="0" style="max-width:32em">
                <span class="avbk-dropzone-text">Sleep een .xlsx-bestand hierheen, of klik om te kiezen</span>
                <input type="file" name="sheet_file" accept=".xlsx" required>
            </div>
            <?php submit_button('Upload en verwerk', 'secondary', 'submit', false); ?>
        </form>

        <?php if ($raw_preview_rows) : ?>
            <h2>Eerste drie regels bronbestand</h2>
            <p class="description">De eerste drie niet-lege regels uit de Google Sheet of het Excel-bestand. Zo kun je controleren op welke rij de kolomkoppen staan.</p>
            <div style="max-width:100%;overflow:auto;margin-bottom:1.5rem">
                <table class="wp-list-table widefat striped" style="width:max-content;min-width:100%">
                    <thead><tr>
                        <th>Rij</th>
                        <?php
                        $preview_column_count = max(array_map(fn($row) => $row['cells'] ? max(array_keys($row['cells'])) + 1 : 0, $raw_preview_rows));
                        for ($preview_column = 0; $preview_column < $preview_column_count; $preview_column++) : ?>
                            <th style="min-width:10em"><?php echo esc_html(AVBK_Sheet_Import::index_to_letter($preview_column)); ?></th>
                        <?php endfor; ?>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($raw_preview_rows as $preview_row) : ?><tr>
                            <th><?php echo esc_html($preview_row['row_number']); ?></th>
                            <?php for ($preview_column = 0; $preview_column < $preview_column_count; $preview_column++) : ?>
                                <td><?php echo esc_html(mb_strimwidth((string) ($preview_row['cells'][$preview_column] ?? ''), 0, 120, '…')); ?></td>
                            <?php endfor; ?>
                        </tr><?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h2>Kolomindeling</h2>
        <p class="description">
            Eén "slot" hieronder = één persoon die in een enkele rij van het formulier genoemd kan worden.
            De meeste formulieren (boek, t-shirt, kamp) hebben maar 1 persoon per rij — dan vul je alleen
            <strong>Persoon 1</strong> in. Per persoon is <strong>Naam of E-mail</strong> verplicht; beide invullen geeft de betrouwbaarste koppeling. Een formulier waarbij je in één keer meerdere mensen tegelijk kunt
            aanmelden (zoals "wil je nog iemand aanmelden?") heeft per mogelijke extra persoon een eigen slot nodig —
            vul dan ook Persoon 2, 3, ... in. Een leeg slot wordt genegeerd.
        </p>
        <?php if ($headers_result['error']) : ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html($headers_result['error']); ?></p></div>
        <?php elseif (!$sheet_headers) : ?>
            <p class="description"><em>Vul hierboven een sheet-link in en sla op, óf upload eenmalig een Excel-bestand — de pagina laadt dan opnieuw en toont hierna een keuzelijst met de echte kolomkoppen, in plaats van kolomletters.</em></p>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('avbk_save_sheet_import_config'); ?>
            <input type="hidden" name="action" value="avbk_save_sheet_import_config">
            <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
            <input type="hidden" id="avbk_config_header_row" name="header_row" value="<?php echo esc_attr(max(1, (int) $config['header_row'])); ?>">
            <input type="hidden" name="preview_header_candidates" value="<?php echo esc_attr(wp_json_encode($preview_header_candidates)); ?>">
            <table class="form-table" style="max-width:700px">
                <tr>
                    <th><label for="price_per_person">Vast bedrag per persoon</label></th>
                    <td>&euro; <input type="text" id="price_per_person" name="price_per_person" value="<?php echo esc_attr($config['price_per_person'] ? number_format((float) $config['price_per_person'], 2, ',', '') : ''); ?>" style="width:6em" placeholder="0,00">
                        <p class="description">Leeg of 0 laten als er geen automatische bijdrage per persoon aangemaakt hoeft te worden (alleen deelname registreren). Wordt genegeerd voor een slot met een eigen "Bedrag-kolom" hieronder (bijv. een drankrekening waar iedereen een ander bedrag heeft).</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="timestamp_column">Inschrijfdatum</label></th>
                    <td>
                        <?php if ($sheet_headers) : ?>
                            <select id="timestamp_column" name="timestamp_column" data-avbk-column-select style="max-width:28em">
                                <option value="">&mdash; niet gebruikt &mdash;</option>
                                <?php foreach ($sheet_headers as $letter => $header_text) : ?>
                                    <option value="<?php echo esc_attr($letter); ?>" <?php selected($config['timestamp_column'], $letter); ?>>
                                        <?php echo esc_html($letter . ' — ' . ($header_text !== '' ? $header_text : '(lege kolomkop)')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else : ?>
                            <input type="text" id="timestamp_column" name="timestamp_column" value="<?php echo esc_attr($config['timestamp_column']); ?>" style="width:5em" placeholder="bijv. A">
                        <?php endif; ?>
                        <p class="description">Kies de Google Forms-kolom “Tijdstempel”/“Timestamp”. Deze oorspronkelijke formulierdatum blijft bewaard bij latere verversingen.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="match_activity_id">Koppelen via deelnemers van</label></th>
                    <td>
                        <select id="match_activity_id" name="match_activity_id" style="max-width:28em">
                            <option value="0">&mdash; alleen deze activiteit &mdash;</option>
                            <?php foreach ($activities as $matching_activity) :
                                if ((int) $matching_activity->id === $activity_id) {
                                    continue;
                                }
                                ?>
                                <option value="<?php echo esc_attr($matching_activity->id); ?>" <?php selected((int) ($config['match_activity_id'] ?? 0), (int) $matching_activity->id); ?>>
                                    <?php echo esc_html($matching_activity->name . ' (' . $matching_activity->year . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Gebruik bijvoorbeeld bij “Drankrekening Kamp” de deelnemers van het bijbehorende archeologiekamp. Bij meerdere actieve leden met dezelfde voornaam geeft één inschrijving in die activiteit de doorslag.</p>
                    </td>
                </tr>
            </table>
            <table class="wp-list-table widefat striped" style="max-width:1200px">
                <thead><tr><th>Slot</th><th>Naam <em>(of e-mail)</em></th><th>E-mail <em>(of naam)</em></th><th>Allergie/notitie</th><th>Overige notitie</th><th>Bedrag <em>(optioneel)</em></th></tr></thead>
                <tbody>
                <?php for ($i = 0; $i < AVBK_Sheet_Import::MAX_SLOTS; $i++) :
                    $slot = $config['slots'][$i] ?? ['name' => '', 'email' => '', 'diet' => '', 'notes' => '', 'amount' => ''];
                    $slot_label = $i === 0 ? 'Persoon 1 (hoofdaanmelder)' : 'Persoon ' . ($i + 1) . ' (optioneel)';
                    $slot_inputs = [
                        'slot_name'   => $slot['name'] ?? '',
                        'slot_email'  => $slot['email'] ?? '',
                        'slot_diet'   => $slot['diet'] ?? '',
                        'slot_notes'  => $slot['notes'] ?? '',
                        'slot_amount' => $slot['amount'] ?? '',
                    ];
                    ?>
                    <tr>
                        <td><?php echo esc_html($slot_label); ?></td>
                        <?php foreach ($slot_inputs as $input_name => $value) : ?>
                            <td>
                                <?php if ($sheet_headers) : ?>
                                    <select name="<?php echo esc_attr($input_name); ?>[]" data-avbk-column-select style="max-width:20em">
                                        <option value="">&mdash; niet gebruikt &mdash;</option>
                                        <?php foreach ($sheet_headers as $letter => $header_text) : ?>
                                            <option value="<?php echo esc_attr($letter); ?>" <?php selected($value, $letter); ?>>
                                                <?php // Google Forms repeats the same question text per attendee slot (e.g. "Naam" for slot 2, 3, 4...), so the letter must be shown too or every slot's dropdown looks identical. ?>
                                                <?php echo esc_html($letter . ' — ' . ($header_text !== '' ? $header_text : '(lege kolomkop)')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else : ?>
                                    <input type="text" name="<?php echo esc_attr($input_name); ?>[]" value="<?php echo esc_attr($value); ?>" style="width:5em" placeholder="bijv. B">
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
            <p class="description">
                Vul een "Bedrag"-kolom alleen in als het bedrag per persoon verschilt (bijv. een drankrekening) — anders het vaste bedrag hierboven gebruiken.
            </p>
            <?php submit_button($config['sheet_url'] ? 'Instellingen opslaan en Google Sheet opnieuw verwerken' : 'Instellingen opslaan'); ?>
        </form>

        <?php if ($preview_header_candidates) : ?>
            <script>
            (() => {
                const headerRow = document.getElementById('header_row');
                const matchActivity = document.getElementById('match_activity_id');
                const candidates = <?php echo wp_json_encode($preview_header_candidates); ?>;
                if (matchActivity) {
                    matchActivity.addEventListener('change', () => {
                        const sourceMatchActivity = document.getElementById('avbk_source_match_activity_id');
                        if (sourceMatchActivity) sourceMatchActivity.value = matchActivity.value;
                    });
                }
                if (!headerRow) return;
                headerRow.addEventListener('change', () => {
                    const headers = candidates[headerRow.value];
                    if (!headers) return;
                    const configHeaderRow = document.getElementById('avbk_config_header_row');
                    if (configHeaderRow) configHeaderRow.value = headerRow.value;
                    document.querySelectorAll('[data-avbk-column-select]').forEach((select) => {
                        const selected = select.value;
                        select.replaceChildren(new Option('— niet gebruikt —', ''));
                        Object.entries(headers).forEach(([letter, heading]) => {
                            select.add(new Option(`${letter} — ${heading || '(lege kolomkop)'}`, letter));
                        });
                        select.value = Object.prototype.hasOwnProperty.call(headers, selected) ? selected : '';
                    });
                });
            })();
            </script>
        <?php endif; ?>

        <?php if ($import_result) : ?>
            <?php if ($import_result['errors']) : ?>
                <div class="notice notice-error"><p><?php echo esc_html(implode(' ', $import_result['errors'])); ?></p></div>
            <?php else : ?>
                <div class="notice notice-success">
                    <p><?php echo esc_html(count($import_result['matched'])); ?> persoon/personen verwerkt<?php echo $import_result['unmatched'] ? ', ' . esc_html(count($import_result['unmatched'])) . ' niet herkend (zie hieronder)' : ''; ?>.</p>
                </div>
            <?php endif; ?>

            <?php if ($import_result['unmatched']) : ?>
                <h3 id="avbk-unmatched">Niet herkend &mdash; handmatig koppelen</h3>
                <p class="description">Deze namen/e-mailadressen konden niet eenduidig aan een bestaand lid worden gekoppeld. Maak eerst zo nodig een nieuw lid aan bij AV-PvH Leden, kies 'm dan hieronder.</p>
                <?php foreach ($import_result['unmatched'] as $person) : ?>
                    <div class="avbk-review-row">
                        <p><strong><?php echo esc_html($person['name'] ?: $person['email']); ?></strong> &mdash; <?php echo esc_html($person['email'] ?: 'geen e-mail'); ?><?php echo $person['allergies'] ? ' — allergieën: ' . esc_html($person['allergies']) : ''; ?><?php echo !empty($person['amount']) ? ' — € ' . esc_html(number_format((float) $person['amount'], 2, ',', '.')) : ''; ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('avbk_sheet_import_link_attendee'); ?>
                            <input type="hidden" name="action" value="avbk_sheet_import_link_attendee">
                            <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
                            <input type="hidden" name="source_name" value="<?php echo esc_attr($person['name']); ?>">
                            <input type="hidden" name="source_email" value="<?php echo esc_attr($person['email']); ?>">
                            <input type="hidden" name="allergies" value="<?php echo esc_attr($person['allergies']); ?>">
                            <input type="hidden" name="notes" value="<?php echo esc_attr($person['notes']); ?>">
                            <input type="hidden" name="amount" value="<?php echo esc_attr($person['amount'] ?? 0); ?>">
                            <input type="hidden" name="registered_at" value="<?php echo esc_attr($person['registered_at'] ?? ''); ?>">
                            <input type="hidden" name="source_timestamp" value="<?php echo esc_attr($person['source_timestamp'] ?? ''); ?>">
                            <label style="display:block;margin:.4rem 0"><input type="search" class="avbk-unmatched-member-search" placeholder="Zoek lid, bijv. paul" style="width:20em"></label>
                            <label style="display:block;margin:.4rem 0"><input type="checkbox" class="avbk-show-inactive-members"> Toon inactieve leden</label>
                            <select name="member_id" class="avbk-unmatched-member-select" required>
                                <option value="">&mdash; kies lid (ook oud-leden/bezoekers) &mdash;</option>
                                <?php if (!empty($person['suggestions'])) : ?>
                                    <optgroup label="Waarschijnlijke actieve leden">
                                        <?php foreach ($person['suggestions'] as $suggestion) :
                                            $suggested_member = $suggestion['member']; ?>
                                            <option value="<?php echo esc_attr($suggested_member->id); ?>">
                                                <?php echo esc_html(avpvh_format_name($suggested_member, 'list') . ' — overeenkomst ' . $suggestion['score'] . '%' . (!empty($suggestion['registered']) ? ', al ingeschreven' : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="Alle leden">
                                <?php endif; ?>
                                <?php foreach (AVPVH_DB::get_members() as $m) : ?>
                                    <option value="<?php echo esc_attr($m->id); ?>" data-member-status="<?php echo esc_attr($m->status); ?>"><?php echo esc_html(avpvh_format_name($m, 'list') . ' (' . $m->status . ')'); ?></option>
                                <?php endforeach; ?>
                                <?php if (!empty($person['suggestions'])) : ?></optgroup><?php endif; ?>
                            </select>
                            <?php if (is_email($person['email'])) : ?>
                                <label style="display:block;margin:0.5rem 0">
                                    <input type="checkbox" name="add_source_email" value="1">
                                    Voeg <strong><?php echo esc_html($person['email']); ?></strong> ook toe aan de ledenadministratie van het gekozen lid
                                </label>
                            <?php endif; ?>
                            <?php submit_button('Koppel en verwerk', 'secondary', 'submit', false); ?>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-top:0.5rem">
                            <?php wp_nonce_field('avbk_sheet_import_ignore_attendee'); ?>
                            <input type="hidden" name="action" value="avbk_sheet_import_ignore_attendee">
                            <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
                            <input type="hidden" name="source_name" value="<?php echo esc_attr($person['name']); ?>">
                            <input type="hidden" name="source_email" value="<?php echo esc_attr($person['email']); ?>">
                            <input type="hidden" name="source_timestamp" value="<?php echo esc_attr($person['source_timestamp'] ?? ''); ?>">
                            <button type="submit" class="button">Negeer deze bronregel</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($import_result && $import_result['unmatched']) : ?>
            <script>
            document.querySelectorAll('.avbk-review-row').forEach(function (row) {
                var search = row.querySelector('.avbk-unmatched-member-search');
                var inactive = row.querySelector('.avbk-show-inactive-members');
                var select = row.querySelector('.avbk-unmatched-member-select');
                if (!search || !inactive || !select) return;
                function filterMembers() {
                    var needle = search.value.trim().toLowerCase();
                    Array.prototype.forEach.call(select.options, function (option) {
                        if (!option.value) return;
                        var isInactive = option.dataset.memberStatus === 'inactive';
                        option.hidden = (!inactive.checked && isInactive) || (needle && option.textContent.toLowerCase().indexOf(needle) === -1);
                    });
                }
                search.addEventListener('input', filterMembers);
                inactive.addEventListener('change', filterMembers);
                filterMembers();
            });
            </script>
        <?php endif; ?>

        <h2>Deelnemers <?php echo esc_html($activity->name); ?> (<?php echo esc_html(count($participants)); ?>)</h2>
        <p class="description">Betalingen zijn verwerkt tot en met <?php echo esc_html(wp_date('d-m-Y', strtotime(AVBK_DB::get_last_processed_date()))); ?>.</p>
        <p class="description"><strong>Ingeschreven op</strong> is uitsluitend de oorspronkelijke formulierdatum van een daadwerkelijke deelname aan deze activiteit. Er wordt geen datum uit een betaling, ledenrecord of koppelactiviteit afgeleid.</p>
        <?php if (empty($config['timestamp_column'])) : ?>
            <div class="notice notice-warning inline"><p>Er is geen kolom voor de inschrijfdatum gekozen. Selecteer bij Kolomindeling de kolom “Timestamp” of “Tijdstempel” en verwerk de Sheet opnieuw.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['payment_requested'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Betaalverzoek verstuurd.</p></div>
        <?php elseif (isset($_GET['payment_request_failed'])) : ?>
            <div class="notice notice-error is-dismissible"><p>Betaalverzoek kon niet worden verstuurd (geen openstaand bedrag, geen e-mailadres bekend, of het versturen is mislukt).</p></div>
        <?php endif; ?>
        <?php if (!$participants) : ?>
            <p>Nog geen deelnemers.</p>
        <?php else : ?>
            <p class="description">Zoek of filter in de velden onder de kolomkoppen. Klik op een kolomkop om te sorteren.</p>
            <div class="avbk-balance-table-tools">
                <button type="button" class="button button-small avbk-col-toggle-btn">Kolommen</button>
                <div class="avbk-col-toggle-panel" hidden></div>
            </div>
            <div class="avbk-balance-table-wrap">
            <table id="avbk-balance-table" data-storage-key="avbk_activity_payments_hidden_cols_<?php echo esc_attr($activity_id); ?>" class="wp-list-table widefat striped avbk-balance-table">
                <thead><tr class="avbk-balance-header-row">
                    <th data-col="naam">Naam</th>
                    <th data-col="ingeschreven" data-filter="select">Ingeschreven op</th>
                    <th data-col="allergieen" class="avbk-col-optional">Allergieën</th>
                    <th data-col="notities" class="avbk-col-optional">Notities</th>
                    <th data-col="betaald" data-type="number">Betaald</th>
                    <th data-col="totaal" data-type="number">Berekend totaal</th>
                    <th data-col="betaalverzoek" data-filter="select">Betaalverzoek</th>
                </tr></thead>
                <tbody>
                <?php $participants_paid_total = 0.0; $participants_due_total = 0.0; foreach ($participants as $p) :
                    $fee_item = AVBK_DB::get_fee_item_for_member_activity((int) $p->member_id, $activity_id);
                    $paid = $fee_item ? AVBK_DB::get_fee_item_paid((int) $fee_item->id) : 0.0;
                    $due = $fee_item ? (float) $fee_item->amount_due : (float) $config['price_per_person'];
                    $remaining = $fee_item ? round($due - $paid, 2) : 0.0;
                    $participants_paid_total += $paid;
                    $participants_due_total += $due;
                    $registration_meta = AVBK_DB::get_sheet_participation_meta($activity_id, (int) $p->member_id);
                    $payments = $fee_item ? AVBK_DB::get_payments_for_fee_item((int) $fee_item->id) : [];
                    $payment_request = $fee_item ? AVBK_DB::get_last_payment_request((int) $fee_item->id) : null;
                    $registration_sort = $registration_meta && $registration_meta->registered_at
                        ? $registration_meta->registered_at
                        : ($registration_meta->source_timestamp ?? '');
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $p->member_id], admin_url('admin.php'))); ?>" target="_blank"><?php echo esc_html(avpvh_format_name($p, 'list')); ?></a></td>
                        <td style="white-space:nowrap" data-sort-value="<?php echo esc_attr($registration_sort); ?>" data-filter-value="<?php echo esc_attr($registration_meta && ($registration_meta->registered_at || $registration_meta->source_timestamp) ? 'Datum bekend' : 'Geen datum'); ?>">
                            <?php if ($registration_meta && $registration_meta->registered_at) : ?>
                                <?php echo esc_html(wp_date('d-m-Y H:i', strtotime($registration_meta->registered_at))); ?>
                            <?php elseif ($registration_meta && $registration_meta->source_timestamp) : ?>
                                <?php echo esc_html($registration_meta->source_timestamp); ?>
                            <?php else : ?>&mdash;<?php endif; ?>
                        </td>
                        <td><?php echo esc_html($p->diet ?: '—'); ?></td>
                        <td><?php echo esc_html($p->notes ?: '—'); ?></td>
                        <td data-sort-value="<?php echo esc_attr(number_format($paid, 2, '.', '')); ?>">
                            <?php echo esc_html('€ ' . number_format($paid, 2, ',', '.')); ?>
                            <?php foreach ($payments as $payment) :
                                $transaction_url = add_query_arg(
                                    ['page' => 'avbk-transactions', 'show_all_years' => '1'],
                                    admin_url('admin.php')
                                ) . '#tx-' . (int) $payment->transaction_id;
                                ?>
                                <br><a href="<?php echo esc_url($transaction_url); ?>">
                                    <?php echo esc_html(wp_date('d-m-Y', strtotime($payment->transaction_date))); ?>
                                    &mdash; transactie #<?php echo esc_html($payment->transaction_id); ?>
                                </a>
                            <?php endforeach; ?>
                        </td>
                        <td data-sort-value="<?php echo esc_attr(number_format($due, 2, '.', '')); ?>">
                            <?php echo esc_html('€ ' . number_format($due, 2, ',', '.')); ?>
                        </td>
                        <td data-filter-value="<?php echo esc_attr($payment_request ? 'Gevraagd' : 'Niet gevraagd'); ?>">
                            <?php if ($remaining > 0.005) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                    <?php wp_nonce_field('avbk_request_payment'); ?>
                                    <input type="hidden" name="action" value="avbk_request_payment">
                                    <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
                                    <input type="hidden" name="member_id" value="<?php echo esc_attr($p->member_id); ?>">
                                    <?php submit_button($payment_request ? 'Opnieuw vragen' : 'Vraag om betaling', 'secondary small', 'submit', false); ?>
                                </form>
                            <?php endif; ?>
                            <?php if ($payment_request) : ?>
                                <div class="description" style="white-space:nowrap">Gevraagd op <?php echo esc_html(wp_date('d-m-Y H:i', strtotime($payment_request->requested_at))); ?></div>
                            <?php elseif ($remaining <= 0.005) : ?>&mdash;<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <th>Totaal</th><th></th><th></th><th></th>
                    <th data-total-col="betaald" data-sort-value="<?php echo esc_attr(number_format($participants_paid_total, 2, '.', '')); ?>">&euro; <?php echo esc_html(number_format($participants_paid_total, 2, ',', '.')); ?></th>
                    <th data-total-col="totaal" data-sort-value="<?php echo esc_attr(number_format($participants_due_total, 2, '.', '')); ?>">&euro; <?php echo esc_html(number_format($participants_due_total, 2, ',', '.')); ?></th>
                    <th></th>
                </tr></tfoot>
            </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <style>
        .avbk-collapsible-block { margin: 1rem 0; border: 1px solid #ccd0d4; background: #fff; }
        .avbk-collapsible-block > summary { cursor: pointer; padding: .7rem 1rem; font-weight: 600; }
        .avbk-collapsible-block > .avbk-collapsible-content { padding: 0 1rem 1rem; }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var headings = Array.prototype.slice.call(document.querySelectorAll('.wrap > h2, .wrap > h3'));
        var wanted = ['Aanmeldingen', 'Eerste drie regels bronbestand', 'Kolomindeling', 'Niet herkend'];
        headings.forEach(function (heading) {
            var title = heading.textContent.trim();
            var label = wanted.find(function (item) { return title.indexOf(item) === 0; });
            if (!label || heading.parentElement.classList.contains('avbk-collapsible-block')) return;
            var details = document.createElement('details');
            details.className = 'avbk-collapsible-block';
            var summary = document.createElement('summary');
            summary.textContent = title;
            var content = document.createElement('div');
            content.className = 'avbk-collapsible-content';
            heading.parentNode.insertBefore(details, heading);
            details.appendChild(summary);
            details.appendChild(content);
            content.appendChild(heading);
            var node = details.nextElementSibling;
            while (node && !(/^H[23]$/.test(node.tagName))) {
                var next = node.nextElementSibling;
                content.appendChild(node);
                node = next;
            }
        });
    });
    </script>
</div>
