<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$open = AVBK_DB::get_disputes('open');
$resolved = AVBK_DB::get_disputes('resolved');
$result = sanitize_key(wp_unslash($_GET['dispute_result'] ?? ''));
$focused_id = absint(wp_unslash($_GET['dispute_id'] ?? 0));
$show_resolved = in_array($focused_id, array_map(fn($item) => (int) $item->id, $resolved), true);
$notices = [
    'reply_sent' => ['success', 'Het antwoord is aangeboden aan de mailserver en opgeslagen in de historie. Het bezwaar blijft open totdat je het afhandelt.'],
    'note_saved' => ['success', 'Interne notitie opgeslagen. Er is geen e-mail verstuurd.'],
    'resolved' => ['success', 'Bezwaar afgehandeld. De actie staat in de historie.'],
    'reopened' => ['success', 'Bezwaar heropend. De eerdere historie blijft bewaard.'],
    'duplicate' => ['info', 'Deze actie is al verwerkt. Er is geen tweede antwoord verstuurd.'],
    'unchanged' => ['info', 'Dit bezwaar had deze status al.'],
    'reply_failed' => ['error', 'Verzenden is mislukt. Het antwoord staat in de historie en als concept klaar om opnieuw te proberen.'],
    'tracking_failed' => ['error', 'De verzendstatus kon niet worden opgeslagen. Controleer de verzending voordat je opnieuw verstuurt.'],
    'save_failed' => ['error', 'De actie kon niet worden opgeslagen. Er is geen nieuw antwoord verstuurd. Je tekst staat nog als concept klaar.'],
    'missing_email' => ['error', 'Er is geen geldig e-mailadres voor de ontvanger. Het antwoord is niet verstuurd.'],
    'empty_message' => ['error', 'Vul eerst een antwoord of interne notitie in.'],
    'invalid_action' => ['error', 'Ongeldige actie. Herlaad de pagina en probeer opnieuw.'],
    'not_found' => ['error', 'Dit bezwaar bestaat niet meer.'],
];
?>
<div class="wrap avbk-disputes">
    <h1>Bezwaren</h1>
    <p class="description">Behandel berichten over het ledenoverzicht. Antwoorden gaan per e-mail naar de indiener; interne notities blijven alleen zichtbaar voor de penningmeester en beheerders.</p>
    <?php if (isset($notices[$result])) : ?>
        <div class="notice notice-<?php echo esc_attr($notices[$result][0]); ?> is-dismissible"><p><?php echo esc_html($notices[$result][1]); ?></p></div>
    <?php endif; ?>

    <h2>Open (<?php echo esc_html(count($open)); ?>)</h2>
    <?php if (!$open) : ?><p>Niets openstaand.</p><?php endif; ?>
    <?php foreach ($open as $dispute) : ?>
        <?php require AVBK_PLUGIN_DIR . 'admin/dispute-card.php'; ?>
    <?php endforeach; ?>

    <?php if ($resolved) : ?>
        <details class="avbk-disputes-resolved" <?php echo $show_resolved ? 'open' : ''; ?>>
            <summary>Afgehandeld (<?php echo esc_html(count($resolved)); ?>)</summary>
            <?php foreach ($resolved as $dispute) : ?>
                <?php require AVBK_PLUGIN_DIR . 'admin/dispute-card.php'; ?>
            <?php endforeach; ?>
        </details>
    <?php endif; ?>
</div>
