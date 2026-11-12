<?php
/**
 * Reusable "Pro feature" preview component.
 *
 * Features provided by the separate Revertix PRO add-on are *previewed* in this
 * plugin's UI so users know they exist. This component decides, for a given
 * feature, whether to:
 *   - render the real control (the add-on is active and provides it), or
 *   - render a preview card with a lock badge that opens the pricing modal.
 * Nothing here disables functionality of this plugin - it only advertises
 * what the separate add-on provides.
 *
 * Usage:
 *   Revertix_Locked_Feature::render( array(
 *       'feature' => 'scheduled_backups',
 *       'title'   => __( 'Scheduled backups', 'revertix' ),
 *       'desc'    => __( '…', 'revertix' ),
 *       'preview' => function () { /* locked-state preview markup *\/ },
 *   ) );
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the dynamic hook name is prefixed with revertix_.

/**
 * Renders a Pro feature in either its unlocked or locked state.
 */
class Revertix_Locked_Feature {

	/**
	 * Render a feature wrapper.
	 *
	 * @param array $args {
	 *     @type string        $feature Feature key (matches plan caps + Pro hooks).
	 *     @type string        $title   Heading.
	 *     @type string        $desc    Short description.
	 *     @type callable|null $preview Optional callback rendering a disabled preview.
	 * }
	 */
	public static function render( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'feature' => '',
				'title'   => '',
				'desc'    => '',
				'preview' => null,
			)
		);

		if ( self::is_unlocked( $args['feature'] ) ) {
			self::render_unlocked( $args );
		} else {
			self::render_locked( $args );
		}
	}

	/**
	 * Whether the given feature is provided on this site. False unless the
	 * Revertix PRO add-on says otherwise via the filter.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public static function is_unlocked( $feature ) {
		return (bool) apply_filters( 'revertix_feature_unlocked', false, $feature );
	}

	/**
	 * Render the real control by delegating to the Pro add-on. If the add-on
	 * reports the feature but registered no renderer, prompt to install it.
	 *
	 * @param array $args Feature args.
	 */
	private static function render_unlocked( array $args ) {
		$hook = 'revertix_pro_render_' . $args['feature'];

		if ( has_action( $hook ) ) {
			/**
			 * Render the unlocked control for a Pro feature.
			 *
			 * @param array $args Feature args.
			 */
			do_action( $hook, $args );
			return;
		}

		echo '<div class="revertix-card revertix-card--notice">';
		echo '<h3 class="revertix-card__title">' . esc_html( $args['title'] ) . '</h3>';
		echo '<p class="revertix-card__desc">' . esc_html__( 'Your plan includes this feature. Install and activate the Revertix PRO add-on to use it.', 'revertix' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Render the visible-but-locked feature card with a lock badge.
	 *
	 * @param array $args Feature args.
	 */
	private static function render_locked( array $args ) {
		?>
		<div class="revertix-card revertix-card--locked" data-revertix-locked="<?php echo esc_attr( $args['feature'] ); ?>" role="button" tabindex="0">
			<span class="revertix-lock" aria-hidden="true">
				<span class="dashicons dashicons-lock"></span>
			</span>
			<div class="revertix-card__head">
				<h3 class="revertix-card__title"><?php echo esc_html( $args['title'] ); ?></h3>
				<span class="revertix-badge revertix-badge--pro"><?php esc_html_e( 'PRO', 'revertix' ); ?></span>
			</div>
			<?php if ( $args['desc'] ) : ?>
				<p class="revertix-card__desc"><?php echo esc_html( $args['desc'] ); ?></p>
			<?php endif; ?>

			<?php if ( is_callable( $args['preview'] ) ) : ?>
				<div class="revertix-card__preview" aria-hidden="true">
					<?php call_user_func( $args['preview'], $args ); ?>
				</div>
			<?php endif; ?>

			<button type="button" class="revertix-btn revertix-btn--upgrade revertix-open-pricing">
				<span class="dashicons dashicons-lock"></span>
				<?php esc_html_e( 'Available in Revertix PRO', 'revertix' ); ?>
			</button>
		</div>
		<?php
	}
}
