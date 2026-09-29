<?php
/**
 * Opti Pict uninstall routine.
 *
 * Generated images and backup metadata are deliberately retained so uninstalling
 * the plugin can never break media URLs or erase original recovery information.
 *
 * @package OptiPict
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'opti_pict_last_report' );
delete_option( 'opti_pict_openai_api_key' );
