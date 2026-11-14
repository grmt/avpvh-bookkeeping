<?php
defined('ABSPATH') || exit;

/*
 * The plugin's own exceptions. Each extends \RuntimeException, so every
 * existing catch (\RuntimeException $e) still catches them, and their
 * messages stay the user-facing Dutch text they always were.
 */

/** A company administration action that cannot be done as asked: the message is shown to the user. */
class AVBK_Company_Exception extends \RuntimeException {}

/** A bank export, spreadsheet or sheet import that cannot be read or stored. */
class AVBK_Import_Exception extends \RuntimeException {}

/** A photo upload or Google Photos pick that cannot be processed. */
class AVBK_Photo_Exception extends \RuntimeException {}
