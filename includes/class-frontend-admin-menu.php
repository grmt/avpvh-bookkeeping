<?php
defined('ABSPATH') || exit;

/** Role-aware shortcuts in the site's first (header) Navigation block. */
class AVBK_Frontend_Admin_Menu {

    private bool $block_menu_injected = false;

    public function __construct() {
        add_filter('render_block_core/navigation', [$this, 'inject_block_navigation'], 20, 2);
        add_action('wp_enqueue_scripts', function () {
            wp_enqueue_style('avbk-frontend-admin-menu', AVBK_PLUGIN_URL . 'assets/frontend-admin-menu.css', [], avbk_asset_version('assets/frontend-admin-menu.css'));
        });
    }

    private function menu_tree(): array {
        if (!is_user_logged_in()) return [];

        $is_admin = current_user_can('manage_options');
        $is_treasurer = $is_admin || AVPVH_Roles::current_user_has_role('penningmeester');
        $is_board = $is_admin || AVPVH_Roles::current_user_has_role('bestuur');

        $can_members = $is_admin || (method_exists('AVPVH_Roles', 'can_manage_members')
            ? AVPVH_Roles::can_manage_members()
            : AVPVH_Roles::current_user_has_role('secretaris'));
        $can_activities = $is_admin || (method_exists('AVPVH_Roles', 'can_manage_activities')
            ? AVPVH_Roles::can_manage_activities()
            : false);
        $can_newsletter = $is_admin || (method_exists('AVPVH_Roles', 'can_send_newsletter')
            ? AVPVH_Roles::can_send_newsletter()
            : false);
        $can_login_attempts = $is_admin || (method_exists('AVPVH_Roles', 'can_view_login_attempts')
            ? AVPVH_Roles::can_view_login_attempts()
            : false);
        $can_dmarc = $is_admin || (method_exists('AVPVH_Roles', 'can_view_dmarc')
            ? AVPVH_Roles::can_view_dmarc()
            : false);
        $can_roles = $is_admin || (method_exists('AVPVH_Roles', 'can_view_roles_page')
            ? AVPVH_Roles::can_view_roles_page()
            : $is_board);
        $can_page_permissions = $is_admin || (method_exists('AVPVH_Roles', 'can_manage_page_permissions')
            ? AVPVH_Roles::can_manage_page_permissions()
            : false);

        if (
            !$is_treasurer && !$is_board && !$can_members && !$can_activities
            && !$can_newsletter && !$can_login_attempts && !$can_dmarc
            && !$can_roles && !$can_page_permissions
        ) {
            return [];
        }

        $members = [];
        if ($can_members) {
            $members[] = ['label' => 'Ledenbeheer', 'url' => admin_url('admin.php?page=avpvh-members')];
            $members[] = ['label' => 'Nieuwe persoon', 'url' => admin_url('admin.php?page=avpvh-add-member')];
        } elseif ($is_board) {
            $members[] = ['label' => 'Ledenlijst', 'url' => home_url('/?page_id=1864')];
        }
        if ($is_admin) {
            $members[] = ['label' => 'Leden samenvoegen', 'url' => admin_url('admin.php?page=avpvh-merge-members')];
        }
        if ($can_activities) {
            $members[] = ['label' => 'Activiteiten', 'url' => admin_url('admin.php?page=avpvh-activity-participation')];
        }
        if ($can_login_attempts) {
            $members[] = ['label' => 'Loginpogingen', 'url' => admin_url('admin.php?page=avpvh-login-attempts')];
        }
        if ($can_dmarc) {
            $members[] = ['label' => 'DMARC-rapporten', 'url' => admin_url('admin.php?page=avpvh-dmarc')];
        }
        if ($can_newsletter) {
            $members[] = ['label' => 'Nieuwsbrief', 'url' => admin_url('admin.php?page=avpvh-newsletter')];
        }
        if ($is_admin) {
            $members[] = ['label' => 'Instellingen', 'url' => admin_url('admin.php?page=avpvh-settings')];
        }
        if ($can_roles) {
            $members[] = ['label' => 'Rollen & delegatie', 'url' => admin_url('admin.php?page=avpvh-roles')];
        }
        if ($can_page_permissions) {
            $members[] = ['label' => 'Paginarechten', 'url' => admin_url('admin.php?page=avpvh-page-permissions')];
        }

        $sections = [];
        if ($members) {
            $sections[] = [
                'label' => 'Leden',
                'url' => $members[0]['url'],
                'children' => $members,
            ];
        }

        if ($is_treasurer) {
            $bookkeeping = [
                ['label' => 'Overzicht', 'url' => admin_url('admin.php?page=avbk-overview')],
                ['label' => 'Bankexport uploaden', 'url' => admin_url('admin.php?page=avbk-import')],
                ['label' => 'Te controleren', 'url' => admin_url('admin.php?page=avbk-review')],
                ['label' => 'Tweede controle', 'url' => admin_url('admin.php?page=avbk-second-approval')],
                ['label' => 'Alle transacties', 'url' => admin_url('admin.php?page=avbk-transactions')],
                ['label' => 'Ledenoverzicht', 'url' => admin_url('admin.php?page=avbk-members')],
                ['label' => 'Tarieven', 'url' => admin_url('admin.php?page=avbk-rates')],
                ['label' => 'IBAN-bankcodes', 'url' => admin_url('admin.php?page=avbk-iban-bank-codes')],
                ['label' => 'Deelname en betalingen', 'url' => admin_url('admin.php?page=avbk-activity-payments')],
                ['label' => 'Bestellingen', 'url' => admin_url('admin.php?page=avbk-orders')],
                ['label' => 'Bezwaren', 'url' => admin_url('admin.php?page=avbk-disputes')],
                ['label' => 'Declaraties', 'url' => admin_url('admin.php?page=avbk-reimbursements')],
                ['label' => 'QR-flyers', 'url' => admin_url('admin.php?page=avbk-flyers')],
            ];
            $sections[] = [
                'label' => 'Boekhouding',
                'url' => $bookkeeping[0]['url'],
                'children' => $bookkeeping,
            ];
        }

        if (!$sections) {
            return [];
        }

        return [[
            'label' => 'Administratie',
            'url' => $sections[0]['url'],
            'children' => $sections,
        ]];
    }

