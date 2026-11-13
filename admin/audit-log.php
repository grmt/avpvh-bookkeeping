<?php
defined('ABSPATH') || exit;

/** Renders the Logboek page: who changed what in the bookkeeping, newest first. */
function avbk_render_audit_log(): void {
    if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
        wp_die('Geen toegang.');
    }
    $filters = avbk_audit_log_filters();
    $per_page = 100;
    $paged = max(1, absint(wp_unslash($_GET['paged'] ?? 1)));
    $result = AVBK_Audit::search($filters, $per_page, ($paged - 1) * $per_page);
    ?>
    <div class="wrap">
        <h1>Logboek</h1>
        <p class="description">Wie wat heeft gewijzigd in de boekhouding. Bewaard voor <?php echo (int) AVBK_Audit::KEEP_YEARS; ?> jaar. Tijden in Nederlandse tijd.</p>
        <?php avbk_render_audit_log_filter_form($filters); ?>
        <p><?php echo esc_html(sprintf('%d regels', $result['total'])); ?></p>
        <?php avbk_render_audit_log_table($result['rows']); ?>
        <?php avbk_render_audit_log_pagination($result['total'], $per_page, $paged); ?>
    </div>
    <?php
}

/** The filters from the query string. */
function avbk_audit_log_filters(): array {
    $date = static function (string $key): string {
        $value = sanitize_text_field(wp_unslash($_GET[$key] ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    };
    $object_type = sanitize_key(wp_unslash($_GET['object_type'] ?? ''));
    return [
        'member_id'     => absint(wp_unslash($_GET['member_id'] ?? 0)),
        'object_type'   => in_array($object_type, ['order', 'fee_item', 'transaction'], true) ? $object_type : '',
        'object_id'     => absint(wp_unslash($_GET['object_id'] ?? 0)),
        'request_id'    => preg_match('/^[0-9a-f-]{32,36}$/', (string) wp_unslash($_GET['request_id'] ?? '')) ? (string) wp_unslash($_GET['request_id']) : '',
        'from'          => $date('from'),
        'to'            => $date('to'),
        'with_requests' => !empty($_GET['with_requests']),
    ];
}

function avbk_render_audit_log_filter_form(array $filters): void {
    ?>
    <form method="get" class="avbk-audit-filters">
        <input type="hidden" name="page" value="avbk-audit-log">
        <label>Lid-nummer <input type="number" name="member_id" min="0" value="<?php echo $filters['member_id'] ? esc_attr($filters['member_id']) : ''; ?>" class="small-text"></label>
        <label>Soort
            <select name="object_type">
                <option value="">Alles</option>
                <?php foreach (['order' => 'Bestelling', 'fee_item' => 'Bijdrage', 'transaction' => 'Transactie'] as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['object_type'], $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Nummer <input type="number" name="object_id" min="0" value="<?php echo $filters['object_id'] ? esc_attr($filters['object_id']) : ''; ?>" class="small-text"></label>
        <label>Van <input type="date" name="from" value="<?php echo esc_attr($filters['from']); ?>"></label>
        <label>Tot en met <input type="date" name="to" value="<?php echo esc_attr($filters['to']); ?>"></label>
        <label><input type="checkbox" name="with_requests" value="1" <?php checked($filters['with_requests']); ?>> Ook alle knoppen en hun uitkomst</label>
        <button type="submit" class="button">Filteren</button>
        <a class="button-link" href="<?php echo esc_url(admin_url('admin.php?page=avbk-audit-log')); ?>">Wissen</a>
    </form>
    <?php
}

function avbk_render_audit_log_table(array $rows): void {
    if (!$rows) {
        echo '<p>Geen regels gevonden.</p>';
        return;
    }
    ?>
    <table class="widefat striped">
        <thead><tr><th scope="col">Tijd</th><th scope="col">Wie</th><th scope="col">Wat</th><th scope="col">Waarover</th><th scope="col">Details</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row) : ?>
            <tr>
                <td class="avbk-cell-nowrap"><?php echo esc_html(get_date_from_gmt((string) $row->created_at, 'd-m-Y H:i:s')); ?></td>
                <td><?php echo esc_html(avbk_audit_actor_label($row)); ?></td>
                <td>
                    <?php echo esc_html(AVBK_Audit::label((string) $row->action)); ?>
                    <?php if ($row->request_id !== '') : ?>
                        <br><a href="<?php echo esc_url(add_query_arg(['page' => 'avbk-audit-log', 'request_id' => $row->request_id, 'with_requests' => 1], admin_url('admin.php'))); ?>">alles van deze actie</a>
                    <?php endif; ?>
                </td>
                <td><?php avbk_render_audit_object($row); ?></td>
                <td><?php echo wp_kses(avbk_audit_details_html((string) $row->details), ['br' => [], 'code' => []]); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

function avbk_audit_actor_label(object $row): string {
    $user = (int) $row->user_id ? get_userdata((int) $row->user_id) : false;
    $labels = ['beheer' => 'beheer', 'lid' => 'lid', 'website' => 'via de website', 'systeem' => 'automatisch', 'wp-cli' => 'WP-CLI'];
    $actor = $labels[(string) $row->actor] ?? (string) $row->actor;
    return $user ? $user->display_name . ' (' . $actor . ')' : ucfirst($actor);
}

/** Links to the order, fee item or transaction and to the member, each also as a logboek filter. */
function avbk_render_audit_object(object $row): void {
    $parts = [];
    $names = ['order' => 'Bestelling', 'fee_item' => 'Bijdrage', 'transaction' => 'Transactie'];
    if (isset($names[$row->object_type]) && (int) $row->object_id) {
        $parts[] = '<a href="' . esc_url(add_query_arg(['page' => 'avbk-audit-log', 'object_type' => $row->object_type, 'object_id' => (int) $row->object_id], admin_url('admin.php'))) . '">'
            . esc_html($names[$row->object_type] . ' #' . (int) $row->object_id) . '</a>';
    }
    if ((int) $row->member_id) {
        $member = AVPVH_DB::get_member((int) $row->member_id);
        $name = $member ? avpvh_format_name($member) : 'lid #' . (int) $row->member_id;
        $parts[] = '<a href="' . esc_url(add_query_arg(['page' => 'avbk-members', 'member_id' => (int) $row->member_id], admin_url('admin.php'))) . '">' . esc_html($name) . '</a>'
            . ' <a href="' . esc_url(add_query_arg(['page' => 'avbk-audit-log', 'member_id' => (int) $row->member_id], admin_url('admin.php'))) . '">(logboek)</a>';
    }
    echo wp_kses_post(implode('<br>', $parts));
}

/** A readable list of the stored details: "veld: oud → nieuw" or "veld: waarde". */
function avbk_audit_details_html(string $json): string {
    $details = json_decode($json, true);
    if (!is_array($details) || !$details) {
        return '';
    }
    $lines = [];
    foreach ($details as $key => $value) {
        $lines[] = esc_html(avbk_audit_field_label((string) $key)) . ': ' . avbk_audit_value_html($value);
    }
    return implode('<br>', $lines);
}

function avbk_audit_field_label(string $key): string {
    $labels = [
        'amount_due' => 'bedrag', 'amount' => 'bedrag', 'total_amount' => 'totaal', 'status' => 'status', 'email' => 'e-mailadres',
        'member_id' => 'lid', 'fee_item_id' => 'bijdrage', 'fee_item' => 'bijdrage', 'order_type' => 'soort', 'quantity' => 'aantal',
        'items' => 'artikelen', 'notes' => 'notitie', 'distribution_status' => 'uitgifte', 'description' => 'omschrijving',
        'removed' => 'verwijderd', 'reason' => 'reden', 'duplicate_of' => 'dubbel van', 'year' => 'jaar', 'count' => 'aantal',
        'ids' => 'nummers', 'outcome' => 'uitkomst', 'cancellation_mail' => 'annuleringsmail', 'category' => 'categorie',
        'allocation_id' => 'koppeling', 'activity_id' => 'activiteit',
    ];
    return $labels[$key] ?? $key;
}

/** [old, new] pairs, lists, booleans and nested arrays as short text. */
function avbk_audit_value_html($value): string {
    if (is_bool($value)) {
        return $value ? 'ja' : 'nee';
    }
    if ($value === null || $value === '') {
        return '—';
    }
    if (!is_array($value)) {
        return esc_html((string) $value);
    }
    if (array_keys($value) === [0, 1] && !is_array($value[0]) && !is_array($value[1])) {
        return avbk_audit_value_html($value[0]) . ' → ' . avbk_audit_value_html($value[1]);
    }
    $parts = [];
    foreach ($value as $key => $item) {
        $text = is_array($item) ? implode(', ', array_map(static fn($k, $v) => $k . ' ' . (is_array($v) ? wp_json_encode($v) : $v), array_keys($item), $item)) : (string) $item;
        $parts[] = (is_int($key) ? '' : $key . '=') . $text;
    }
    return '<code>' . esc_html(implode('; ', $parts)) . '</code>';
}

function avbk_render_audit_log_pagination(int $total, int $per_page, int $paged): void {
    $pages = (int) ceil($total / $per_page);
    if ($pages <= 1) {
        return;
    }
    $links = paginate_links([
        'base'    => add_query_arg('paged', '%#%'),
        'format'  => '',
        'current' => $paged,
        'total'   => $pages,
    ]);
    echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post((string) $links) . '</div></div>';
}
