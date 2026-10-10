<?php
/** Native WordPress uninstall entry point. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
require_once __DIR__ . '/includes/class-lite-uninstall.php';
EMCP_Lite_Uninstall::run();