    public function inject_block_navigation(string $content, array $block): string {
        if (is_admin() || $this->block_menu_injected) return $content;
        $tree = $this->menu_tree();
        if (!$tree) return $content;
        $position = strrpos($content, '</ul>');
        if ($position === false) return $content;
        $this->block_menu_injected = true;
        return substr($content, 0, $position) . $this->block_items($tree, true) . substr($content, $position);
    }

    private function block_items(array $items, bool $is_top_level = false): string {
        $html = '';
        foreach ($items as $item) {
            $label = esc_html($item['label']);
            $url = esc_url($item['url']);
            if (empty($item['children'])) {
                $html .= '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="' . $url . '"><span class="wp-block-navigation-item__label">' . $label . '</span></a></li>';
                continue;
            }
            // avbk-frontend-admin-menu marks only the injected top-level
            // item, scoping frontend-admin-menu.css's leftward-flyout
            // fix to this menu without touching the theme's own nav items.
            $top_level_class = $is_top_level ? ' avbk-frontend-admin-menu' : '';
            $html .= '<li data-wp-context="{&quot;submenuOpenedBy&quot;:{&quot;click&quot;:false,&quot;hover&quot;:false,&quot;focus&quot;:false},&quot;type&quot;:&quot;submenu&quot;,&quot;modal&quot;:null,&quot;previousFocus&quot;:null}" data-wp-interactive="core/navigation" data-wp-on--focusout="actions.handleMenuFocusout" data-wp-on--keydown="actions.handleMenuKeydown" data-wp-on--pointerenter="actions.openMenuOnHover" data-wp-on--pointerleave="actions.closeMenuOnHover" data-wp-watch="callbacks.initMenu" tabindex="-1" class="wp-block-navigation-item has-child open-on-hover-click wp-block-navigation-submenu' . $top_level_class . '">';
            $html .= '<a class="wp-block-navigation-item__content" href="' . $url . '"><span class="wp-block-navigation-item__label">' . $label . '</span></a>';
            $html .= '<button data-wp-bind--aria-expanded="state.isSubmenuOpen" data-wp-on--click="actions.toggleMenuOnClick" aria-label="' . esc_attr($item['label']) . ' submenu" class="wp-block-navigation__submenu-icon wp-block-navigation-submenu__toggle"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true" focusable="false"><path d="M1.5 4L6 8L10.5 4" stroke-width="1.5"></path></svg></button>';
            $html .= '<ul data-wp-on--focus="actions.openMenuOnFocus" class="wp-block-navigation__submenu-container wp-block-navigation-submenu">' . $this->block_items($item['children']) . '</ul></li>';
        }
        return $html;
    }

}
