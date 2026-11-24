<?php
defined('ABSPATH') || exit;

/**
 * "Mail controleren": a mail the penningmeester sends by hand is shown
 * first, with an editable subject and body, and only goes out on
 * "Verstuur". Used for the betaalverzoek from Ledenoverzicht, the
 * cancellation of an order and the order mail sent again from Bestellingen.
 *
 * The draft lives server side, per user, in a transient: what the send
 * does (which order, which fee items) and the mail's own <body> tag come
 * from there, never from the browser. The browser only sends back the
 * subject and the edited body, which is filtered with wp_kses_post().
 * AVBK_Admin::handle_send_composed_mail() carries out the draft.
 */
class AVBK_Mail_Compose implements AVBK_Component {

    public const PAGE = 'avbk-compose-mail';
    public const KINDS = ['payment_request', 'order_cancel', 'order_confirm'];
    private const TTL = HOUR_IN_SECONDS;

    /** Why "Verstuur" was refused, as shown on the page. */
    private const FAILURES = [
        'empty_body'    => 'De mail is leeg. Zet er tekst in, of ga terug.',
        'changed'       => 'De openstaande posten of het tegoed zijn intussen gewijzigd, dus deze mail klopt niet meer. Ga terug en maak het betaalverzoek opnieuw.',
        'invalid_email' => 'Dit lid heeft geen geldig e-mailadres (meer).',
        'no_order'      => 'De bestelling bestaat niet meer.',
        'order_changed' => 'Het e-mailadres van de bestelling is intussen gewijzigd. Ga terug en verwerk de bestelling opnieuw.',
    ];

    public function register(): void {
        add_action('admin_menu', [$this, 'add_page'], 20);
    }

    /** Hidden page: reached only through a draft, so not in the menu. */
    public function add_page(): void {
        add_submenu_page('', 'Mail controleren', 'Mail controleren', 'read', self::PAGE, [$this, 'render_page']);
    }

    /**
     * Stores a draft for the current user.
     *
     * @param array{kind: string, to: string, subject: string, html: string, return_url: string, send_label?: string, note?: string, intro?: string} $draft
     *        html is the full mail (doctype, body tag and all); the rest of
     *        the keys are what the send needs for this kind.
     * @return string The draft key.
     */
    public static function save_draft(array $draft): string {
        [$body_tag, $inner] = self::split_html($draft['html']);
        unset($draft['html']);
        $draft['body_tag'] = $body_tag;
        $draft['body'] = $inner;
        $key = strtolower(wp_generate_password(20, false, false));
        set_transient(self::transient_name($key), $draft, self::TTL);
        return $key;
    }

    public static function load_draft(string $key): ?array {
        if (!preg_match('/^[a-z0-9]{20}$/', $key)) {
            return null;
        }
        $draft = get_transient(self::transient_name($key));
        return is_array($draft) && in_array($draft['kind'] ?? '', self::KINDS, true) ? $draft : null;
    }

    public static function delete_draft(string $key): void {
        if (preg_match('/^[a-z0-9]{20}$/', $key)) {
            delete_transient(self::transient_name($key));
        }
    }

    public static function url(string $key): string {
        return add_query_arg(['page' => self::PAGE, 'draft' => $key], admin_url('admin.php'));
    }

    private static function transient_name(string $key): string {
        return 'avbk_mail_draft_' . get_current_user_id() . '_' . $key;
    }

