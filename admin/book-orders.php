<?php
defined('ABSPATH') || exit;
if (!isset($_GET['tab'])) {
    $_GET['tab'] = 'book';
}
require_once __DIR__ . '/orders.php';
