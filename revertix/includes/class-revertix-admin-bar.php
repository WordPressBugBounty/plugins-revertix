<?php
/**
 * Environment indicator + switcher in the WordPress admin bar.
 *
 * The Revertix plugin is cloned into each staging site and active there, so this
 * same code runs on both the live site and its staging copies. A staging copy
 * carries the `revertix_is_staging` option (set when it is built); the live site
 * does not. From that we render:
 *
 *   - on staging: a bright "Staging: <name>" badge + a "Switch to live" link.
 *   - on live:    a "Live" badge whose menu lists every staging site to jump to.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the live/staging badge and switcher to the admin bar.
 */
class Revertix_Admin_Bar {

	/**
	 * Wire the admin-bar node and its styles (front end + admin).
	 */
	public function __construct() {
		add_action( 'admin_bar_menu', array( $this, 'add_nodes' ), 90 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_notices', array( $this, 'staging_notice' ) );

		// Brand the login screen on a staging copy (logo + "Staging" badge), so it
		// is obvious which environment you are signing into.
		add_action( 'login_enqueue_scripts', array( $this, 'login_styles' ) );
		add_filter( 'login_headerurl', array( $this, 'login_url' ) );
		add_filter( 'login_headertext', array( $this, 'login_text' ) );
		add_filter( 'login_message', array( $this, 'login_message' ) );
	}

	/* ---------------------------------------------------------------------
	 * Login screen branding (staging only)
	 * ------------------------------------------------------------------- */

	/**
	 * The Revertix logo URL.
	 *
	 * @return string
	 */
	public static function logo_url() {
		return REVERTIX_PLUGIN_URL . 'assets/img/revertix-logo.svg';
	}

	/**
	 * The full Revertix logo (mark + wordmark) for the staging login screen.
	 *
	 * @return string
	 */
	public static function login_logo_url() {
		return REVERTIX_PLUGIN_URL . 'assets/img/revertix-logo-full.png';
	}

	/**
	 * The Revertix brand icon for the admin bar - the same dashicon used by the
	 * Revertix menu (dashicons-images-alt2), rendered via its :before glyph.
	 *
	 * @return string
	 */
	protected static function mark_svg() {
		return '<img class="revertix-env__logo" src="' . esc_url( self::logo_url() ) . '" alt="" width="18" height="18" />';
	}

	/**
	 * Replace the wp-login logo with the Revertix logo on a staging copy. The
	 * few dynamic rules ride as an inline style on core's 'login' stylesheet.
	 */
	public function login_styles() {
		if ( ! self::is_staging() ) {
			return;
		}

		wp_add_inline_style(
			'login',
			'#login h1 a{background-image:url(' . esc_url( self::login_logo_url() ) . ') !important;background-size:contain !important;background-position:center !important;width:240px !important;height:96px !important}'
			. '.revertix-login-badge{margin:0 0 18px;text-align:center}'
			. '.revertix-login-badge span{display:inline-block;background:#d97706;color:#fff;font-weight:700;font-size:11px;letter-spacing:.08em;text-transform:uppercase;padding:5px 14px;border-radius:999px}'
			. '#login form{border-top:3px solid #d97706}'
		);
	}

	/**
	 * Point the login logo link at the staging home.
	 *
	 * @param string $url Default URL.
	 * @return string
	 */
	public function login_url( $url ) {
		return self::is_staging() ? home_url( '/' ) : $url;
	}

	/**
	 * Login logo title/text on staging.
	 *
	 * @param string $text Default text.
	 * @return string
	 */
	public function login_text( $text ) {
		return self::is_staging() ? 'Revertix - ' . __( 'Staging', 'revertix' ) : $text;
	}

	/**
	 * Prepend a "Staging" badge above the login form.
	 *
	 * @param string $message Existing login message.
	 * @return string
	 */
	public function login_message( $message ) {
		if ( ! self::is_staging() ) {
			return $message;
		}

		$name  = get_option( 'revertix_staging_name', self::staging_slug() );
		$badge = '<p class="revertix-login-badge"><span>'
			. esc_html( sprintf( /* translators: %s: staging name. */ __( 'Staging · %s', 'revertix' ), $name ) )
			. '</span></p>';

		return $badge . $message;
	}

	/**
	 * A clear, persistent banner in wp-admin when this is a staging copy, so it is
	 * never mistaken for the live site.
	 */
	public function staging_notice() {
		if ( ! self::is_staging() ) {
			return;
		}

		$name = get_option( 'revertix_staging_name', self::staging_slug() );
		$live = trailingslashit( (string) get_option( 'revertix_live_url', home_url( '/' ) ) );

		echo '<div class="notice" style="border-left:4px solid #d97706;background:#fff8ef;padding:12px 14px;margin:14px 0;">';
		echo '<p style="margin:0;font-size:14px;">';
		echo '<strong style="color:#b45309;">' . esc_html__( 'Staging site', 'revertix' ) . '</strong> - ';
		echo esc_html(
			sprintf(
				/* translators: %s: staging name. */
				__( 'you are working on the staging copy “%s”. Changes here do NOT affect your live site.', 'revertix' ),
				$name
			)
		);
		echo ' <a href="' . esc_url( $live ) . '">' . esc_html__( 'Go to the live site →', 'revertix' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * The staging slug if the current site is a staging copy, else ''.
	 *
	 * @return string
	 */
	public static function staging_slug() {
		return (string) get_option( 'revertix_is_staging', '' );
	}

	/**
	 * Whether the current site is a Revertix staging copy.
	 *
	 * @return bool
	 */
	public static function is_staging() {
		return '' !== self::staging_slug();
	}

	/**
	 * Build the admin-bar nodes.
	 *
	 * @param WP_Admin_Bar $bar Admin bar instance.
	 */
	public function add_nodes( $bar ) {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		$can = current_user_can( revertix_capability() );

		if ( self::is_staging() ) {
			$this->add_staging_nodes( $bar, $can );
		} else {
			$this->add_live_nodes( $bar, $can );
		}
	}

	/**
	 * Nodes shown when viewing a staging copy.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 * @param bool         $can Whether the user can manage Revertix.
	 */
	protected function add_staging_nodes( $bar, $can ) {
		$name = get_option( 'revertix_staging_name', self::staging_slug() );
		$live = trailingslashit( (string) get_option( 'revertix_live_url', home_url( '/' ) ) );

		$bar->add_node(
			array(
				'id'    => 'revertix-env',
				'title' => '<span class="revertix-env__dot"></span>' . esc_html( sprintf( /* translators: %s: staging name. */ __( 'Staging: %s', 'revertix' ), $name ) ),
				'href'  => $can ? esc_url( $live ) : false,
				'meta'  => array(
					'class' => 'revertix-env revertix-env--staging',
					'title' => __( 'You are viewing a staging copy, not the live site.', 'revertix' ),
				),
			)
		);

		if ( $can ) {
			$bar->add_node(
				array(
					'parent' => 'revertix-env',
					'id'     => 'revertix-env-live',
					'title'  => esc_html__( 'Switch to live site', 'revertix' ),
					'href'   => esc_url( $live ),
				)
			);
		}

		$this->relabel_woocommerce_badge( $bar );
	}

	/**
	 * WooCommerce adds a site-visibility badge to the admin bar that reads "Live"
	 * (its store visibility status). On a staging copy that is misleading, so we
	 * relabel it to "Staging" and drop WooCommerce's green "live" class (our
	 * staging styles then tint it like the rest of the amber bar). Runs at the
	 * default add_nodes priority (90), after WooCommerce adds its node (31), so the
	 * node already exists; add_node() merges, keeping its href.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	protected function relabel_woocommerce_badge( $bar ) {
		if ( ! $bar->get_node( 'woocommerce-site-visibility-badge' ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'woocommerce-site-visibility-badge',
				'title' => esc_html__( 'Staging', 'revertix' ),
				'meta'  => array( 'class' => 'revertix-wc-staging' ),
			)
		);
	}

	/**
	 * Nodes shown when viewing the live site.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 * @param bool         $can Whether the user can manage Revertix.
	 */
	protected function add_live_nodes( $bar, $can ) {
		// On the live site the badge is only useful to people who manage staging.
		if ( ! $can ) {
			return;
		}

		$manage = Revertix_Admin::tab_url( 'staging' );

		$label = revertix_is_premium() ? 'Revertix PRO' : 'Revertix';

		$bar->add_node(
			array(
				'id'    => 'revertix-env',
				'title' => self::mark_svg() . '<span class="revertix-env__name">' . esc_html( $label ) . '</span>',
				'href'  => esc_url( $manage ),
				'meta'  => array(
					'class' => 'revertix-env revertix-env--live',
					'title' => __( 'You are on the live site.', 'revertix' ),
				),
			)
		);

		$sites = Revertix_Staging::all();

		if ( empty( $sites ) ) {
			$bar->add_node(
				array(
					'parent' => 'revertix-env',
					'id'     => 'revertix-env-none',
					'title'  => esc_html__( 'Create a staging site…', 'revertix' ),
					'href'   => esc_url( $manage ),
				)
			);
			return;
		}

		foreach ( $sites as $site ) {
			$bar->add_node(
				array(
					'parent' => 'revertix-env',
					'id'     => 'revertix-env-' . $site['slug'],
					'title'  => esc_html( sprintf( /* translators: %s: staging name. */ __( 'Open staging: %s', 'revertix' ), $site['name'] ) ),
					'href'   => esc_url( trailingslashit( $site['url'] ) ),
				)
			);
		}

		$bar->add_node(
			array(
				'parent' => 'revertix-env',
				'id'     => 'revertix-env-manage',
				'title'  => esc_html__( 'Manage staging', 'revertix' ),
				'href'   => esc_url( $manage ),
			)
		);
	}

	/**
	 * Styles for the env badge, attached as an inline style on a dedicated
	 * handle (the rules are a handful of dynamic lines - the colour is a
	 * setting - so there is no static file to serve). On staging the WHOLE
	 * admin bar turns amber so it is unmistakable; on live only the small
	 * badge is styled.
	 */
	public function enqueue_styles() {
		if ( ! is_admin_bar_showing() || ( ! self::is_staging() && ! current_user_can( revertix_capability() ) ) ) {
			return;
		}

		wp_register_style( 'revertix-env', false, array(), REVERTIX_VERSION );
		wp_enqueue_style( 'revertix-env' );

		$dot = '#wpadminbar .revertix-env__dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#fff;margin-right:7px;vertical-align:middle;box-shadow:0 0 0 2px rgba(255,255,255,.35)}';

		if ( self::is_staging() ) {
			// The colour is configurable via the Revertix PRO add-on; amber default.
			$amber = Revertix_Settings::staging_bar_color();
			wp_add_inline_style(
				'revertix-env',
				// Colour the entire bar amber, with white text/icons.
				'#wpadminbar{background:' . $amber . ' !important}'
				. '#wpadminbar .ab-item,#wpadminbar a.ab-item,#wpadminbar>#wp-toolbar span.ab-label,#wpadminbar>#wp-toolbar span.noticon,#wpadminbar .ab-icon:before,#wpadminbar .ab-item:before{color:#fff !important}'
				. '#wpadminbar:not(.mobile)>#wp-toolbar li:hover>.ab-item,#wpadminbar:not(.mobile)>#wp-toolbar li.hover>.ab-item,#wpadminbar .ab-top-menu>li.hover>.ab-item{background:rgba(0,0,0,.18) !important;color:#fff !important}'
				// Make the Staging badge itself pop (inverted: white pill).
				. '#wpadminbar #wp-admin-bar-revertix-env>.ab-item{background:#fff !important;color:' . $amber . ' !important;font-weight:700}'
				. '#wpadminbar #wp-admin-bar-revertix-env .revertix-env__dot{background:' . $amber . ';box-shadow:0 0 0 2px rgba(0,0,0,.15)}'
				// Match the relabelled WooCommerce "Staging" badge to the amber theme
				// (overrides WooCommerce's grey/green pill).
				. '#wpadminbar .quicklinks #wp-admin-bar-woocommerce-site-visibility-badge a.ab-item{background:#fff !important;color:' . $amber . ' !important;font-weight:700}'
				. $dot
			);
			return;
		}

		// Live: no coloured pill - just the Revertix mark + name, like a normal bar
		// item (it blends into the default admin bar instead of standing out).
		wp_add_inline_style(
			'revertix-env',
			'#wpadminbar .revertix-env > .ab-item{font-weight:600}'
			. '#wpadminbar .revertix-env__logo{margin-right:6px;width:18px;height:18px;vertical-align:middle;position:relative;top:-1px}'
			. '#wpadminbar .revertix-env__name{vertical-align:middle}'
		);
	}
}