    /**
     * The mail's opening <body ...> tag and what is inside it. A mail without
     * a body tag gets a plain one, and all of it counts as the inside.
     *
     * @return array{0: string, 1: string}
     */
    public static function split_html(string $html): array {
        if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $m, PREG_OFFSET_CAPTURE)) {
            $tag_end = strpos($html, '>', $m[0][1]) + 1;
            return [substr($html, $m[0][1], $tag_end - $m[0][1]), $m[1][0]];
        }
        return ['<body>', $html];
    }

    /** The full mail again, from the stored body tag and the (edited) inside. */
    public static function join_html(string $body_tag, string $inner): string {
        return '<!doctype html><html>' . $body_tag . $inner . '</body></html>';
    }

    /** The subject and body as posted from the page, cleaned; null for an empty body. @return array{0: string, 1: string}|null */
    public static function posted_mail(array $draft, array $post): ?array {
        $subject = trim(sanitize_text_field(wp_unslash((string) ($post['mail_subject'] ?? ''))));
        $inner = wp_kses_post(wp_unslash((string) ($post['mail_body'] ?? '')));
        if (trim(wp_strip_all_tags($inner)) === '') {
            return null;
        }
        return [$subject !== '' ? $subject : (string) $draft['subject'], self::join_html((string) $draft['body_tag'], $inner)];
    }

    public function render_page(): void {
        if (!current_user_can('manage_options') && !AVPVH_Roles::current_user_has_role('penningmeester')) {
            wp_die(esc_html('Geen toegang.'), 403);
        }
        $key = sanitize_key(wp_unslash($_GET['draft'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows the current user's own draft; sending checks its own nonce.
        $draft = self::load_draft($key);
        if (!$draft) {
            avbk_log_failure('compose mail', 'draft_missing', ['user' => get_current_user_id()]);
            ?><div class="wrap"><h1>Mail controleren</h1>
            <div class="notice notice-error"><p>Deze mail is niet meer beschikbaar (verlopen na een uur, of al verstuurd). Begin opnieuw vanaf de pagina waar je hem maakte.</p></div></div><?php
            return;
        }
        $failed = isset($_GET['send_failed']) ? sanitize_key(wp_unslash($_GET['send_failed'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- picks a fixed message from FAILURES.
        ?>
        <div class="wrap">
            <h1>Mail controleren</h1>
            <?php if ($failed !== '') : ?>
                <div class="notice notice-error"><p><?php echo esc_html(self::FAILURES[$failed] ?? 'Versturen is niet gelukt.'); ?> Er is niets verstuurd of gewijzigd.</p></div>
            <?php endif; ?>
            <?php if (!empty($draft['intro'])) : ?>
                <p><?php echo esc_html($draft['intro']); ?></p>
            <?php endif; ?>
            <?php if (!empty($draft['note'])) : ?>
                <div class="notice notice-info inline"><p><strong>Opmerking bij de bestelling:</strong> <?php echo nl2br(esc_html($draft['note'])); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="avbk-compose-form">
                <?php wp_nonce_field('avbk_send_composed_mail'); ?>
                <input type="hidden" name="action" value="avbk_send_composed_mail">
                <input type="hidden" name="draft" value="<?php echo esc_attr($key); ?>">
                <table class="form-table">
                    <tr><th scope="row">Aan</th><td><?php echo esc_html($draft['to']); ?></td></tr>
                    <tr><th scope="row"><label for="avbk-compose-subject">Onderwerp</label></th>
                        <td><input type="text" class="large-text" id="avbk-compose-subject" name="mail_subject" value="<?php echo esc_attr($draft['subject']); ?>"></td></tr>
                </table>
                <p class="description">Klik in de mail om tekst toe te voegen, te wijzigen of weg te halen. Wat je hier ziet, wordt verstuurd.</p>
                <iframe id="avbk-compose-frame" title="Mail" style="width:100%;max-width:760px;height:560px;border:1px solid #8c8f94;background:#fff;display:block"></iframe>
                <input type="hidden" name="mail_body" id="avbk-compose-body">
                <p class="submit">
                    <?php submit_button($draft['send_label'] ?? 'Verstuur', 'primary', 'submit', false); ?>
                    <a class="button" href="<?php echo esc_url($draft['return_url']); ?>">Terug, niets versturen</a>
                </p>
            </form>
        </div>
        <script>
        (function () {
            var frame = document.getElementById('avbk-compose-frame');
            var body = document.getElementById('avbk-compose-body');
            var html = <?php echo wp_json_encode(self::join_html((string) $draft['body_tag'], (string) $draft['body'])); ?>;
            frame.addEventListener('load', function () {
                var doc = frame.contentDocument;
                doc.body.setAttribute('contenteditable', 'true');
                doc.body.style.outline = 'none';
                doc.body.style.margin = '12px';
            }, { once: true });
            frame.srcdoc = html;
            document.getElementById('avbk-compose-form').addEventListener('submit', function () {
                body.value = frame.contentDocument.body.innerHTML;
            });
        })();
        </script>
        <?php
    }
}
