<?php
defined('ABSPATH') || exit;

/** Renders the admin/expenses.php page: outgoing transactions to explain, and the explained ones per year. */
function avbk_render_expenses(): void {
    if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
        wp_die('Geen toegang.');
    }
    $tab = sanitize_key(wp_unslash($_GET['tab'] ?? '')) === 'explained' ? 'explained' : 'open';
    $base_url = add_query_arg(['page' => 'avbk-expenses'], admin_url('admin.php'));
    $closed_through_year = (int) get_option('avbk_closed_through_year', 0);
    $min_year = $closed_through_year > 0 ? $closed_through_year + 1 : 0;
    $open_count = AVBK_DB::count_unexplained_outgoing($min_year);
    ?>
<div class="wrap">
    <h1>Uitgaven</h1>
    <p class="description">
        Elke uitgaande betaling krijgt hier een verantwoording: een factuur met het document, een declaratie, een teruggestorte betaling, bankkosten of een interne overboeking.
        Een verantwoorde uitgave gaat daarna naar Tweede controle. Een terugbetaling van een bijdrage koppel je bij het lid, via Terugbetalen.
    </p>
    <?php avbk_render_expenses_notices(); ?>
    <nav class="nav-tab-wrapper">
        <a href="<?php echo esc_url($base_url); ?>" class="nav-tab<?php echo $tab === 'open' ? ' nav-tab-active' : ''; ?>">Te verantwoorden (<?php echo esc_html($open_count); ?>)</a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'explained', $base_url)); ?>" class="nav-tab<?php echo $tab === 'explained' ? ' nav-tab-active' : ''; ?>">Verantwoord</a>
    </nav>
    <?php
    if ($tab === 'open') {
        avbk_render_expenses_open($min_year, $closed_through_year);
    } else {
        avbk_render_expenses_explained();
    }
    ?>
</div>
<?php
}

