<?php
/** Exercise the real company handler with the existing synthetic company fixtures. */
require __DIR__ . '/test-companies.php';
function check_admin_referer($action): void {}
function is_user_logged_in(): bool { return get_current_user_id() > 0; }
function wp_unslash($value) { return $value; }
function sanitize_key($value): string { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', $value)); }
function esc_url_raw($url): string { return $url; }
function wp_validate_redirect($url, $fallback): string { return $url ?: $fallback; }
function set_transient($key, $value, $ttl = 0): bool { $GLOBALS['security_notices'][$key] = $value; return true; }
function wp_safe_redirect($url): void { throw new RuntimeException('redirect'); }
$GLOBALS['test_user'] = 12; // Read-only accountant in company 1.
$_POST = ['operation' => 'export', 'company_id' => 1, 'book_year' => 2026, 'quarter' => 3];
try { (new AVBK_Company_Admin())->handle(); }
catch (RuntimeException $e) { if ($e->getMessage() !== 'redirect') throw $e; }
$notice = $GLOBALS['security_notices']['avbk_company_notice_12'] ?? [];
company_check(($notice['error'] ?? false) && ($notice['message'] ?? '') === 'Geen toegang tot deze administratie.', 'Accountant workbook upload is rejected before reading or saving a report');
company_check(AVBK_Companies::can(1, 'export'), 'Accountant retains access to existing workbook downloads');
