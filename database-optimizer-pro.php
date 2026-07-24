<?php
/**
 * Plugin Name: Database Optimizer Pro
 * Description: Schedule and run database cleanups for revisions, drafts, transients and more with size analytics.
 * Version: 1.0.0
 * Author: mrshahbazdev
 * Author URI: https://github.com/mrshahbazdev
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: database-optimizer-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOP_VERSION', '1.0.0' );
define( 'DOP_FILE', __FILE__ );
define( 'DOP_DIR', plugin_dir_path( __FILE__ ) );
define( 'DOP_URL', plugin_dir_url( __FILE__ ) );

require_once DOP_DIR . 'includes/class-database-optimizer-pro.php';
add_action( 'plugins_loaded', array( 'Database_Optimizer_Pro', 'init' ) );
