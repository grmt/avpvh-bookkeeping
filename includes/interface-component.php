<?php
defined('ABSPATH') || exit;

/**
 * A part of the plugin that hooks into WordPress. Creating one has no side
 * effects; register() adds its actions, filters and shortcodes, and is
 * called once from avpvh-bookkeeping.php.
 */
interface AVBK_Component {
    public function register(): void;
}