/** Result notices of saving or removing an explanation. */
function avbk_render_expenses_notices(): void {
    $messages = [
        'no_transaction'    => 'De transactie is niet gevonden.',
        'not_outgoing'      => 'Deze transactie is geen uitgaande betaling die nog verantwoord moet worden.',
        'unknown_kind'      => 'Kies wat voor uitgave dit is.',
        'no_supplier'       => 'Vul de leverancier van de factuur in.',
        'no_document'       => 'Upload de factuur (pdf of foto).',
        'document_invalid'  => 'Het document is niet opgeslagen: kies een pdf of foto (jpg, png, webp, heic) van maximaal 25 MB.',
        'no_reimbursement'  => 'Kies de declaratie die met deze betaling is uitbetaald, of upload de bon als de declaratie niet via de website is ingediend.',
        'no_related'        => 'Kies de binnengekomen betaling die is teruggestort.',
        'related_allocated' => 'Die betaling is aan bijdragen toegewezen. Gebruik bij het lid Terugbetalen bij de bijdrage.',
        'related_returned'  => 'Die betaling is al als teruggestort verantwoord.',
        'amount_mismatch'   => 'Het bedrag komt niet overeen met de gekozen declaratie of betaling.',
        'no_note'           => 'Beschrijf bij Overig waar de betaling voor was.',
        'not_found'         => 'Deze verantwoording of dit verzoek bestaat niet meer.',
        'kind_fixed'        => 'Deze uitgave is gekoppeld aan een declaratie of een binnengekomen betaling. Verwijder de verantwoording en verantwoord haar opnieuw om een andere soort te kiezen.',
        'request_failed'    => 'De bon kon niet worden opgevraagd. Kies het lid aan wie je de bon vraagt.',
    ];
    $requested = [
        'mailed'     => ['success', 'Bon opgevraagd. Het lid heeft een mail gekregen en ziet het verzoek onder &ldquo;Te doen&rdquo; op de ledenpagina.'],
        'not_mailed' => ['warning', 'Bon opgevraagd. Het lid ziet het verzoek onder &ldquo;Te doen&rdquo; op de ledenpagina, maar de mail is niet verstuurd (zie Verzonden mails).'],
        'no_address' => ['warning', 'Bon opgevraagd. Het lid ziet het verzoek onder &ldquo;Te doen&rdquo; op de ledenpagina; er is geen mail gestuurd, want het lid heeft geen bruikbaar e-mailadres.'],
    ];
    if (isset($_GET['document_requested'])) :
        [$type, $text] = $requested[sanitize_key(wp_unslash($_GET['document_requested']))] ?? $requested['mailed']; ?>
        <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible"><p><?php echo wp_kses($text, []); ?></p></div>
    <?php elseif (isset($_GET['document_request_withdrawn'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Verzoek ingetrokken.</p></div>
    <?php elseif (isset($_GET['explained'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Uitgave verantwoord. Ze staat nu bij Tweede controle.</p></div>
    <?php elseif (isset($_GET['updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Verantwoording aangepast. Ze staat weer bij Tweede controle.</p></div>
    <?php elseif (isset($_GET['removed'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Verantwoording verwijderd. De betaling staat weer bij Te verantwoorden.</p></div>
    <?php elseif (isset($_GET['explain_failed'])) :
        $code = sanitize_key(wp_unslash($_GET['explain_failed'])); ?>
        <div class="notice notice-error"><p><?php echo esc_html($messages[$code] ?? 'De verantwoording is niet opgeslagen.'); ?></p></div>
    <?php endif;
}

/**
 * The category suggestions and the activities, once for the whole page;
 * assets/expenses.js copies the activities into each form's select.
 *
 * @param object[] $activities
 * @param string[] $categories
 */
function avbk_render_expense_lists(array $activities, array $categories): void {
    ?>
    <datalist id="avbk-expense-categories">
        <?php foreach ($categories as $category) : ?><option value="<?php echo esc_attr($category); ?>"></option><?php endforeach; ?>
    </datalist>
    <template id="avbk-expense-activities"><?php foreach ($activities as $activity) : ?><option value="<?php echo esc_attr($activity->id); ?>"><?php echo esc_html(avbk_expense_activity_label($activity)); ?></option><?php endforeach; ?></template>
    <?php
}

function avbk_expense_activity_label(object $activity): string {
    return $activity->name . ' (' . $activity->year . ')';
}

/** @return string[] Categories for the suggestions: the ones used before, then the defaults. */
function avbk_expense_categories(): array {
    return array_values(array_unique(array_merge(AVBK_DB::get_expense_categories(), AVBK_Expenses::DEFAULT_CATEGORIES)));
}

/** The outgoing transactions still waiting for an explanation. */
function avbk_render_expenses_open(int $min_year, int $closed_through_year): void {
    $transactions = AVBK_DB::get_unexplained_outgoing($min_year);
    if ($closed_through_year) : ?>
        <p class="description">Uitgaven tot en met <?php echo esc_html($closed_through_year); ?> zijn afgesloten en staan hier niet.</p>
    <?php endif;
    if (!$transactions) : ?>
        <p>Alle uitgaande betalingen zijn verantwoord.</p>
        <?php return;
    endif;
    $context = [
        'activities'     => AVPVH_DB::get_activities(),
        'reimbursements' => AVBK_DB::get_reimbursement_candidates(),
        'categories'     => avbk_expense_categories(),
        'members'        => AVBK_DB::get_payable_members(),
    ];
    ?>
    <?php // Every member once for the whole page; assets/expenses.js copies them into each "Bon opvragen" select. ?>
    <template id="avbk-expense-members"><optgroup label="Alle leden"><?php foreach ($context['members'] as $member) : ?><option value="<?php echo esc_attr($member->id); ?>"><?php echo esc_html(avpvh_format_name($member, 'list')); ?></option><?php endforeach; ?></optgroup></template>
    <?php avbk_render_expense_lists($context['activities'], $context['categories']); ?>
    <?php foreach ($transactions as $tx) {
        avbk_render_expense_form($tx, $context);
    }
}

/**
 * One outgoing transaction with its explanation form. Fields carry the kinds
 * they belong to (data-kinds); assets/expenses.js shows only those.
 *
 * @param array{activities: object[], reimbursements: object[], categories: string[], members: object[]} $context
 */
function avbk_render_expense_form(object $tx, array $context): void {
    $field = 'avbk-expense-' . (int) $tx->id;
    $failed_here = (int) ($_GET['tx'] ?? 0) === (int) $tx->id;
    $returned = AVBK_Expenses::rank_returned_candidates(AVBK_DB::get_returned_payment_candidates($tx), (float) $tx->amount);
    $reimbursements = AVBK_Expenses::rank_reimbursements($context['reimbursements'], (float) $tx->amount, (string) $tx->counterparty_iban);
    $member_id = $tx->counterparty_iban !== '' ? AVBK_DB::find_member_id_by_iban((string) $tx->counterparty_iban) : null;
    ?>
    <div class="avbk-review-row" id="tx-<?php echo esc_attr($tx->id); ?>" style="margin:1rem 0;padding:1rem;background:#fff;border:1px solid #dcdcde<?php echo $failed_here ? ';border-color:#d63638' : ''; ?>">
        <p style="margin-top:0">
            <strong><?php echo esc_html(wp_date('d-m-Y', strtotime($tx->transaction_date))); ?> &mdash; &euro; <?php echo esc_html(number_format((float) $tx->amount, 2, ',', '.')); ?> &mdash; <?php echo esc_html($tx->counterparty_name ?: 'onbekende ontvanger'); ?></strong>
            <?php if ($tx->counterparty_iban !== '') : ?><br><code><?php echo esc_html($tx->counterparty_iban); ?></code><?php endif; ?>
            <br><span class="description"><?php echo esc_html(AVBK_Matcher::strip_name_field((string) $tx->description)); ?></span>
        </p>
        <?php if ($member_id) : ?>
            <p class="description">Deze rekening hoort bij een lid. Is dit een terugbetaling van een bijdrage of van tegoed? <a href="<?php echo esc_url(add_query_arg(['page' => 'avbk-members', 'member_id' => $member_id, 'refund_tx' => (int) $tx->id], admin_url('admin.php'))); ?>">Koppel die bij het lid</a>.</p>
        <?php endif; ?>
        <?php avbk_render_expense_document_request($tx, $context['members']); ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="avbk-expense-form">
            <?php wp_nonce_field('avbk_explain_expense'); ?>
            <input type="hidden" name="action" value="avbk_explain_expense">
            <input type="hidden" name="transaction_id" value="<?php echo esc_attr($tx->id); ?>">
            <?php avbk_render_expense_kind_field($field, AVBK_Expenses::KINDS, ''); ?>
            <?php avbk_render_expense_detail_fields($field, ['supplier' => (string) $tx->counterparty_name], 'of sleep het bestand op deze betaling'); ?>
            <?php avbk_render_expense_reimbursement_field($field, $reimbursements, (float) $tx->amount); ?>
            <?php avbk_render_expense_returned_field($field, $returned, (float) $tx->amount); ?>
            <?php avbk_render_expense_note_field($field, ''); ?>
            <button type="submit" class="button button-primary">Verantwoorden</button>
        </form>
    </div>
    <?php
}

/**
 * The kind select. With one kind (an explanation tied to a declaratie or
 * an incoming payment) it can't be changed.
 *
 * @param array<string, string> $kinds
 */
function avbk_render_expense_kind_field(string $field, array $kinds, string $selected): void {
    ?>
            <p>
                <label for="<?php echo esc_attr($field); ?>-kind">Soort uitgave</label><br>
                <select name="kind" id="<?php echo esc_attr($field); ?>-kind" required class="avbk-expense-kind">
                    <?php if (count($kinds) > 1) : ?><option value="">&mdash; kies &mdash;</option><?php endif; ?>
                    <?php foreach ($kinds as $kind => $label) : ?>
                        <option value="<?php echo esc_attr($kind); ?>"<?php selected($kind, $selected); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
    <?php
}

/**
 * Supplier, category, activity and document, each shown for the kinds it
 * belongs to (data-kinds, see AVBK_Expenses::FIELD_KINDS).
 *
 * @param array{supplier?: string, category?: string, activity_id?: int|string|null, document_url?: string} $values
 */
function avbk_render_expense_detail_fields(string $field, array $values, string $document_hint): void {
    ?>
            <p data-kinds="invoice">
                <label for="<?php echo esc_attr($field); ?>-supplier">Leverancier</label><br>
                <input type="text" name="supplier" id="<?php echo esc_attr($field); ?>-supplier" value="<?php echo esc_attr($values['supplier'] ?? ''); ?>" class="regular-text">
            </p>
            <p data-kinds="invoice reimbursement other bank_costs transfer">
                <label for="<?php echo esc_attr($field); ?>-category">Categorie</label><br>
                <input type="text" name="category" id="<?php echo esc_attr($field); ?>-category" value="<?php echo esc_attr($values['category'] ?? ''); ?>" list="avbk-expense-categories" class="regular-text">
            </p>
            <p data-kinds="invoice reimbursement other">
                <label for="<?php echo esc_attr($field); ?>-activity">Activiteit (optioneel)</label><br>
                <select name="activity_id" id="<?php echo esc_attr($field); ?>-activity" style="max-width:28em" data-options="avbk-expense-activities" data-value="<?php echo esc_attr((int) ($values['activity_id'] ?? 0)); ?>">
                    <option value="0">&mdash; geen &mdash;</option>
                </select>
            </p>
            <p data-kinds="invoice reimbursement other">
                <label for="<?php echo esc_attr($field); ?>-document">Document (pdf of foto; verplicht bij een factuur, en bij een declaratie die niet via de website is ingediend)</label><br>
                <?php if (!empty($values['document_url'])) : ?>
                    Nu: <a href="<?php echo esc_url($values['document_url']); ?>" target="_blank" rel="noopener">bekijk</a>. Kies alleen een bestand om het te vervangen.<br>
                <?php endif; ?>
                <input type="file" name="document" id="<?php echo esc_attr($field); ?>-document" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif">
                <span class="description avbk-drop-hint"><?php echo esc_html($document_hint); ?></span>
            </p>
    <?php
}

function avbk_render_expense_note_field(string $field, string $note): void {
    ?>
            <p data-kinds="invoice reimbursement returned_payment bank_costs transfer other">
                <label for="<?php echo esc_attr($field); ?>-note">Toelichting<span data-kinds="other"> (verplicht bij Overig)</span></label><br>
                <textarea name="note" id="<?php echo esc_attr($field); ?>-note" rows="2" class="large-text" maxlength="500"><?php echo esc_textarea($note); ?></textarea>
            </p>
    <?php
}

/**
 * The receipt asked from a member for this payment: a member to choose
 * (the account's and look-alike names first) and a button to ask, or
 * whether it came in yet, with a withdraw button. Separate forms, outside
 * the explanation form.
 *
 * @param object[] $members Every member that can be asked.
 */
function avbk_render_expense_document_request(object $tx, array $members): void {
    $request = AVBK_DB::get_document_request_for_transaction((int) $tx->id);
    $field = 'avbk-expense-' . (int) $tx->id . '-request-member';
    ?>
        <div class="avbk-expense-request" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin:.5rem 0">
            <?php if (!$request) :
                $suggestions = AVBK_Document_Requests::suggestions($tx, $members); ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
                    <?php wp_nonce_field('avbk_request_document'); ?>
                    <input type="hidden" name="action" value="avbk_request_document">
                    <input type="hidden" name="transaction_id" value="<?php echo esc_attr($tx->id); ?>">
                    <label for="<?php echo esc_attr($field); ?>">Bon opvragen bij</label>
                    <select name="member_id" id="<?php echo esc_attr($field); ?>" required style="max-width:22em" data-options="avbk-expense-members">
                        <?php if (!$suggestions) : ?><option value="">&mdash; kies een lid &mdash;</option><?php endif; ?>
                        <?php if ($suggestions) : ?>
                            <optgroup label="Voorstel">
                                <?php foreach ($suggestions as $suggested) : ?><option value="<?php echo esc_attr($suggested->id); ?>"><?php echo esc_html(avpvh_format_name($suggested, 'list')); ?></option><?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                    <button type="submit" class="button button-small">Bon opvragen</button>
                </form>
                <span class="description">Het lid krijgt een mail en kan op de ledenpagina, onder Te doen, een foto of pdf uploaden.</span>
            <?php else :
                $member = AVPVH_DB::get_member((int) $request->member_id);
                $name = $member ? avpvh_format_name($member) : 'het lid'; ?>
                <span>
                    <?php if ($request->document_path !== '') : ?>
                        <strong>Bon ontvangen</strong> van <?php echo esc_html($name); ?> op <?php echo esc_html(wp_date('d-m-Y', strtotime((string) $request->uploaded_at))); ?>:
                        <a href="<?php echo esc_url(AVBK_Document_Requests::view_url($request)); ?>" target="_blank" rel="noopener">bekijk</a>.
                        Wordt gebruikt als je hieronder geen ander document kiest.
                    <?php else : ?>
                        Bon gevraagd aan <?php echo esc_html($name); ?> op <?php echo esc_html(wp_date('d-m-Y', strtotime((string) $request->requested_at))); ?>; nog niet geüpload.
                    <?php endif; ?>
                </span>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('avbk_withdraw_document_request'); ?>
                    <input type="hidden" name="action" value="avbk_withdraw_document_request">
                    <input type="hidden" name="document_request_id" value="<?php echo esc_attr($request->id); ?>">
                    <button type="submit" class="button-link button-link-delete" onclick="return confirm('Dit verzoek intrekken?');">intrekken</button>
                </form>
            <?php endif; ?>
        </div>
    <?php
}

/** Select of the reimbursement this transfer paid, best match first. */
function avbk_render_expense_reimbursement_field(string $field, array $reimbursements, float $amount): void {
    ?>
            <p data-kinds="reimbursement">
                <label for="<?php echo esc_attr($field); ?>-reimbursement">Declaratie</label><br>
                <?php if (!$reimbursements) : ?>
                    <span class="description">Er is geen ingediende declaratie zonder gekoppelde betaling. Is de declaratie buiten de website om gedaan? Upload dan hieronder de bon.</span>
                <?php else : ?>
                    <select name="reimbursement_id" id="<?php echo esc_attr($field); ?>-reimbursement" style="max-width:36em">
                        <?php foreach ($reimbursements as $r) :
                            $member = AVPVH_DB::get_member((int) $r->member_id);
                            $label = sprintf(
                                '#%d — %s — € %s — %s%s',
                                (int) $r->id,
                                $member ? avpvh_format_name($member, 'list') : 'onbekend lid',
                                number_format((float) $r->amount, 2, ',', '.'),
                                $r->description,
                                $r->status === 'paid' ? ' (al op uitbetaald gezet)' : ''
                            );
                            ?>
                            <option value="<?php echo esc_attr($r->id); ?>"><?php echo esc_html($label . (abs((float) $r->amount - $amount) < 0.005 ? ' · zelfde bedrag' : '')); ?></option>
                        <?php endforeach; ?>
                        <option value="0">&mdash; niet via de website ingediend: bon hieronder uploaden &mdash;</option>
                    </select>
                <?php endif; ?>
            </p>
    <?php
}

/** Select of the incoming payment this transfer sent back. */
function avbk_render_expense_returned_field(string $field, array $returned, float $amount): void {
    ?>
            <p data-kinds="returned_payment">
                <label for="<?php echo esc_attr($field); ?>-related">Teruggestorte betaling</label><br>
                <?php if (!$returned) : ?>
                    <span class="description">Geen eerdere, niet-toegewezen betaling van deze rekening gevonden. Was de betaling aan een bijdrage toegewezen? Gebruik dan bij het lid Terugbetalen.</span>
                <?php else : ?>
                    <select name="related_transaction_id" id="<?php echo esc_attr($field); ?>-related" style="max-width:36em">
                        <?php foreach ($returned as $payment) : ?>
                            <option value="<?php echo esc_attr($payment->id); ?>"><?php echo esc_html(sprintf(
                                '#%d — %s — € %s — %s%s',
                                (int) $payment->id,
                                wp_date('d-m-Y', strtotime($payment->transaction_date)),
                                number_format((float) $payment->amount, 2, ',', '.'),
                                mb_strimwidth(AVBK_Matcher::strip_name_field((string) $payment->description), 0, 60, '…'),
                                abs((float) $payment->amount - $amount) < 0.005 ? ' · zelfde bedrag' : ''
                            )); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </p>
    <?php
}

/** The explained expenses of one year, with totals per kind and category. */
function avbk_render_expenses_explained(): void {
    $year = (int) ($_GET['year'] ?? 0) ?: (int) current_time('Y');
    $expenses = AVBK_DB::get_expenses_for_year($year);
    $totals = [];
    foreach ($expenses as $expense) {
        $key = (AVBK_Expenses::KINDS[$expense->kind] ?? $expense->kind) . ($expense->category !== '' ? ' — ' . $expense->category : '');
        $totals[$key] = round(($totals[$key] ?? 0) + (float) $expense->amount, 2);
    }
    arsort($totals);
    ?>
    <form method="get" style="margin:1rem 0">
        <input type="hidden" name="page" value="avbk-expenses">
        <input type="hidden" name="tab" value="explained">
        <label for="avbk-expense-year">Jaar</label>
        <input type="number" name="year" id="avbk-expense-year" value="<?php echo esc_attr($year); ?>" min="2000" max="2100" style="width:6em">
        <button type="submit" class="button">Tonen</button>
    </form>
    <?php if (!$expenses) : ?>
        <p>Geen verantwoorde uitgaven in <?php echo esc_html($year); ?>.</p>
        <?php return;
    endif; ?>
    <h2>Totaal per soort en categorie</h2>
    <table class="widefat striped" style="max-width:40em">
        <tbody>
            <?php foreach ($totals as $label => $total) : ?>
                <tr><td><?php echo esc_html($label); ?></td><td style="text-align:right">&euro; <?php echo esc_html(number_format($total, 2, ',', '.')); ?></td></tr>
            <?php endforeach; ?>
            <tr><th scope="row">Totaal</th><td style="text-align:right;font-weight:600">&euro; <?php echo esc_html(number_format(array_sum($totals), 2, ',', '.')); ?></td></tr>
        </tbody>
    </table>
    <h2>Uitgaven <?php echo esc_html($year); ?></h2>
    <?php
    $activities = AVPVH_DB::get_activities();
    $activity_labels = [];
    foreach ($activities as $activity) {
        $activity_labels[(int) $activity->id] = avbk_expense_activity_label($activity);
    }
    avbk_render_expense_lists($activities, avbk_expense_categories());
    ?>
    <p class="description">Filter in de velden onder de kolomkoppen en klik op een kolomkop om te sorteren; het totaal onderaan telt de zichtbare uitgaven. Met &ldquo;bewerken&rdquo; pas je een verantwoording aan; die moet daarna opnieuw door Tweede controle.</p>
    <div data-avpvh-sortable>
    <div class="avpvh-table-tools">
        <span class="avpvh-table-count" aria-live="polite"></span>
        <button type="button" class="button button-small avpvh-col-toggle-btn">Kolommen</button>
        <span class="avpvh-col-toggle-panel" hidden></span>
    </div>
    <div class="avpvh-table-wrap">
    <table class="widefat striped avpvh-sortable-table" data-storage-key="avbk_expenses_hidden_cols">
        <thead><tr>
            <th scope="col" data-col="datum">Datum</th>
            <th scope="col" data-col="bedrag" data-type="number">Bedrag</th>
            <th scope="col" data-col="aan">Aan</th>
            <th scope="col" data-col="soort" data-filter="select">Soort</th>
            <th scope="col" data-col="categorie" data-filter="select">Categorie</th>
            <th scope="col" data-col="activiteit" data-filter="select" class="avpvh-col-optional">Activiteit</th>
            <th scope="col" data-col="details">Details</th>
            <th scope="col" data-col="document" data-filter="select">Document</th>
            <th scope="col" data-col="controle" data-filter="select">Tweede controle</th>
            <td><span class="screen-reader-text">Acties</span></td>
        </tr></thead>
        <tbody>
            <?php foreach ($expenses as $expense) {
                avbk_render_expense_row($expense, $activity_labels, $year);
            } ?>
        </tbody>
        <tfoot><tr>
            <th scope="row">Totaal</th><td data-total-col="bedrag"></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
        </tr></tfoot>
    </table>
    </div>
    </div>
    <?php
}

/**
 * One explained expense: its columns, and the edit dialog and remove button.
 *
 * @param array<int, string> $activity_labels
 */
function avbk_render_expense_row(object $expense, array $activity_labels, int $year): void {
    $tx_url = add_query_arg(['page' => 'avbk-transactions', 'show_all_years' => '1'], admin_url('admin.php')) . '#tx-' . (int) $expense->transaction_id;
    $has_document = (string) $expense->document_path !== '';
    $activity = $activity_labels[(int) $expense->activity_id] ?? '';
    $dialog = 'avbk-expense-edit-' . (int) $expense->id;
    ?>
            <tr id="expense-<?php echo esc_attr($expense->id); ?>">
                <td data-sort-value="<?php echo esc_attr($expense->transaction_date); ?>"><a href="<?php echo esc_url($tx_url); ?>"><?php echo esc_html(wp_date('d-m-Y', strtotime($expense->transaction_date))); ?></a></td>
                <td data-sort-value="<?php echo esc_attr(number_format((float) $expense->amount, 2, '.', '')); ?>">&euro; <?php echo esc_html(number_format((float) $expense->amount, 2, ',', '.')); ?></td>
                <td><?php echo esc_html($expense->counterparty_name); ?></td>
                <td><?php echo esc_html(AVBK_Expenses::KINDS[$expense->kind] ?? $expense->kind); ?></td>
                <td data-filter-value="<?php echo esc_attr($expense->category !== '' ? $expense->category : 'geen'); ?>"><?php echo esc_html($expense->category); ?></td>
                <td data-filter-value="<?php echo esc_attr($activity !== '' ? $activity : 'geen'); ?>"><?php echo esc_html($activity); ?></td>
                <td><?php echo esc_html(implode(' — ', AVBK_Expenses::details($expense))); ?></td>
                <td data-filter-value="<?php echo $has_document ? 'met document' : 'zonder document'; ?>"><?php if ($has_document) : ?><a href="<?php echo esc_url(AVBK_Expenses::document_url($expense)); ?>" target="_blank" rel="noopener">bekijk</a><?php else : ?>&mdash;<?php endif; ?></td>
                <td><?php echo $expense->second_approved_by ? 'akkoord' : 'wacht'; ?></td>
                <td style="white-space:nowrap">
                    <button type="button" class="button-link" data-dialog="<?php echo esc_attr($dialog); ?>">bewerken</button> |
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <?php wp_nonce_field('avbk_remove_expense'); ?>
                        <input type="hidden" name="action" value="avbk_remove_expense">
                        <input type="hidden" name="expense_id" value="<?php echo esc_attr($expense->id); ?>">
                        <button type="submit" class="button-link button-link-delete" onclick="return confirm('Deze verantwoording verwijderen? De betaling komt weer bij Te verantwoorden.');">verwijderen</button>
                    </form>
                    <?php avbk_render_expense_edit_dialog($expense, $dialog, $year); ?>
                </td>
            </tr>
    <?php
}

/** The edit form of one explanation, in a dialog opened by its "bewerken" button. */
function avbk_render_expense_edit_dialog(object $expense, string $dialog, int $year): void {
    $field = 'avbk-expense-edit-field-' . (int) $expense->id;
    ?>
                    <dialog id="<?php echo esc_attr($dialog); ?>" class="avbk-review-row" style="max-width:40em;white-space:normal">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="avbk-expense-form">
                            <h2 style="margin-top:0"><?php echo esc_html(wp_date('d-m-Y', strtotime($expense->transaction_date)) . ' — € ' . number_format((float) $expense->amount, 2, ',', '.') . ' — ' . $expense->counterparty_name); ?></h2>
                            <?php wp_nonce_field('avbk_edit_expense'); ?>
                            <input type="hidden" name="action" value="avbk_edit_expense">
                            <input type="hidden" name="expense_id" value="<?php echo esc_attr($expense->id); ?>">
                            <input type="hidden" name="year" value="<?php echo esc_attr($year); ?>">
                            <?php avbk_render_expense_kind_field($field, AVBK_Expenses::editable_kinds($expense), (string) $expense->kind); ?>
                            <?php if (count(AVBK_Expenses::editable_kinds($expense)) === 1) : ?>
                                <p class="description">Deze uitgave is gekoppeld aan een declaratie of een binnengekomen betaling. Een andere soort kies je door de verantwoording te verwijderen en opnieuw te verantwoorden.</p>
                            <?php endif; ?>
                            <?php avbk_render_expense_detail_fields($field, [
                                'supplier'     => (string) $expense->supplier,
                                'category'     => (string) $expense->category,
                                'activity_id'  => $expense->activity_id,
                                'document_url' => (string) $expense->document_path !== '' ? AVBK_Expenses::document_url($expense) : '',
                            ], 'of sleep het bestand hierop'); ?>
                            <?php avbk_render_expense_note_field($field, (string) $expense->note); ?>
                            <p>
                                <button type="submit" class="button button-primary">Opslaan</button>
                                <button type="submit" class="button" formmethod="dialog" formnovalidate>Annuleren</button>
                            </p>
                        </form>
                    </dialog>
    <?php
}
