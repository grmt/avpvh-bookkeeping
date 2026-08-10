<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}
?>
<div class="wrap">
    <h1>Bankexport uploaden</h1>

    <?php if (isset($_GET['import_error'])) : ?>
        <div class="notice notice-error">
            <p>Uploaden mislukt<?php if (!empty($_GET['import_error_message'])) : ?>: <?php echo esc_html(rawurldecode($_GET['import_error_message'])); ?><?php endif; ?></p>
        </div>
    <?php endif; ?>

    <p>Upload een .xlsx-export van de bank (bijv. ING &ldquo;Alle transacties&rdquo;). Al eerder geïmporteerde transacties worden automatisch overgeslagen, dus een overlappende periode nogmaals uploaden is veilig.</p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
        <?php wp_nonce_field('avbk_upload_import'); ?>
        <input type="hidden" name="action" value="avbk_upload_import">
        <table class="form-table">
            <tr>
                <th><label for="bank_export">Bestand</label></th>
                <td><input type="file" id="bank_export" name="bank_export" accept=".xlsx" required></td>
            </tr>
        </table>
        <?php submit_button('Uploaden en verwerken'); ?>
    </form>
</div>
