<?php
defined('ABSPATH') || exit;

/** An upload that is not a file PHP received in this request. */
class AVBK_Upload_Exception extends \RuntimeException {}

/**
 * The path of a file PHP received in this request, rebuilt from PHP's own
 * upload directory plus the file's base name. The browser never chooses
 * tmp_name, but this makes sure a path that is not one of this request's
 * uploads, or lies outside that directory, is never opened.
 *
 * @throws AVBK_Upload_Exception when $tmp_name is not an upload of this request.
 */
function avbk_uploaded_file_path(string $tmp_name): string {
    $directory = realpath((string) (ini_get('upload_tmp_dir') ?: sys_get_temp_dir()));
    $real = realpath($tmp_name);
    if ($directory === false || $real === false || !is_uploaded_file($tmp_name)) {
        throw new AVBK_Upload_Exception('Het geüploade bestand is niet gevonden. Kies het bestand opnieuw.');
    }
    $path = $directory . DIRECTORY_SEPARATOR . basename($real);
    if ($path !== $real) {
        throw new AVBK_Upload_Exception('Het geüploade bestand is niet gevonden. Kies het bestand opnieuw.');
    }
    return $path;
}
