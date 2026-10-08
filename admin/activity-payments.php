<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$activities = AVPVH_DB::get_activities();
$activity_id = (int) ($_GET['activity_id'] ?? 0);
$current_user_id = get_current_user_id();
if ($activity_id) {
    // Remember per-admin so the page reopens on whichever activity this
    // user last looked at, instead of always falling back to "current".
    update_user_meta($current_user_id, 'avbk_last_activity_payments_id', $activity_id);
} else {
    $remembered_id = (int) get_user_meta($current_user_id, 'avbk_last_activity_payments_id', true);
    $remembered_exists = $remembered_id && array_filter($activities, fn($a) => (int) $a->id === $remembered_id);
    if ($remembered_exists) {
        $activity_id = $remembered_id;
    } else {
        $current = AVPVH_DB::get_current_activity();
        $activity_id = $current ? (int) $current->id : ($activities ? (int) $activities[0]->id : 0);
    }
}
$activity = $activity_id ? AVPVH_DB::get_activity($activity_id) : null;
$is_camp = $activity && ($activity->type_name ?? '') === 'Kamp';
$config = $activity_id ? AVBK_Sheet_Import::get_config($activity_id) : AVBK_Sheet_Import::DEFAULT_CONFIG;
$import_result = get_transient(AVBK_Sheet_Import::result_transient_key($activity_id));
$participants = $activity_id ? AVPVH_DB::get_participation_for_activity($activity_id) : [];
$preview_result = $activity_id && !$is_camp
    ? AVBK_Sheet_Import::fetch_preview($config['sheet_url'], (int) $config['header_row'])
    : ['headers' => [], 'rows' => [], 'raw_rows' => [], 'error' => null];
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
$activity_years = array_values(array_unique(array_map(fn($a) => (int) $a->year, $activities)));
rsort($activity_years);
$activity_types = array_values(array_unique(array_map(fn($a) => (string) ($a->type_name ?? ''), $activities)));
sort($activity_types);
?>
<script type="application/json" id="avbk-activity-picker-config"><?php echo wp_json_encode([
    'activities' => array_map(fn($a) => [
        'id'    => (int) $a->id,
        'year'  => (int) $a->year,
        'type'  => (string) ($a->type_name ?? ''),
        'label' => $a->name . ' (' . $a->year . ')',
    ], $activities),
]); ?></script>
<div class="wrap">
    <h1>Activiteit betalingen</h1>
    <p class="description">Verwerk deelnemers uit het bij de activiteit passende bronbestand en beheer de bijbehorende betalingen.</p>

    <form method="get" style="margin-bottom:1rem" id="avbk-activity-picker-form">
        <input type="hidden" name="page" value="avbk-activity-payments">
        <label>Jaar:
            <select id="avbk-activity-year-filter">
                <option value="">&mdash; alle jaren &mdash;</option>
                <?php foreach ($activity_years as $year) : ?>
                    <option value="<?php echo esc_attr($year); ?>" <?php selected($activity && (int) $activity->year === $year); ?>>
                        <?php echo esc_html((string) $year); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Type:
            <select id="avbk-activity-type-filter">
                <option value="">&mdash; alle types &mdash;</option>
                <?php foreach ($activity_types as $type) : ?>
                    <option value="<?php echo esc_attr($type); ?>" <?php selected($activity && ($activity->type_name ?? '') === $type); ?>>
                        <?php echo esc_html($type !== '' ? $type : '(geen type)'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Activiteit:
            <div class="avbk-activity-combo">
                <input type="hidden" name="activity_id" id="avbk-activity-combo-value" value="<?php echo esc_attr($activity_id ?: ''); ?>">
                <input type="text" class="avbk-activity-combo-input" autocomplete="off" placeholder="&mdash; kies activiteit &mdash;" value="<?php echo esc_attr($activity ? $activity->name . ' (' . $activity->year . ')' : ''); ?>">
                <div class="avbk-activity-combo-list" hidden></div>
            </div>
        </label>
        <noscript><?php submit_button('Wisselen', 'secondary', '', false); ?></noscript>
    </form>
    <script>
    (function () {
        var cfg = JSON.parse(document.getElementById('avbk-activity-picker-config').textContent);
        var form = document.getElementById('avbk-activity-picker-form');
        var yearFilter = document.getElementById('avbk-activity-year-filter');
        var typeFilter = document.getElementById('avbk-activity-type-filter');
        var wrapper = form.querySelector('.avbk-activity-combo');
        var hidden = document.getElementById('avbk-activity-combo-value');
        var input = wrapper.querySelector('.avbk-activity-combo-input');
        var list = wrapper.querySelector('.avbk-activity-combo-list');
        var activeIndex = -1;
        var renderedItems = [];

        function labelFor(id) {
            var a = cfg.activities.filter(function (x) { return String(x.id) === String(id); })[0];
            return a ? a.label : '';
        }

        function closeList() {
            list.hidden = true;
            activeIndex = -1;
        }

        function selectActivity(id, label) {
            hidden.value = id;
            input.value = label;
            closeList();
            form.submit();
        }

        function setActive(index) {
            var children = Array.prototype.slice.call(list.querySelectorAll('.avbk-activity-combo-item'));
            children.forEach(function (el, i) { el.classList.toggle('is-active', i === index); });
            if (children[index]) children[index].scrollIntoView({ block: 'nearest' });
            activeIndex = index;
        }

        function render(forceEmptyTerm) {
            var term = forceEmptyTerm ? '' : input.value.trim().toLowerCase();
            var year = yearFilter.value;
            var type = typeFilter.value;
            list.innerHTML = '';
            renderedItems = [];
            cfg.activities.forEach(function (a) {
                if (year !== '' && String(a.year) !== year) return;
                if (type !== '' && a.type !== type) return;
                if (term !== '' && a.label.toLowerCase().indexOf(term) === -1) return;
                var item = document.createElement('div');
                item.className = 'avbk-activity-combo-item';
                item.textContent = a.label;
                item.dataset.id = a.id;
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    selectActivity(a.id, a.label);
                });
                list.appendChild(item);
                renderedItems.push(item);
            });
            list.hidden = renderedItems.length === 0;
            activeIndex = -1;
        }

        input.addEventListener('input', function () { render(false); });
        input.addEventListener('focus', function () {
            input.select();
            render(true);
        });
        input.addEventListener('keydown', function (e) {
            if (list.hidden && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                render(true);
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(Math.min(activeIndex + 1, renderedItems.length - 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(Math.max(activeIndex - 1, 0));
            } else if (e.key === 'Enter') {
                if (!list.hidden && activeIndex >= 0 && renderedItems[activeIndex]) {
                    e.preventDefault();
                    var item = renderedItems[activeIndex];
                    selectActivity(item.dataset.id, item.textContent);
                }
            } else if (e.key === 'Escape') {
                closeList();
            }
        });
        input.addEventListener('blur', function () {
            setTimeout(function () {
                closeList();
                input.value = hidden.value ? labelFor(hidden.value) : '';
            }, 150);
        });
        // Kiezen van een jaar/type is zelf geen keuze van activiteit —
        // alleen de kandidatenlijst versmallen, direct zichtbaar als die
        // al open staat.
        yearFilter.addEventListener('change', function () {
            if (!list.hidden) render(false);
        });
        typeFilter.addEventListener('change', function () {
            if (!list.hidden) render(false);
        });
    })();
    </script>

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
        <?php if ($is_camp) : ?>
            <p class="description">
                Voor een kamp wordt automatisch de speciale indeling van het werkblad
                <code>totaal inschrijvingen</code> gebruikt. Je hoeft daarom geen kopregel of kolomindeling in te stellen.
                Namen staan in D, kampdagen in E–T en nawacht, opmerkingen en dieet in W–Z.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="margin:1rem 0 1.5rem">
                <?php wp_nonce_field('avbk_sheet_import_upload'); ?>
                <input type="hidden" name="action" value="avbk_sheet_import_upload">
                <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
                <div class="avbk-dropzone" tabindex="0" style="max-width:32em">
                    <span class="avbk-dropzone-text">Sleep het bijgewerkte kampbestand (.xlsx) hierheen, of klik om te kiezen</span>
                    <input type="file" name="sheet_file" accept=".xlsx" required>
                </div>
                <p class="description">
                    Herkende deelnemers en hun dagen worden bijgewerkt; de kampperiode volgt de datums in het bestand.
                    Niet-herkende namen komen hieronder ter beoordeling. Deelnemers die niet meer in het bestand staan worden niet automatisch verwijderd.
                </p>
                <?php submit_button('Kampbestand verwerken', 'primary', 'submit', false); ?>
            </form>
            <p class="description">Of gebruik een Google Sheets-link (moet op "Anyone with the link can view" staan):</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0 0 1.5rem">
                <?php wp_nonce_field('avbk_camp_sheet_import_from_url'); ?>
                <input type="hidden" name="action" value="avbk_camp_sheet_import_from_url">
                <input type="hidden" name="activity_id" value="<?php echo esc_attr($activity_id); ?>">
                <input type="url" name="camp_sheet_url" class="regular-text" style="width:32em" placeholder="https://docs.google.com/spreadsheets/d/...">
                <?php submit_button('Ophalen en verwerken', 'secondary', 'submit', false); ?>
            </form>
        <?php else : ?>
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
                    <th><label for="timestamp_format">Datumformaat inschrijfdatum</label></th>
                    <td>
                        <select id="timestamp_format" name="timestamp_format">
                            <option value="mdy" <?php selected($config['timestamp_format'] ?? 'mdy', 'mdy'); ?>>Maand/dag/jaar (Google Forms-standaard)</option>
                            <option value="dmy" <?php selected($config['timestamp_format'] ?? 'mdy', 'dmy'); ?>>Dag/maand/jaar</option>
                        </select>
                        <p class="description">Google Forms schrijft de tijdstempel altijd als maand/dag/jaar, ongeacht de taal van het formulier — laat dit op de standaard staan tenzij de bron iets anders is. Bij een datum als 9/11 kan de code dit niet zelf aan losse rijen zien (9 en 11 zijn allebei een geldige dag én maand), vandaar deze instelling in plaats van gokken.</p>
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
        <?php endif; ?>

        <?php if ($import_result) : ?>
            <?php if ($import_result['errors']) : ?>
                <div class="notice notice-error"><p><?php echo esc_html(implode(' ', $import_result['errors'])); ?></p></div>
            <?php else : ?>
                <div class="notice notice-success">
                    <p><?php echo esc_html(count($import_result['matched'])); ?> persoon/personen verwerkt<?php echo $import_result['unmatched'] ? ', ' . esc_html(count($import_result['unmatched'])) . ' niet herkend (zie hieronder)' : ''; ?>.</p>
                    <?php if (!empty($import_result['camp'])) : ?>
                        <p>Kampperiode: <?php echo esc_html($import_result['camp']['start_date']); ?> t/m <?php echo esc_html($import_result['camp']['end_date']); ?>.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($import_result['date_warnings'])) : ?>
                <div class="notice notice-warning">
                    <p><strong>Controleer deze inschrijfdatums</strong> — mogelijk staat het datumformaat (hierboven, "Datumformaat inschrijfdatum") verkeerd:</p>
                    <ul style="list-style:disc; margin-left:1.5em">
                        <?php foreach ($import_result['date_warnings'] as $warning) : ?>
                            <li><?php echo esc_html($warning); ?></li>
                        <?php endforeach; ?>
                    </ul>
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
                            <?php if (isset($person['camp_days'])) : ?>
                                <input type="hidden" name="camp_days" value="<?php echo esc_attr(wp_json_encode($person['camp_days'])); ?>">
                                <input type="hidden" name="camp_nawacht" value="<?php echo !empty($person['camp_nawacht']) ? '1' : ''; ?>">
                            <?php endif; ?>
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
                    <th data-col="bank" class="avbk-col-optional">Bank(en)</th>
                    <th data-col="bank_naam" data-filter="select" class="avbk-col-optional">Bank</th>
                    <th data-col="land" data-filter="select" class="avbk-col-optional">Land</th>
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
                    <?php
                    $known_ibans = AVBK_DB::get_own_known_ibans((int) $p->member_id);
                    // Almost every participant has at most one account, so
                    // this joined string is also each one's clean, single
                    // filter value in the common case; someone with two
                    // different banks just gets their own combined option
                    // in the filter list rather than silently hiding one.
                    $bank_names = array_values(array_unique(array_map(fn($k) => AVBK_DB::iban_bank_name($k->iban), $known_ibans)));
                    $countries = array_values(array_unique(array_map(fn($k) => AVBK_DB::iban_country($k->iban), $known_ibans)));
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $p->member_id], admin_url('admin.php'))); ?>" target="_blank"><?php echo esc_html(avpvh_format_name($p, 'list')); ?></a></td>
                        <td data-filter-value="<?php echo esc_attr(implode(', ', wp_list_pluck($known_ibans, 'iban'))); ?>">
                            <?php if (!$known_ibans) : ?>&mdash;<?php else : foreach ($known_ibans as $known) : ?>
                                <div><?php echo esc_html($known->iban); ?><?php echo $known->account_name ? ' (' . esc_html($known->account_name) . ')' : ''; ?></div>
                            <?php endforeach; endif; ?>
                        </td>
                        <td data-filter-value="<?php echo esc_attr(implode(', ', $bank_names)); ?>"><?php echo esc_html($bank_names ? implode(', ', $bank_names) : '—'); ?></td>
                        <td data-filter-value="<?php echo esc_attr(implode(', ', $countries)); ?>"><?php echo esc_html($countries ? implode(', ', $countries) : '—'); ?></td>
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
                                <?php
                                $preview_email_url = wp_nonce_url(
                                    add_query_arg(
                                        ['action' => 'avbk_preview_request_payment_email', 'activity_id' => $activity_id, 'member_id' => $p->member_id],
                                        admin_url('admin-post.php')
                                    ),
                                    'avbk_preview_request_payment_email'
                                );
                                ?>
                                <a href="<?php echo esc_url($preview_email_url); ?>" target="_blank" rel="noopener" class="button button-secondary button-small" style="margin-left:.3em">Vraag om betaling en voeg nog iets toe</a>
                                <?php
                                $preview_url = wp_nonce_url(
                                    add_query_arg(
                                        ['action' => 'avbk_preview_payment_request', 'activity_id' => $activity_id, 'member_id' => $p->member_id],
                                        admin_url('admin-post.php')
                                    ),
                                    'avbk_preview_payment_request'
                                );
                                ?>
                                <a href="<?php echo esc_url($preview_url); ?>" target="_blank" rel="noopener" class="button button-secondary button-small" style="margin-left:.3em">Toon QR</a>
                            <?php endif; ?>
                            <?php if ($payment_request) : ?>
                                <div class="description" style="white-space:nowrap">Gevraagd op <?php echo esc_html(mysql2date('d-m-Y H:i', $payment_request->requested_at)); ?></div>
                            <?php elseif ($remaining <= 0.005) : ?>&mdash;<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <th>Totaal</th><th></th><th></th><th></th><th></th><th></th><th></th>
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
