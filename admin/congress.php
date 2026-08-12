<?php
defined('ABSPATH') || exit;
if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
    wp_die('Geen toegang.');
}

$registrations = AVBK_DB::get_congress_registrations();
?>
<div class="wrap">
    <h1>Congres/Reünie &mdash; aanmeldingen</h1>
    <p class="description">Aanmeldingen via de publieke aanmeldpagina (<code>[avpvh_bk_congress]</code>). Rijen in het rood hebben aandacht nodig: de bevestigingsmail kon niet worden verstuurd, of de naam kon niet eenduidig aan een bestaand lid worden gekoppeld.</p>

    <?php if (!$registrations) : ?>
        <p>Nog geen aanmeldingen.</p>
    <?php else : ?>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Naam</th>
                    <th>E-mail</th>
                    <th>Telefoon</th>
                    <th>Gekoppeld lid</th>
                    <th>Status</th>
                    <th>E-mail verzonden</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($registrations as $reg) :
                $member = $reg->member_id ? AVPVH_DB::get_member((int) $reg->member_id) : null;
                $needs_attention = !empty($reg->needs_review) || empty($reg->email_sent);
                ?>
                <tr<?php echo $needs_attention ? ' style="background:#fbeaea"' : ''; ?>>
                    <td style="white-space:nowrap"><?php echo esc_html(wp_date('D d M Y H:i', strtotime($reg->created_at))); ?></td>
                    <td>
                        <?php echo esc_html(trim("{$reg->first_name} {$reg->suffix} {$reg->last_name}")); ?>
                        <?php if (!empty($reg->review_note)) : ?>
                            <br><span style="color:#b32d2e">&#9888; <?php echo esc_html($reg->review_note); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($reg->email); ?></td>
                    <td><?php echo esc_html($reg->phone); ?></td>
                    <td>
                        <?php if ($member) : ?>
                            <a href="<?php echo esc_url(add_query_arg(['page' => 'avbk-members', 'member_id' => $member->id], admin_url('admin.php'))); ?>">
                                <?php echo esc_html(avpvh_format_name($member, 'list')); ?>
                            </a>
                            <br><span class="description"><?php echo esc_html([
                                'email' => 'via e-mail',
                                'name'  => 'via naam',
                                'new'   => 'nieuw lid aangemaakt',
                            ][$reg->match_type] ?? ''); ?></span>
                        <?php else : ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td><?php echo $reg->status === 'confirmed' ? 'Bevestigd' : 'Wacht op bevestiging'; ?></td>
                    <td><?php echo $reg->email_sent ? 'Ja' : 'Nee' . ($reg->email_error !== '' ? ' (' . esc_html($reg->email_error) . ')' : ''); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
