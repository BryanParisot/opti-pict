<?php
/**
 * Plugin Name:       Opti Pict
 * Description:       Analyse la médiathèque, crée des versions WebP plus légères et mesure le gain obtenu.
 * Version:           0.2.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Opti Pict
 * Update URI:        false
 * Text Domain:       opti-pict
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package OptiPict
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTI_PICT_VERSION', '0.2.1' );
define( 'OPTI_PICT_FILE', __FILE__ );
define( 'OPTI_PICT_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPTI_PICT_URL', plugin_dir_url( __FILE__ ) );

require_once OPTI_PICT_DIR . 'includes/class-opti-pict-ai.php';
require_once OPTI_PICT_DIR . 'includes/class-opti-pict.php';

Opti_Pict_AI::instance();
Opti_Pict::instance();
