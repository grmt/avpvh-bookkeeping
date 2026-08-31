<?php
defined('ABSPATH') || exit;

/** Role-aware shortcuts in the site's first (header) Navigation block. */
class AVBK_Frontend_Admin_Menu {

    private bool $block_menu_injected = false;

    public function __construct() {
        add_filter('render_block_core/navigation', [$this, 'inject_block_navigation'], 20, 2);
    }

    private function menu_tree(): array {
        if (!is_user_logged_in()) return [];

        $is_admin = current_user_can('manage_options');
        $is_treasurer = $is_admin || AVPVH_Roles::current_user_has_role('penningmeester');
        $is_secretary = $is_admin || AVPVH_Roles::current_user_has_role('secretaris');
        $is_chair = $is_admin || AVPVH_Roles::current_user_has_role('voorzitter');
        if (!$is_treasurer && !$is_secretary && !$is_chair) return [];

        $members = [];
        if ($is_secretary) {
            $members[] = ['label' => 'Ledenbeheer', 'url' => admin_url('admin.php?page=avpvh-members')];
            $members[] = ['label' => 'Nieuw lid', 'url' => admin_url('admin.php?page=avpvh-add-member')];
        } else {
            $members[] = ['label' => 'Ledenlijst', 'url' => home_url('/?page_id=1864')];
        }
        // Every officer role implies bestuur; this page is explicitly
        // registered for bestuur by the members plugin.
        $members[] = ['label' => 'Rollen & delegatie', 'url' => admin_url('admin.php?page=avpvh-roles')];
        if ($is_admin) {
            $members[] = ['label' => 'Activiteiten', 'url' => admin_url('admin.php?page=avpvh-activity-participation')];
            $members[] = ['label' => 'Nieuwsbrief', 'url' => admin_url('admin.php?page=avpvh-newsletter')];
            $members[] = ['label' => 'Loginpogingen', 'url' => admin_url('admin.php?page=avpvh-login-attempts')];
            $members[] = ['label' => 'Instellingen', 'url' => admin_url('admin.php?page=avpvh-settings')];
        }

        $sections = [[
            'label' => 'Leden',
            'url' => $members[0]['url'],
            'children' => $members,
        ]];

        if ($is_treasurer) {
            $bookkeeping = [
                ['label' => 'Overzicht', 'url' => admin_url('admin.php?page=avbk-overview')],
                ['label' => 'Bankexport uploaden', 'url' => admin_url('admin.php?page=avbk-import')],
                ['label' => 'Te controleren', 'url' => admin_url('admin.php?page=avbk-review')],
                ['label' => 'Tweede controle', 'url' => admin_url('admin.php?page=avbk-second-approval')],
                ['label' => 'Alle transacties', 'url' => admin_url('admin.php?page=avbk-transactions')],
                ['label' => 'Ledenoverzicht', 'url' => admin_url('admin.php?page=avbk-members')],
                ['label' => 'Tarieven', 'url' => admin_url('admin.php?page=avbk-rates')],
                ['label' => 'Activiteit betalingen', 'url' => admin_url('admin.php?page=avbk-activity-payments')],
                ['label' => 'Bezwaren', 'url' => admin_url('admin.php?page=avbk-disputes')],
                ['label' => 'Declaraties', 'url' => admin_url('admin.php?page=avbk-reimbursements')],
            ];
            $sections[] = [
                'label' => 'Boekhouding',
                'url' => $bookkeeping[0]['url'],
                'children' => $bookkeeping,
            ];
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
        return substr($content, 0, $position) . $this->block_items($tree) . substr($content, $position);
    }

    private function block_items(array $items): string {
        $html = '';
        foreach ($items as $item) {
            $label = esc_html($item['label']);
            $url = esc_url($item['url']);
            if (empty($item['children'])) {
                $html .= '<li class="wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content" href="' . $url . '"><span class="wp-block-navigation-item__label">' . $label . '</span></a></li>';
                continue;
            }
            $html .= '<li data-wp-context="{&quot;submenuOpenedBy&quot;:{&quot;click&quot;:false,&quot;hover&quot;:false,&quot;focus&quot;:false},&quot;type&quot;:&quot;submenu&quot;,&quot;modal&quot;:null,&quot;previousFocus&quot;:null}" data-wp-interactive="core/navigation" data-wp-on--focusout="actions.handleMenuFocusout" data-wp-on--keydown="actions.handleMenuKeydown" data-wp-on--pointerenter="actions.openMenuOnHover" data-wp-on--pointerleave="actions.closeMenuOnHover" data-wp-watch="callbacks.initMenu" tabindex="-1" class="wp-block-navigation-item has-child open-on-hover-click wp-block-navigation-submenu">';
            $html .= '<a class="wp-block-navigation-item__content" href="' . $url . '"><span class="wp-block-navigation-item__label">' . $label . '</span></a>';
            $html .= '<button data-wp-bind--aria-expanded="state.isSubmenuOpen" data-wp-on--click="actions.toggleMenuOnClick" aria-label="' . esc_attr($item['label']) . ' submenu" class="wp-block-navigation__submenu-icon wp-block-navigation-submenu__toggle"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true" focusable="false"><path d="M1.5 4L6 8L10.5 4" stroke-width="1.5"></path></svg></button>';
            $html .= '<ul data-wp-on--focus="actions.openMenuOnFocus" class="wp-block-navigation__submenu-container wp-block-navigation-submenu">' . $this->block_items($item['children']) . '</ul></li>';
        }
        return $html;
    }

}
