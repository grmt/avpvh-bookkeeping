<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$open = AVBK_DB::get_disputes('open');
$resolved = AVBK_DB::get_disputes('resolved');
?>
<div class="wrap">
    <h1>Bezwaren</h1>
    <p class="description">Berichten die leden via hun overzicht (&ldquo;Klopt dit niet?&rdquo;) hebben gestuurd — dezelfde melding is ook per e-mail verstuurd, dit is de doorlopende todo-lijst.</p>

    <?php if (isset($_GET['resolved'])) : ?>
        <div class="notice notice-success is-dismissible"><p>Afgehandeld.</p></div>
    <?php endif; ?>

    <h2>Open (<?php echo esc_html(count($open)); ?>)</h2>
    <?php if (!$open) : ?>
        <p>Niets openstaand.</p>
    <?php else : ?>
        <table class="wp-list-table widefat striped">
            <thead><tr><th>Datum</th><th>Lid</th><th>Bericht</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($open as $dispute) :
                $member = AVPVH_DB::get_member((int) $dispute->member_id); ?>
                <tr>
                    <td style="white-space:nowrap"><?php echo esc_html(wp_date('D d M Y H:i', strtotime($dispute->created_at))); ?></td>
                    <td>
                        <?php if ($member) : ?>
                            <a href="<?php echo esc_url(add_query_arg(['page' => 'avbk-members', 'member_id' => $member->id], admin_url('admin.php'))); ?>">
                                <?php echo esc_html(avpvh_format_name($member, 'list')); ?>
                            </a>
                        <?php else : ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td><?php echo nl2br(esc_html($dispute->message)); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('avbk_resolve_dispute'); ?>
                            <input type="hidden" name="action" value="avbk_resolve_dispute">
                            <input type="hidden" name="id" value="<?php echo esc_attr($dispute->id); ?>">
                            <?php submit_button('Afgehandeld', 'secondary', 'submit', false); ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($resolved) : ?>
        <h2>Afgehandeld</h2>
        <table class="wp-list-table widefat striped">
            <thead><tr><th>Datum</th><th>Lid</th><th>Bericht</th><th>Afgehandeld op</th></tr></thead>
            <tbody>
            <?php foreach ($resolved as $dispute) :
                $member = AVPVH_DB::get_member((int) $dispute->member_id); ?>
                <tr>
                    <td style="white-space:nowrap"><?php echo esc_html(wp_date('D d M Y H:i', strtotime($dispute->created_at))); ?></td>
                    <td><?php echo $member ? esc_html(avpvh_format_name($member, 'list')) : '&mdash;'; ?></td>
                    <td><?php echo nl2br(esc_html($dispute->message)); ?></td>
                    <td style="white-space:nowrap"><?php echo $dispute->resolved_at ? esc_html(mysql2date('D d M Y', $dispute->resolved_at)) : '&mdash;'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
