<?php

class SQLog {
    private $rewrite_rules;
	public static $sqlog_dirname       = 'sqlog';
	public static $csv_separator       = '|';
	public static $per_page            = 20;
	public static $purge_interval_base = 60; // 60 seconds

    /*
     * construct
     */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'sqlog_plugin_version' ) );
		add_action( 'admin_init', array( $this, 'sqlog_process_actions' ) );
		add_action( 'admin_init', array( $this, 'sqlog_settings_init' ) );

		add_action( 'init', array( $this, 'sqlog_add_rewrite_rules' ) );
		add_action( 'init', array( $this, 'schedule_crons' ) );

		add_action( 'admin_menu', array( $this, 'sqlog_settings_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'sqlog_enqueue_scripts_base' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'sqlog_admin_enqueue_scripts' ) );

		add_action( 'wp', array( $this, 'sqlog_rewrite_process' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'sqlog_enqueue_scripts_base' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'sqlog_index_enqueue_scripts' ) );
		add_action( 'wp_head', array( $this, 'sqlog_add_scripts' ) );

		add_action( 'add_option_sqlog_settings_update', array( $this, 'sqlog_settings_update' ), 9999 );
		add_action( 'update_option_sqlog_settings_update', array( $this, 'sqlog_settings_update' ), 9999 );
		add_action( 'sqlog_cron_process_purge_logs', array( $this, 'sqlog_process_purge_logs' ) );
		add_action( 'shutdown', array( $this, 'sqlog_logger' ), 9999 );

		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );

		add_filter( 'query_vars', array( $this, 'sqlog_add_vars' ) );

		add_filter( 'plugin_action_links_sqlog/sqlog.php', array( $this, 'sqlog_settings_link' ) );

		register_activation_hook( SQLOG_PLUGIN_FILE, array( $this, 'sqlog_install' ) );
		register_deactivation_hook( SQLOG_PLUGIN_FILE, array( $this, 'sqlog_uninstall' ) );

		$this->rewrite_rules = array(
			array(
				'regex' => '^' . SQLOG_SLUG . '/?$',
				'query' => 'index.php?sqlog_rewrite=sqlog',
				'after' => 'top',
			),
		);
	}

	public static function run() {
		new self();
	}

    /*
     * install
     */
	public function sqlog_install() {
		update_option( 'sqlog_install', true );
		update_option( 'sqlog_install_date', date_i18n( 'Y-m-d H:i:s' ) );
		update_option( 'sqlog_uninstall_date', null );

		if ( isset( $this->rewrite_rules ) && is_array( $this->rewrite_rules ) && count( $this->rewrite_rules ) > 0 ) {
			foreach ( $this->rewrite_rules as $rewrite_rule ) {
				add_rewrite_rule( $rewrite_rule['regex'], $rewrite_rule['query'], $rewrite_rule['after'] );
			}
		}
		flush_rewrite_rules();

		self::sqlog_install_mu_files();

		self::sqlog_add_htaccess_rules();

		self::get_sqlog_path();

		update_option( 'sqlog_enabled', false );
		update_option( 'sqlog_purge_interval', self::$purge_interval_base * 60 );
	}


	public function sqlog_uninstall() {
		update_option( 'sqlog_install', false );
		update_option( 'sqlog_install_date', null );
		update_option( 'sqlog_uninstall_date', date_i18n( 'Y-m-d H:i:s' ) );

		self::ni_owa_cas_uninstall_mu_files();

		self::sqlog_remove_htaccess_rules();

		update_option( 'sqlog_enabled', false );
	}

    /*
     * Check version and process what needed
     */
	public function sqlog_plugin_version() {
		// Change each time the plugin is up :
		$sqlog_plugin_version_current = get_option( 'sqlog_plugin_version' );
		if (
			false === $sqlog_plugin_version_current ||
			( ! empty( $sqlog_plugin_version_current ) && version_compare( $sqlog_plugin_version_current, SQLOG_PLUGIN_VERSION, '<' ) )
		) {
			update_option( 'sqlog_plugin_version', SQLOG_PLUGIN_VERSION );

			self::sqlog_install_mu_files();

			self::sqlog_add_htaccess_rules();
		}
	}

    /*
     * Install MU File
     */
	public static function sqlog_install_mu_files() {
		$filesystem = self::get_filesystem();

		$filename = WPMU_PLUGIN_DIR . '/sqlog.php';

		if ( $filesystem->exists( $filename ) ) { // if exists return
			return;
		}

		$mu_plugin_path = SQLOG_PATH . '/mu-plugin-template/sqlog.php';
		$contents       = $filesystem->get_contents( $mu_plugin_path );  // get mu-plugin file content

		if ( ! $filesystem->exists( WPMU_PLUGIN_DIR ) ) {  // if not exists create mu-plugins dir
			$filesystem->mkdir( WPMU_PLUGIN_DIR );
		}

		if ( ! $filesystem->exists( WPMU_PLUGIN_DIR ) ) {  // if still not exists, give up
			return;
		}

		$filesystem->put_contents( $filename, $contents );   // it's good, copy that
	}

	/*
     * Remove MU file
     */
	public function ni_owa_cas_uninstall_mu_files() {
		$filesystem = self::get_filesystem();

		// Remove worker
		$filename = WPMU_PLUGIN_DIR . '/sqlog.php';
		if ( $filesystem->exists( $filename ) ) {
			$filesystem->delete( $filename );
		}
	}

	/*
     * Add htaccess rules
     */
	public function sqlog_add_htaccess_rules() {
		global $wp_rewrite;

		// Ensure get_home_path() is declared.
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$home_path     = get_home_path();
		$htaccess_file = $home_path . '.htaccess';
		$new_rules     = array();

		/*
		 * If the file doesn't already exist check for write access to the directory
		 * and whether we have some rules. Else check for write access to the file.
		 */
		if ( ( ! file_exists( $htaccess_file ) && is_writable( $home_path ) && $wp_rewrite->using_mod_rewrite_permalinks() ) || is_writable( $htaccess_file ) ) :
			if ( got_mod_rewrite() ) :
				$filesystem = self::get_filesystem();

				$contents = $filesystem->get_contents( $htaccess_file );

				if ( ! preg_match( '/# BEGIN ' . SQLOG_SLUG_CAMELCASE . '/', $contents, $matches ) ) :
					$new_rules[] = '# BEGIN ' . SQLOG_SLUG_CAMELCASE;
					$new_rules[] = '<IfModule mod_rewrite.c>';
					$new_rules[] = 'RewriteEngine On';
					$new_rules[] = 'RewriteBase /';
					$new_rules[] = 'RewriteRule ^(' . SQLOG_SLUG . '-htaccess-test) /index.php?sqlog_rewrite=process&sqlog_target=$1';
					$new_rules[] = '</IfModule>';
					$new_rules[] = '# END ' . SQLOG_SLUG_CAMELCASE;
					$new_rules[] = ''; // EOL
					$new_rules[] = ''; // One more line

					$contents = implode( "\r\n", $new_rules ) . $contents;
					$filesystem->put_contents( $htaccess_file, $contents );
				endif;
			endif;
		endif;
	}

	/*
     * Remove htaccess rules
     */
	public function sqlog_remove_htaccess_rules() {
		global $wp_rewrite;

		$home_path     = get_home_path();
		$htaccess_file = $home_path . '.htaccess';

		$filesystem = self::get_filesystem();

		if ( $filesystem->exists( $htaccess_file ) ) :
			$contents = $filesystem->get_contents( $htaccess_file );
			$contents = preg_replace( '/\# BEGIN ' . SQLOG_SLUG_CAMELCASE . '(.)+\# END ' . SQLOG_SLUG_CAMELCASE . '[\r\n]{2}/is', '', $contents );
			$filesystem->put_contents( $htaccess_file, $contents );
		endif;
	}

	/**
	 * Add cron intervals
	 *
	 * @param $schedules
	 *
	 * @return mixed
	 */
	public function add_cron_intervals( $schedules ) {
		if ( ! isset( $schedules['1min'] ) ) {
			$schedules['1min'] = array(
				'interval' => 1 * 60,
				'display'  => __( '1 minute', 'sqlog' ),
			);
		}
		if ( ! isset( $schedules['2min'] ) ) {
			$schedules['2min'] = array(
				'interval' => 2 * 60,
				'display'  => __( '2 minutes', 'sqlog' ),
			);
		}
		if ( ! isset( $schedules['3min'] ) ) {
			$schedules['3min'] = array(
				'interval' => 3 * 60,
				'display'  => __( '3 minutes', 'sqlog' ),
			);
		}
		if ( ! isset( $schedules['4min'] ) ) {
			$schedules['4min'] = array(
				'interval' => 4 * 60,
				'display'  => __( '4 minutes', 'sqlog' ),
			);
		}
		if ( ! isset( $schedules['5min'] ) ) {
			$schedules['5min'] = array(
				'interval' => 5 * 60,
				'display'  => __( '5 minutes', 'sqlog' ),
			);
		}
		if ( ! isset( $schedules['10min'] ) ) {
			$schedules['10min'] = array(
				'interval' => 10 * 60,
				'display'  => __( '10 minutes', 'sqlog' ),
			);
		}
		if ( ! isset( $schedules['30min'] ) ) {
			$schedules['30min'] = array(
				'interval' => 30 * 60,
				'display'  => __( '30 minutes', 'sqlog' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule all crons
	 */
	public function schedule_crons() {
		if ( ! wp_next_scheduled( 'sqlog_cron_process_purge_logs' ) ) {
			wp_schedule_event( time(), '30min', 'sqlog_cron_process_purge_logs' );
		}
	}

	/**
	 * Process purge logs
	 */
	public function sqlog_process_purge_logs() {
		$wpfs = self::get_filesystem();
		if ( ! $wpfs ) {
			error_log( __( 'File system missing.', 'sqlog' ) );
			return false;
		}

		if ( 'disabled' === get_option( 'sqlog_purge_interval' ) ) {
			error_log( __( 'Purge logs disabled.', 'sqlog' ) );
			return false;
		}

		ob_start();
		echo 'START PURGE FILES' . "\n";
		$log_files = self::get_log_files( 0, self::get_nb_log_files() );
		if ( ! $log_files ) {
			error_log( ob_get_clean() );
			error_log( __( 'No sqlog files to purge.', 'sqlog' ) );
			return false;
		}

		$sqlog_purge_interval = get_option( 'sqlog_purge_interval' );
		if ( ! $sqlog_purge_interval ) {
			error_log( ob_get_clean() );
			error_log( __( 'No purge interval set.', 'sqlog' ) );

		}

		foreach ( $log_files as $log_file ) {
			$name        = $log_file['name'];
			$size        = $log_file['size'];
			$lastmodunix = $log_file['lastmodunix'];

			echo wp_timezone_string() . "\n";

			$today = new DateTime();
			$today->setTimezone( new DateTimeZone( wp_timezone_string() ) );
			esc_html_e( '$today : ' . $today->format( 'd/m/Y H:i:s' ) . "\n" );

			$log_date = new DateTime();
			$log_date->setTimezone( new DateTimeZone( wp_timezone_string() ) );
			$log_date->setTimestamp( $lastmodunix );
			esc_html_e( '$log_date : ' . $log_date->format( 'd/m/Y H:i:s' ) . "\n" );

			$log_date = new DateTime();
			$log_date->setTimezone( new DateTimeZone( wp_timezone_string() ) );
			$log_date->setTimestamp( $lastmodunix + $sqlog_purge_interval );
			esc_html_e( '$log_date + purge interval : ' . $log_date->format( 'd/m/Y H:i:s' ) . "\n" );

			if ( $today->format( 'Y-m-d H:i:s' ) <= $log_date->format( 'Y-m-d H:i:s' ) ) {
				echo __( 'DO NOT DELETE YET!', 'sqlog' ) . "\n";
				echo '--------------------------------------------------' . "\n";
				continue;
			}

			$file_path = self::get_sqlog_file_path( $name );

			// Delete log file without check
			$file_path_log = preg_replace( '/.csv$/', '.log', $file_path );
			$wpfs->delete( $file_path_log );

			if ( ! $wpfs->exists( $file_path ) ) {
				echo sprintf( __( '%s does not exist.', 'sqlog' ), esc_url( $file_path ) ) . "\n";
				echo '--------------------------------------------------' . "\n";
				continue;
			}

			if ( ! $wpfs->delete( $file_path ) ) {
				echo sprintf( __( 'Error while deleting %.', 'sqlog' ), esc_url( $file_path ) ) . "\n";
				echo '--------------------------------------------------' . "\n";
				continue;
			}

			echo sprintf( __( '%s deleted with success!', 'sqlog' ), esc_url( $file_path ) ) . "\n";
			echo '--------------------------------------------------' . "\n";

		}
		error_log( ob_get_clean() );
	}

	/*
     * Add query vars
     */
	public function sqlog_add_vars( $vars ) {
		$vars[] = 'sqlog_rewrite';
		$vars[] = 'sqlog_target';
		//
		return $vars;
	}

    /*
     * Maintain rewrite rules
     */
	public function sqlog_add_rewrite_rules() {
		if ( isset( $this->rewrite_rules ) && is_array( $this->rewrite_rules ) && count( $this->rewrite_rules ) > 0 ) {
			foreach ( $this->rewrite_rules as $rewrite_rule ) {
				add_rewrite_rule( $rewrite_rule['regex'], $rewrite_rule['query'], $rewrite_rule['after'] );
			}
		}
	}

    /*
     * Display rewrite content needed
     */
	public function sqlog_rewrite_process() {
		$sqlog_rewrite = get_query_var( 'sqlog_rewrite' );

		http_response_code( 200 );

		if ( ! empty( $sqlog_rewrite ) && 'sqlog' === $sqlog_rewrite ) {
			require SQLOG_PATH . '/rewrites/sqlog.php';
			exit;
		}

		if ( ! empty( $sqlog_rewrite ) && 'process' === $sqlog_rewrite ) {
			require SQLOG_PATH . '/rewrites/sqlog-process.php';
			exit;
		}
	}

	/*
     * enqueue scripts
     */
	public function sqlog_enqueue_scripts_base() {
		wp_enqueue_style( SQLOG_SLUG . '-front', SQLOG_PLUGIN_URL . 'assets/css/front.css', array(), SQLOG_PLUGIN_VERSION, 'all' );
	}

	public function sqlog_admin_enqueue_scripts() {
		wp_enqueue_style( SQLOG_SLUG . '-admin', SQLOG_PLUGIN_URL . 'assets/css/admin.css', array(), SQLOG_PLUGIN_VERSION, 'all' );

		if ( ! did_action( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		wp_enqueue_script( SQLOG_SLUG . '-admin', SQLOG_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), SQLOG_PLUGIN_VERSION, false );

		$this->sqlog_enqueue_scripts_extras( SQLOG_SLUG . '-admin' );
	}

	public function sqlog_index_enqueue_scripts() {
		wp_enqueue_script( SQLOG_SLUG . '-front', SQLOG_PLUGIN_URL . 'assets/js/front.js', array( 'jquery' ), SQLOG_PLUGIN_VERSION, false );

		$this->sqlog_enqueue_scripts_extras( SQLOG_SLUG . '-front' );
	}

	public function sqlog_enqueue_scripts_extras( $handle ) {
		$sqlog_extras = array(
			'error_undefined' => __( 'Sorry, an error occured.', 'sqlog' ),
			'processing'      => __( 'Processing...', 'sqlog' ),
			'check'           => __( 'Check', 'sqlog' ),
			'finish'          => __( 'Finish', 'sqlog' ),
			'ajaxurl'         => admin_url( 'admin-ajax.php' ),
		);
		wp_localize_script( $handle, 'sqlog', $sqlog_extras );
	}

    /*
     * add scripts in footer
     */
	public function sqlog_add_scripts() {
		$current_lang = current( explode( '_', get_locale() ) );
		?>
        <script>
			var sqlog_current_lang = '<?php echo $current_lang; ?>';
        </script>
		<?php
	}

    /**
     * Add settings link
     */
	public function sqlog_settings_link( $links ) {
		$settings_link = '<a href="' . admin_url( 'admin.php?page=sqlog-page' ) . '">' . __( 'Settings', 'sqlog' ) . '</a>';

		array_unshift( $links, $settings_link );

		return $links;
	}

    /*
     * Admin : add settings page
     */
	public function sqlog_settings_page() {
		add_menu_page(
            __( 'SQLog - Dashboard', 'sqlog' ),             // Page title
            __( 'SQLog', 'sqlog' ),                         // Menu title
            'manage_options',                               // Capability
            'sqlog-page',                                   // Slug of setting page
            array( $this, 'sqlog_settings_page_content' ),  // Call Back function for rendering
            'dashicons-database',                           // icon URL
            // position
		);
	}

	public function sqlog_settings_init() {
		add_settings_section(
            'sqlog-settings-section',           // id of the section
            __( 'SQLog - Dashboard', 'sqlog' ), // title to be displayed
            '',                                 // callback function to be called when opening section
            'sqlog-page'                        // page on which to display the section, this should be the same as the slug used in add_submenu_page()
		);
		//
		register_setting(
            'sqlog-page',
            'sqlog_settings_update'
		);
		//
        register_setting(
            'sqlog-page',
            'sqlog_enabled'
        );
		//
        register_setting(
            'sqlog-page',
            'sqlog_purge_interval'
        );
        //
        $sqlog_enabled = get_option( 'sqlog_enabled' );
        add_settings_field(
            'sqlog_enabled',                        // id of the settings field
            __( 'SQLog', 'yole' ),                  // title
            array( $this, 'sqlog_render_field' ),   // callback function
            'sqlog-page',                           // page on which settings display
            'sqlog-settings-section',               // section on which to show settings
            array(
                'type'    => 'select',
                'id'      => 'sqlog_enabled',
                'name'    => 'sqlog_enabled',
                'value'   => $sqlog_enabled,
				'options' => array(
					0 => __( 'Disabled', 'sqlog' ),
					1 => __( 'Enabled', 'sqlog' ),
				),
            )
        );
        //
        $sqlog_purge_interval = get_option( 'sqlog_purge_interval' );
        add_settings_field(
            'sqlog_purge_interval',                 // id of the settings field
            __( 'Purge interval', 'sqlog' ),        // title
            array( $this, 'sqlog_render_field' ),   // callback function
            'sqlog-page',                           // page on which settings display
            'sqlog-settings-section',               // section on which to show settings
            array(
                'type'    => 'select',
                'id'      => 'sqlog_purge_interval',
                'name'    => 'sqlog_purge_interval',
                'value'   => $sqlog_purge_interval,
				'options' => array(
					'disabled'                           => __( 'Disabled', 'sqlog' ),
					self::$purge_interval_base * 60      => __( '1 hour', 'sqlog' ),
					self::$purge_interval_base * 60 * 6  => __( '6 hours', 'sqlog' ),
					self::$purge_interval_base * 60 * 12 => __( '12 hours', 'sqlog' ),
					self::$purge_interval_base * 60 * 24 => __( '1 day', 'sqlog' ),
					self::$purge_interval_base * 60 * 24 * 7 => __( '1 week', 'sqlog' ),
					self::$purge_interval_base * 60 * 24 * 30 => __( '30 days', 'sqlog' ),
				),
            )
        );
	}

    public function sqlog_render_field( $args ) {
        $value = ( isset( $args['value'] ) && ! empty( $args['value'] ) ) ? esc_attr( $args['value'] ) : '';
        switch ( $args['type'] ) {
            case 'select':
				?>
				<select id="<?php echo esc_attr( $args['id'] ); ?>" name="<?php echo esc_attr( $args['id'] ); ?>">
				<?php if ( isset( $args['options'] ) && is_array( $args['options'] ) && count( $args['options'] ) > 0 ) : ?>
					<?php foreach ( $args['options'] as $key => $label ) : ?>
						<option
							value="<?php echo esc_attr( $key ); ?>"
							<?php if ( $key == $value ) : ?>
								selected="selected"
							<?php endif; ?>>
							<?php echo esc_attr( $label ); ?>
						</option>
					<?php endforeach; ?>
				<?php endif; ?>
				</select>
				<?php
                break;
            default:
				?>
                <input
					id="<?php echo esc_attr( $args['id'] ); ?>"
					type="text"
					name="<?php echo esc_attr( $args['name'] ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
					class="regular-text" />
				<?php
                break;
        }
    }

	public function sqlog_settings_page_content() {
		// check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
        <div class="wrap">
			<?php settings_errors(); ?>
            <form method="post" action="options.php">
				<?php wp_nonce_field( 'sqlog-settings', 'sqlog_settings' ); ?>
				<?php settings_fields( 'sqlog-page' ); ?>
                <input type="hidden" id="_wp_http_referer" name="_wp_http_referer" value="<?php echo esc_attr_e( add_query_arg( 'page', 'sqlog-page', admin_url( 'admin.php' ) ) ); ?>" />
				<?php do_settings_sections( 'sqlog-page' ); ?>
                <input type="hidden" id="sqlog_settings_update" name="sqlog_settings_update" value="<?php echo date_i18n( 'Y-m-d H:i:s' ); ?>" />
				<?php submit_button(); ?>
			</form>
			<form method="get" id="fsqlog" name="fsqlog">
				<input type="hidden" name="page" value="sqlog-page" />
				<?php wp_nonce_field( 'sqlog-delete-files', 'sqlog-delete-files' ); ?>
				<div class="sqlog-files-content">
					<?php echo $this->display_files_list(); ?>
				</div>
			</form>
        </div>
		<?php
	}

	/*
	* List files
	*/
	public function display_files_list() {
		$url_page       = add_query_arg( 'page', 'sqlog-page', admin_url( 'admin.php' ) );
		$nb_items       = self::get_nb_log_files();
		$nb_pages       = ceil( $nb_items / self::$per_page );
		$paged          = filter_input( INPUT_GET, 'paged', FILTER_SANITIZE_NUMBER_INT );
		$current_page   = ( $paged ) ? $paged : 1;
		$start          = ( $current_page - 1 ) * self::$per_page;
		$first_url_page = $url_page;
		$prev_url_page  = add_query_arg( 'paged', ( $current_page - 1 ), $url_page );
		$next_url_page  = add_query_arg( 'paged', ( $current_page + 1 ), $url_page );
		$last_url_page  = add_query_arg( 'paged', $nb_pages, $url_page );
		?>
		<h2><?php esc_html_e( 'Logs list', 'sqlog' ); ?></h2>
		<?php
		$log_files = $this->get_log_files( $start, self::$per_page );
		if ( is_bool( $log_files ) && ! $log_files ) {
			echo '<p>' . esc_html_e( 'Log path is invalid.', 'sqlog' ) . '</p>';
			return;
		}
		if ( is_array( $log_files ) && 0 === count( $log_files ) ) {
			echo '<p>' . esc_html_e( 'No files available.', 'sqlog' ) . '</p>';
			return;
		}
		?>
<div class="tablenav top">
	<div class="alignleft actions bulkactions">
		<label for="bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e( __( 'Bulk actions', 'sqlog' ) ); ?></label>
		<select name="action" id="bulk-action-selector-top">
			<option value="-1"><?php esc_attr_e( __( 'Bulk actions', 'sqlog' ) ); ?></option>
			<option value="delete"><?php esc_html_e( __( 'Delete', 'sqlog' ) ); ?></option>
		</select>
		<input type="submit" id="doaction" class="button action" value="<?php esc_html_e( __( 'Apply', 'sqlog' ) ); ?>" />
	</div>

	<div class="tablenav-pages">
		<span class="displaying-num"><?php echo sprintf( _n( '%s item', '%s items', self::get_nb_log_files(), 'sqlog' ), number_format_i18n( $nb_items ) ); ?></span>
		<span class="pagination-links">
			<?php if ( 1 == $current_page ) : ?>
				<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>
				<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>
			<?php else : ?>
				<a class="first-page button" href="<?php echo esc_url( $url_page ); ?>">
					<span class="screen-reader-text"><?php esc_html_e( __( 'First page', 'sqlog' ) ); ?></span>
					<span aria-hidden="true">«</span>
				</a>
				<a class="prev-page button" href="<?php esc_attr_e( $prev_url_page ); ?>">
					<span class="screen-reader-text"><?php esc_html_e( __( 'Previous page', 'sqlog' ) ); ?></span>
					<span aria-hidden="true">‹</span>
				</a>
			<?php endif; ?>
			
			<span class="paging-input">
				<label for="current-page-selector" class="screen-reader-text"><?php esc_html_e( __( 'Current page', 'sqlog' ) ); ?></label>
				<input class="current-page" id="current-page-selector" type="text" name="paged" value="<?php esc_attr_e( $current_page ); ?>" size="1" aria-describedby="table-paging">
				<span class="tablenav-paging-text"> sur <span class="total-pages"><?php esc_html_e( $nb_pages ); ?></span></span>
			</span>

			<?php if ( $nb_pages == $current_page ) : ?>
				<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>
				<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>
			<?php else : ?>
				<a class="next-page button" href="<?php esc_attr_e( $next_url_page ); ?>">
					<span class="screen-reader-text"><?php esc_html_e( __( 'Next page', 'sqlog' ) ); ?></span>
					<span aria-hidden="true">›</span>
				</a>
				<a class="last-page button" href="<?php esc_attr_e( $last_url_page ); ?>">
					<span class="screen-reader-text"><?php esc_html_e( __( 'Last page', 'sqlog' ) ); ?></span>
					<span aria-hidden="true">»</span>
				</a>
			<?php endif; ?>
		</span>
	</div>
	<br class="clear">
</div>

<table class="wp-list-table widefat fixed striped sqlog">
	<thead>
		<tr>
			<td id="cb" class="manage-column column-cb check-column">
				<label class="screen-reader-text" for="cb-select-all-1"><?php esc_html_e( 'Select all', 'sqlog' ); ?></label>
				<input id="cb-select-all-1" type="checkbox" />
			</td>
			<th scope="col" id="sqlog-name" class="manage-column column-name column-primary">
				<span><?php esc_html_e( 'Filename', 'sqlog' ); ?></span>
			</th>
			<th scope="col" id="sqlog-size" class="manage-column column-size">
				<span><?php esc_html_e( 'Size', 'sqlog' ); ?></span>
			</th>
			<th scope="col" id="sqlog-date" class="manage-column column-date">
				<span><?php esc_html_e( 'Date', 'sqlog' ); ?></span>
			</th>
		</tr>
	</thead>
	<tbody id="logs-list">
		<?php foreach ( $log_files as $index => $log_file ) : ?>
			<?php
				$name        = $log_file['name'];
				$size        = $log_file['size'];
				$lastmodunix = $log_file['lastmodunix'];
			?>
			<tr id="sqlog-<?php echo $index; ?>" class="author-self level-0 sqlog-<?php echo $index; ?> type-sqlog">
				<th scope="row" class="check-column">
					<label class="screen-reader-text" for="cb-select-<?php echo $index; ?>"><?php echo sprintf( __( 'Select %s', 'sqlog' ), esc_html( $name ) ); ?></label>
					<input id="cb-select-<?php echo $index; ?>" type="checkbox" name="sqlog_name[]" value="<?php esc_attr_e( $name ); ?>">
				</th>
				<td class="title column-name has-row-actions column-primary page-name" data-colname="<?php esc_attr_e( 'Filename', 'sqlog' ); ?>">
					<strong>
						<a class="row-name" href="<?php echo self::get_sqlog_file_url( $name ); ?>" aria-label="<?php esc_attr_e( $name ); ?>"><?php esc_html_e( $name ); ?></a>
					</strong>

					<div class="row-actions">
						<span class="download"><a href="<?php echo self::get_sqlog_file_url( $name ); ?>" aria-label="<?php esc_html_e( 'Download', 'sqlog' ); ?>"><?php esc_html_e( 'Download', 'sqlog' ); ?></a> | </span>
						<span class="view-log"><a href="<?php echo self::get_sqlog_file_log_url( $name ); ?>" aria-label="<?php esc_html_e( 'View log version', 'sqlog' ); ?>" target="_blank"><?php esc_html_e( 'View log version', 'sqlog' ); ?></a> | </span>
						<span class="trash"><a href="<?php echo self::get_sqlog_file_delete_url( $name ); ?>" class="submitdelete" aria-label="<?php esc_html_e( 'Delete', 'sqlog' ); ?>"><?php esc_html_e( 'Delete', 'sqlog' ); ?></a></span>
					</div>
				</td>
				<td class="date column-size" data-colname="<?php esc_attr_e( 'Size', 'sqlog' ); ?>">
					<?php echo $filesize = size_format( $size ); ?>
				</td>
				<td class="date column-date" data-colname="<?php esc_attr_e( 'Date', 'sqlog' ); ?>">
				<?php
					$log_date = new DateTime();
					$log_date->setTimezone( new DateTimeZone( wp_timezone_string() ) );
					$log_date->setTimestamp( $lastmodunix );
				?>
				<abbr title="<?php esc_attr_e( $log_date->format( 'Y/m/d H:i:s' ) ); ?>"><?php esc_attr_e( $log_date->format( 'd/m/Y H:i:s' ) ); ?></abbr>
				</td>
			</tr>			
		<?php endforeach; ?>
	</tbody>
	<tfoot>
		<tr>
			<td class="manage-column column-cb check-column">
				<label class="screen-reader-text" for="cb-select-all-2"><?php esc_html_e( 'Select All', 'sqlog' ); ?></label>
				<input id="cb-select-all-2" type="checkbox" />
			</td>
			<th scope="col" class="manage-column column-name column-primary">
				<span><?php esc_html_e( 'Filename', 'sqlog' ); ?></span>
			</th>
			<th scope="col" class="manage-column column-size column-primary">
				<span><?php esc_html_e( 'Size', 'sqlog' ); ?></span>
			</th>
			<th scope="col" class="manage-column column-date">
			<span><?php esc_html_e( 'Date', 'sqlog' ); ?></span>
			</th>
		</tr>
	</tfoot>
</table>
		<?php
	}

	/*
	* Update options
	*/
	public function sqlog_settings_update() {
		if ( isset( $_POST['sqlog_settings'] ) ) {
			$sqlog_settings = filter_input( INPUT_POST, 'sqlog_settings', FILTER_SANITIZE_STRING );
			if ( ! wp_verify_nonce( $sqlog_settings, 'sqlog-settings' ) ) {
				wp_die( __( 'Sorry, your nonce did not verify.', 'sqlog' ) );
			}
		}
	}

	/*
	* Get filesystem function
	*/
	public function sqlog_logger() {
		global $wpdb;

		if ( ! get_option( 'sqlog_enabled' ) ) {
			return;
		}

		$wpfs = self::get_filesystem();
		if ( ! $wpfs ) {
			return;
		}

		$sqlog_dir = self::get_sqlog_path();
		if ( ! $sqlog_dir ) {
			return;
		}

		$generate_uuid4    = wp_generate_uuid4();
		$snsq_log_path     = $sqlog_dir . date_i18n( 'Y-m-d-His' ) . '-' . $generate_uuid4 . '.log';
		$snsq_log_csv_path = $sqlog_dir . date_i18n( 'Y-m-d-His' ) . '-' . $generate_uuid4 . '.csv';

		$contents     = array();
		$contents_csv = array();
		if ( isset( $wpdb->queries ) && count( $wpdb->queries ) > 0 ) {
			foreach ( $wpdb->queries as $q ) {
				$q[0]           = str_replace( "\r\n", '', $q[0] );
				$q[0]           = str_replace( "\r", '', $q[0] );
				$q[0]           = str_replace( "\n", '', $q[0] );
				$q[0]           = str_replace( "\t", ' ', $q[0] );
				$q[0]           = preg_replace( '/([ ])+/', ' ', $q[0] );
				$q[0]           = ltrim( rtrim( $q[0] ) );
				$contents[]     = '[' . date_i18n( 'Y-m-d-H:i:s' ) . "] $q[0] : ($q[1] s)";
				$contents_csv[] = date_i18n( 'Y-m-d-H:i:s' ) . self::$csv_separator . $q[0] . self::$csv_separator . $q[1];
			}
		}

		if ( count( $contents ) > 0 ) {
			$wpfs->put_contents( $snsq_log_path, implode( "\r\n", $contents ) );
		}
		if ( count( $contents_csv ) > 0 ) {
			$wpfs->put_contents( $snsq_log_csv_path, implode( "\r\n", $contents_csv ) );
		}
	}

	/*
	* Get nb SQLog files
	*/
	public static function get_nb_log_files() {
		$sqlog_path = self::get_sqlog_path();
		if ( ! $sqlog_path ) {
			return 0;
		}

		$wpfs = self::get_filesystem();
		if ( ! $wpfs ) {
			return 0;
		}

		$dirlist = $wpfs->dirlist( $sqlog_path );
		if ( is_bool( $dirlist ) && ! $dirlist ) {
			return 0;
		}

		sort( $dirlist );
		$dirlist = array_reverse( $dirlist );

		$dirlist = array_filter(
			$dirlist,
            function( $element ) {
				if ( ! isset( $element['type'] ) ) {
					return false;
				}
				if ( 'f' !== $element['type'] ) {
					return false;
				}
				if ( ! isset( $element['name'] ) ) {
					return false;
				}
				if ( ! preg_match( '/\.csv$/', $element['name'] ) ) {
					return false;
				}
				return true;
			}
        );

		return count( $dirlist );
	}

	/*
	* Get SQLog files
	*/
	public static function get_log_files( $start = 0, $nb = 10 ) {
		$sqlog_path = self::get_sqlog_path();
		if ( ! $sqlog_path ) {
			return false;
		}

		$wpfs = self::get_filesystem();
		if ( ! $wpfs ) {
			return false;
		}

		$dirlist = $wpfs->dirlist( $sqlog_path );
		if ( is_bool( $dirlist ) && ! $dirlist ) {
			return false;
		}

		sort( $dirlist );
		$dirlist = array_reverse( $dirlist );

		$dirlist = array_filter(
			$dirlist,
            function( $element ) {
				if ( ! isset( $element['type'] ) ) {
					return false;
				}
				if ( 'f' !== $element['type'] ) {
					return false;
				}
				if ( ! isset( $element['name'] ) ) {
					return false;
				}
				// if ( ! preg_match( '/(.log|.csv)/', $element['name'] ) ) {
				if ( ! preg_match( '/\.csv$/', $element['name'] ) ) {
					return false;
				}
				return true;
			}
        );

		return array_slice( $dirlist, $start, $nb );
	}

	/*
	* Get SQLog files path
	*/
	public static function get_sqlog_path() {
		$wpfs = self::get_filesystem();
		if ( ! $wpfs ) {
			return false;
		}

		$wp_upload_dir = wp_upload_dir();
		if ( ! $wp_upload_dir['basedir'] ) {
			return false;
		}

		$sqlog_dir = $wp_upload_dir['basedir'] . '/' . self::$sqlog_dirname . '/';

		if ( ! $wpfs->is_dir( $sqlog_dir ) ) {
			$wpfs->mkdir( $sqlog_dir );
		}

		if ( ! $wpfs->is_dir( $sqlog_dir ) ) {
			return false;
		}

		return $sqlog_dir;
	}

	/*
	* Get SQLog files URL
	*/
	public static function get_sqlog_url() {
		$wp_upload_dir = wp_upload_dir();
		if ( ! $wp_upload_dir['basedir'] ) {
			return false;
		}

		return $wp_upload_dir['baseurl'] . '/' . self::$sqlog_dirname . '/';
	}

	/*
	* Get SQLog file path
	*/
	public static function get_sqlog_file_path( $name = null ) {
		if ( ! $name ) {
			return false;
		}

		$sqlog_path = self::get_sqlog_path();
		if ( ! $sqlog_path ) {
			return false;
		}

		return $sqlog_path . $name;
	}

	/*
	* Get SQLog file URL
	*/
	public static function get_sqlog_file_url( $name = null ) {
		if ( ! $name ) {
			return '#';
		}

		$sqlog_url = self::get_sqlog_url();
		if ( ! $sqlog_url ) {
			return '#';
		}

		return $sqlog_url . $name;
	}

	/*
	* Get SQLog file log URL
	*/
	public static function get_sqlog_file_log_url( $name = null ) {
		return preg_replace( '/\.csv$/', '.log', self::get_sqlog_file_url( $name ) );
	}

	/*
	* Get SQLog file delete URL
	*/
	public static function get_sqlog_file_delete_url( $name = null ) {
		if ( ! $name ) {
			return '#';
		}

		$url_page = add_query_arg(
            array(
				'page'         => 'sqlog-page',
				'name'         => $name,
				'sqlog_action' => 'delete',
            ),
            admin_url( 'admin.php' )
        );

		return wp_nonce_url( $url_page, 'sqlog-delete' );
	}

	/*
	* Process actions
	*/
	public static function sqlog_process_actions() {
		if ( isset( $_POST['action'] ) ) { // phpcs:ignore
			self::sqlog_process_actions_post();
		} else {
			self::sqlog_process_actions_get();
		}
	}

	/*
	* Process actions POST
	*/
	public static function sqlog_process_actions_post() {
		$page       = filter_input( INPUT_POST, 'page', FILTER_SANITIZE_STRING );
		$sqlog_name = filter_input( INPUT_POST, 'sqlog_name', FILTER_SANITIZE_STRING, FILTER_REQUIRE_ARRAY );
		$_wpnonce   = filter_input( INPUT_POST, 'sqlog-delete-files', FILTER_SANITIZE_STRING );
		$action     = filter_input( INPUT_POST, 'action', FILTER_SANITIZE_STRING );

		if ( empty( $_wpnonce ) ) {
			return false;
		}

		if ( 'sqlog-page' !== $page ) {
			add_settings_error(
				'sqlog-errors',
				'sqlog-page-access-denied',
				__( 'Sorry, you are not allowed to be here.', 'sqlog' ),
				'warning'
			);
			return false;
		}

		if ( ! wp_verify_nonce( $_wpnonce, 'sqlog-delete-files' ) ) {
			add_settings_error(
				'sqlog-errors',
				'sqlog-file-delete',
				__( 'Sorry, nonce bulk delete invalid.', 'sqlog' ),
				'warning'
			);
			return false;
		}

		if ( 'delete' !== $action ) {
			add_settings_error(
				'sqlog-errors',
				'sqlog-files-action-delete-missing',
				__( 'Sorry, please choose a valid bulk action.', 'sqlog' ),
				'warning'
			);
			return false;
		}

		if ( ! is_array( $sqlog_name ) ) {
			add_settings_error(
				'sqlog-errors',
				'sqlog-files-items-missing',
				__( 'Sorry, no items selected.', 'sqlog' ),
				'warning'
			);
			return false;
		}

		if ( 0 === count( $sqlog_name ) ) {
			add_settings_error(
				'sqlog-errors',
				'sqlog-files-not-array',
				__( 'Sorry, no item checked.', 'sqlog' ),
				'warning'
			);
			return false;
		}

		$wpfs = self::get_filesystem();
		if ( ! $wpfs ) {
			add_settings_error(
				'sqlog-errors',
				'sqlog-filesystem-failed',
				__( 'Sorry, there is an error with the file system management.', 'sqlog' ),
				'warning'
			);
			return false;
		}

		$delete_succeed = array();
		$delete_failed  = array();
		foreach ( $sqlog_name as $name ) {
			$file_path = self::get_sqlog_file_path( $name );
			if ( ! $file_path ) {
				$delete_failed[] = $name;
				continue;
			}

			if ( ! $wpfs->exists( $file_path ) ) {
				$delete_failed[] = $name;
				continue;
			}

			if ( ! $wpfs->delete( $file_path ) ) {
				$delete_failed[] = $name;
				continue;
			}

			$delete_succeed[] = $name;

			// Delete log file without check
			$file_path_log = preg_replace( '/.csv$/', '.log', $file_path );
			$wpfs->delete( $file_path_log );
		}

		if ( 0 < count( $delete_failed ) ) {
			add_settings_error(
				'sqlog-errors',
				'sqlog-files-delete-error',
				__( 'Sorry, some files could not have been deleted.', 'sqlog' ),
				'warning'
			);
		}

		if ( 0 < count( $delete_succeed ) ) {
			add_settings_error(
				'sqlog-errors',
				'sqlog-file-delete-success',
				__( 'Some files have been deleted with success!', 'sqlog' ),
				'success'
			);
		}
	}

	/*
	* Process actions GET
	*/
	public static function sqlog_process_actions_get() {
		$page     = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_STRING );
		$name     = filter_input( INPUT_GET, 'name', FILTER_SANITIZE_STRING );
		$_wpnonce = filter_input( INPUT_GET, '_wpnonce', FILTER_SANITIZE_STRING );

		if ( empty( $_wpnonce ) ) {
			return false;
		}

		if ( 'sqlog-page' !== $page ) {
			add_settings_error(
				'sqlog-errors',
				'sqlog-page-access-denied',
				__( 'Sorry, you are not allowed to be here.', 'sqlog' ),
				'warning'
			);
			return false;
		}

		if ( ! wp_verify_nonce( $_wpnonce, 'sqlog-delete' ) ) {
			add_settings_error(
				'sqlog-errors',
				'sqlog-file-delete',
				__( 'Sorry, nonce delete invalid.', 'sqlog' ),
				'warning'
			);
			return false;
		}

		if ( empty( $name ) ) {
			add_settings_error(
				'sqlog-errors',
				'sqlog-file-name-empty',
				__( 'Sorry, filename empty.', 'sqlog' ),
				'warning'
			);
			return false;
		}

		$file_path = self::get_sqlog_file_path( $name );
		if ( ! $file_path ) {
			return false;
		}

		$wpfs = self::get_filesystem();
		if ( ! $wpfs ) {
			return false;
		}

		// Delete file log without check
		$file_path_log = preg_replace( '/.csv$/', '.log', $file_path );
		$wpfs->delete( $file_path_log );

		if ( ! $wpfs->exists( $file_path ) ) {
			add_settings_error(
				'sqlog-errors',
				'sqlog-file-unavailable',
				__( 'Sorry, this file does not exist (anymore).', 'sqlog' ),
				'warning'
			);
			return false;
		}

		if ( ! $wpfs->delete( $file_path ) ) {
			add_settings_error(
				'sqlog-errors',
				'sqlog-file-delete-error',
				__( 'Sorry, this file can not be deleted.', 'sqlog' ),
				'warning'
			);
			return false;
		}

		add_settings_error(
			'sqlog-errors',
			'sqlog-file-delete-success',
			__( 'The file has been deleted with success!', 'sqlog' ),
			'success'
		);

		return true;
	}

	/*
	* Get filesystem function
	*/
	public static function get_filesystem() {
		static $filesystem;
		if ( $filesystem ) {
			return $filesystem;
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		$filesystem = new \WP_Filesystem_Direct( new \stdClass() );

		if ( ! defined( 'FS_CHMOD_DIR' ) ) {
			define( 'FS_CHMOD_DIR', ( @fileperms( ABSPATH ) & 0777 | 0755 ) );
		}
		if ( ! defined( 'FS_CHMOD_FILE' ) ) {
			define( 'FS_CHMOD_FILE', ( @fileperms( ABSPATH . 'index.php' ) & 0777 | 0644 ) );
		}

		return $filesystem;
	}
}
