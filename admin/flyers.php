<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$presets = AVBK_Flyer::get_presets();
?>
<div class="wrap">
    <h1>QR-flyers genereren</h1>
    <p class="description">
        Genereer drukklare A4 QR-flyers (HTML) of losse vector QR-codes (SVG) voor een van de vaste presets of voor een eigen URL.
        Open een gedownloade HTML-flyer in je browser en kies <strong>Afdrukken (Ctrl+P / Cmd+P)</strong> of <strong>Opslaan als PDF</strong> om deze direct op A4-formaat af te drukken.
    </p>

    <?php if (isset($_GET['flyer_error'])) : ?>
        <div class="notice notice-error is-dismissible">
            <p>Kon de flyer niet genereren: controleer of er een geldige preset of een geldige URL (<code>https://...</code>) is opgegeven.</p>
        </div>
    <?php endif; ?>

    <h2>Vaste presets</h2>
    <table class="wp-list-table widefat striped" style="max-width:1050px;margin-bottom:2rem">
        <thead>
            <tr>
                <th scope="col">Preset</th>
                <th scope="col">Titel &amp; ondertitel</th>
                <th scope="col">Zichtbare URL</th>
                <th scope="col">Downloaden</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($presets as $key => $preset) : ?>
            <tr>
                <td><code><?php echo esc_html($key); ?></code></td>
                <td>
                    <strong><?php echo esc_html($preset['title']); ?></strong><br>
                    <span class="description"><?php echo esc_html($preset['subtitle']); ?></span>
                </td>
                <td>
                    <code><?php echo esc_html($preset['display_url']); ?></code>
                </td>
                <td style="white-space:nowrap">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <?php wp_nonce_field('avbk_generate_flyer'); ?>
                        <input type="hidden" name="action" value="avbk_generate_flyer">
                        <input type="hidden" name="preset" value="<?php echo esc_attr($key); ?>">
                        <button type="submit" name="format" value="html" class="button button-primary button-small">Genereer flyer (HTML)</button>
                        <button type="submit" name="format" value="svg" class="button button-secondary button-small">QR-code (SVG)</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Eigen flyer maken</h2>
    <p class="description">
        Maak een flyer of losse QR-code voor een willekeurige pagina (bijv. een aanmeldpagina of activiteit).
    </p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:750px">
        <?php wp_nonce_field('avbk_generate_flyer'); ?>
        <input type="hidden" name="action" value="avbk_generate_flyer">
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="avbk-flyer-url">Doel-URL *</label></th>
                <td>
                    <input type="url" id="avbk-flyer-url" name="url" class="regular-text" style="width:100%;max-width:28em" placeholder="https://avpvh.nl/voorbeeld" required>
                    <p class="description">De volledige URL waar de QR-code naartoe verwijst.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="avbk-flyer-display-url">Zichtbare URL</label></th>
                <td>
                    <input type="text" id="avbk-flyer-display-url" name="display_url" class="regular-text" style="width:100%;max-width:28em" placeholder="avpvh.nl/voorbeeld (optioneel)">
                    <p class="description">Korte leesbare URL onder de QR-code. Wordt automatisch afgeleid uit de doel-URL als je dit leeg laat.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="avbk-flyer-title">Hoofdtitel</label></th>
                <td>
                    <input type="text" id="avbk-flyer-title" name="title" class="regular-text" style="width:100%;max-width:28em" placeholder="<?php echo esc_attr(AVBK_Flyer::DEFAULT_TITLE); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="avbk-flyer-subtitle">Ondertitel</label></th>
                <td>
                    <input type="text" id="avbk-flyer-subtitle" name="subtitle" class="regular-text" style="width:100%;max-width:28em" placeholder="<?php echo esc_attr(AVBK_Flyer::DEFAULT_SUBTITLE); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="avbk-flyer-instructions">Instructietekst</label></th>
                <td>
                    <input type="text" id="avbk-flyer-instructions" name="instructions" class="regular-text" style="width:100%;max-width:28em" placeholder="<?php echo esc_attr(AVBK_Flyer::DEFAULT_INSTRUCTIONS); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="avbk-flyer-badge">Badge onderaan</label></th>
                <td>
                    <input type="text" id="avbk-flyer-badge" name="badge" class="regular-text" style="width:100%;max-width:28em" value="<?php echo esc_attr(AVBK_Flyer::DEFAULT_BADGE); ?>">
                </td>
            </tr>
        </table>
        <p class="submit" style="display:flex;gap:8px;align-items:center">
            <button type="submit" name="format" value="html" class="button button-primary">Genereer flyer (HTML)</button>
            <button type="submit" name="format" value="svg" class="button button-secondary">Download QR-code (SVG)</button>
        </p>
    </form>
</div>
