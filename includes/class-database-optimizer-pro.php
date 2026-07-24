<?php
/**
 * Database Optimizer Pro core class.
 *
 * @package Database_Optimizer_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Database_Optimizer_Pro
 */
class Database_Optimizer_Pro {

	const OPTION     = 'database_optimizer_pro_settings';
	const CRON_HOOK  = 'dop_daily_cleanup';

	/**
	 * Initialize.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_cleanup' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );
	}

	/**
	 * Get settings.
     *
     * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'revisions'      => 0,
			'auto_drafts'    => 0,
			'trashed_posts'  => 0,
			'spam_comments'  => 0,
			'trashed_comments' => 0,
			'expired_transients' => 0,
			'orphan_meta'    => 0,
			'schedule'       => 'never',
		);
		$settings = get_option( self::OPTION, array() );
		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Add admin menu.
	 */
	public static function add_menu() {
		add_management_page(
			esc_html__( 'Database Optimizer Pro', 'database-optimizer-pro' ),
			esc_html__( 'DB Optimizer Pro', 'database-optimizer-pro' ),
			'manage_options',
			'database-optimizer-pro',
			array( __CLASS__, 'render_admin' )
		);
	}

	/**
	 * Enqueue assets.
     *
     * @param string $hook Hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'tools_page_database-optimizer-pro' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'dop-admin', DOP_URL . 'assets/css/admin.css', array(), DOP_VERSION );
	}

	/**
	 * Add cron interval.
     *
     * @param array $schedules Schedules.
     * @return array
	 */
	public static function add_cron_interval( $schedules ) {
		$schedules['dop_daily'] = array(
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'Once Daily', 'database-optimizer-pro' ),
		);
		return $schedules;
	}

	/**
	 * Handle admin actions.
	 */
	public static function handle_actions() {
		if ( ! isset( $_POST['dop_action'] ) || ! isset( $_POST['_wpnonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'dop_admin' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['dop_action'] ) );

		if ( 'save_settings' === $action ) {
			$settings = self::get_settings();
			$settings['revisions']      = isset( $_POST['dop_revisions'] ) ? 1 : 0;
			$settings['auto_drafts']    = isset( $_POST['dop_auto_drafts'] ) ? 1 : 0;
			$settings['trashed_posts']  = isset( $_POST['dop_trashed_posts'] ) ? 1 : 0;
			$settings['spam_comments']  = isset( $_POST['dop_spam_comments'] ) ? 1 : 0;
			$settings['trashed_comments'] = isset( $_POST['dop_trashed_comments'] ) ? 1 : 0;
			$settings['expired_transients'] = isset( $_POST['dop_expired_transients'] ) ? 1 : 0;
			$settings['orphan_meta']    = isset( $_POST['dop_orphan_meta'] ) ? 1 : 0;
			$settings['schedule']       = isset( $_POST['dop_schedule'] ) && in_array( sanitize_text_field( wp_unslash( $_POST['dop_schedule'] ) ), array( 'never', 'daily', 'weekly' ), true ) ? sanitize_text_field( wp_unslash( $_POST['dop_schedule'] ) ) : 'never';

			update_option( self::OPTION, $settings );
			self::reschedule( $settings['schedule'] );

			wp_safe_redirect( add_query_arg( 'dop_saved', '1', wp_get_referer() ) );
			exit;
		}

		if ( 'run_cleanup' === $action ) {
			$deleted = self::run_cleanup();
			wp_safe_redirect( add_query_arg( 'dop_cleaned', '1', wp_get_referer() ) );
			exit;
		}
	}

	/**
	 * Reschedule cron.
     *
     * @param string $schedule Schedule.
	 */
	public static function reschedule( $schedule ) {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( 'daily' === $schedule ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		} elseif ( 'weekly' === $schedule ) {
			wp_schedule_event( time(), 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * Run scheduled cleanup.
	 */
	public static function run_scheduled_cleanup() {
		self::run_cleanup();
	}

	/**
	 * Run cleanup based on settings.
     *
     * @return int
	 */
	public static function run_cleanup() {
		global $wpdb;
		$settings = self::get_settings();
		$deleted  = 0;

		if ( $settings['revisions'] ) {
			$deleted += $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		}

		if ( $settings['auto_drafts'] ) {
			$deleted += $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
		}

		if ( $settings['trashed_posts'] ) {
			$deleted += $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'" );
		}

		if ( $settings['spam_comments'] ) {
			$deleted += $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
		}

		if ( $settings['trashed_comments'] ) {
			$deleted += $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'" );
		}

		if ( $settings['expired_transients'] ) {
			$deleted += $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_timeout_%', '_site_transient_timeout_%' ) );
		}

		if ( $settings['orphan_meta'] ) {
			$deleted += $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" );
			$deleted += $wpdb->query( "DELETE cm FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL" );
			$deleted += $wpdb->query( "DELETE um FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE u.ID IS NULL" );
		}

		return $deleted;
	}

	/**
	 * Render admin page.
	 */
	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'database-optimizer-pro' ) );
		}

		$settings = self::get_settings();
		$stats    = self::get_stats();
		?>
		<div class="wrap dop-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( isset( $_GET['dop_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'database-optimizer-pro' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['dop_cleaned'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Cleanup completed.', 'database-optimizer-pro' ); ?></p></div>
			<?php endif; ?>

			<div class="dop-summary">
				<div class="dop-card">
					<span class="dop-label"><?php esc_html_e( 'Database Size', 'database-optimizer-pro' ); ?></span>
					<span class="dop-value"><?php echo esc_html( size_format( $stats['size'] ) ); ?></span>
				</div>
				<div class="dop-card">
					<span class="dop-label"><?php esc_html_e( 'Tables', 'database-optimizer-pro' ); ?></span>
					<span class="dop-value"><?php echo esc_html( number_format_i18n( $stats['tables'] ) ); ?></span>
				</div>
				<div class="dop-card">
					<span class="dop-label"><?php esc_html_e( 'Revisions', 'database-optimizer-pro' ); ?></span>
					<span class="dop-value"><?php echo esc_html( number_format_i18n( $stats['revisions'] ) ); ?></span>
				</div>
				<div class="dop-card">
					<span class="dop-label"><?php esc_html_e( 'Auto Drafts', 'database-optimizer-pro' ); ?></span>
					<span class="dop-value"><?php echo esc_html( number_format_i18n( $stats['auto_drafts'] ) ); ?></span>
				</div>
			</div>

			<div class="dop-grid">
				<div class="dop-card-main">
					<h2><?php esc_html_e( 'Cleanup Settings', 'database-optimizer-pro' ); ?></h2>
					<form method="post">
						<?php wp_nonce_field( 'dop_admin' ); ?>
						<input type="hidden" name="dop_action" value="save_settings">
						<div class="dop-checks">
							<label class="dop-check"><input type="checkbox" name="dop_revisions" value="1" <?php checked( 1, $settings['revisions'] ); ?>> <?php esc_html_e( 'Delete post revisions', 'database-optimizer-pro' ); ?></label>
							<label class="dop-check"><input type="checkbox" name="dop_auto_drafts" value="1" <?php checked( 1, $settings['auto_drafts'] ); ?>> <?php esc_html_e( 'Delete auto-drafts', 'database-optimizer-pro' ); ?></label>
							<label class="dop-check"><input type="checkbox" name="dop_trashed_posts" value="1" <?php checked( 1, $settings['trashed_posts'] ); ?>> <?php esc_html_e( 'Delete trashed posts', 'database-optimizer-pro' ); ?></label>
							<label class="dop-check"><input type="checkbox" name="dop_spam_comments" value="1" <?php checked( 1, $settings['spam_comments'] ); ?>> <?php esc_html_e( 'Delete spam comments', 'database-optimizer-pro' ); ?></label>
							<label class="dop-check"><input type="checkbox" name="dop_trashed_comments" value="1" <?php checked( 1, $settings['trashed_comments'] ); ?>> <?php esc_html_e( 'Delete trashed comments', 'database-optimizer-pro' ); ?></label>
							<label class="dop-check"><input type="checkbox" name="dop_expired_transients" value="1" <?php checked( 1, $settings['expired_transients'] ); ?>> <?php esc_html_e( 'Delete expired transients', 'database-optimizer-pro' ); ?></label>
							<label class="dop-check"><input type="checkbox" name="dop_orphan_meta" value="1" <?php checked( 1, $settings['orphan_meta'] ); ?>> <?php esc_html_e( 'Delete orphan post/comment/user meta', 'database-optimizer-pro' ); ?></label>
						</div>

						<label class="dop-label"><?php esc_html_e( 'Schedule', 'database-optimizer-pro' ); ?></label>
						<select name="dop_schedule">
							<option value="never" <?php selected( 'never', $settings['schedule'] ); ?>><?php esc_html_e( 'Never', 'database-optimizer-pro' ); ?></option>
							<option value="daily" <?php selected( 'daily', $settings['schedule'] ); ?>><?php esc_html_e( 'Daily', 'database-optimizer-pro' ); ?></option>
							<option value="weekly" <?php selected( 'weekly', $settings['schedule'] ); ?>><?php esc_html_e( 'Weekly', 'database-optimizer-pro' ); ?></option>
						</select>

						<?php submit_button( __( 'Save Settings', 'database-optimizer-pro' ) ); ?>
					</form>
				</div>

				<div class="dop-card-main">
					<h2><?php esc_html_e( 'Run Cleanup Now', 'database-optimizer-pro' ); ?></h2>
					<p><?php esc_html_e( 'This will clean the selected items immediately.', 'database-optimizer-pro' ); ?></p>
					<form method="post" onsubmit="return confirm('<?php esc_attr_e( 'Run cleanup now? This cannot be undone.', 'database-optimizer-pro' ); ?>');">
						<?php wp_nonce_field( 'dop_admin' ); ?>
						<input type="hidden" name="dop_action" value="run_cleanup">
						<?php submit_button( __( 'Run Cleanup Now', 'database-optimizer-pro' ), 'primary' ); ?>
					</form>
				</div>
			</div>

			<h2><?php esc_html_e( 'Tables', 'database-optimizer-pro' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Table', 'database-optimizer-pro' ); ?></th>
						<th><?php esc_html_e( 'Rows', 'database-optimizer-pro' ); ?></th>
						<th><?php esc_html_e( 'Size', 'database-optimizer-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $stats['table_list'] as $table ) : ?>
						<tr>
							<td><?php echo esc_html( $table['name'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $table['rows'] ) ); ?></td>
							<td><?php echo esc_html( size_format( $table['size'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Get database stats.
     *
     * @return array
	 */
	public static function get_stats() {
		global $wpdb;

		$tables = $wpdb->get_results( "SHOW TABLE STATUS" );
		$size   = 0;
		$table_list = array();
		foreach ( $tables as $table ) {
			$table_size = (int) $table->Data_length + (int) $table->Index_length;
			$size += $table_size;
			$table_list[] = array(
				'name' => $table->Name,
				'rows' => $table->Rows,
				'size' => $table_size,
			);
		}

		$revisions   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		$auto_drafts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
		$trashed     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" );
		$spam        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );

		return array(
			'size'       => $size,
			'tables'     => count( $tables ),
			'revisions'  => $revisions,
			'auto_drafts' => $auto_drafts,
			'trashed'    => $trashed,
			'spam'       => $spam,
			'table_list' => $table_list,
		);
	}
}
