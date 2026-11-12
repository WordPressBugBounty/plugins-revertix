<?php
/**
 * Base class for every long-running, resumable Revertix operation.
 *
 * A job is a small state machine persisted to disk. The browser calls an AJAX
 * action repeatedly; each call hands a *time budget* to run(), which executes
 * discrete steps until the budget is spent, then returns progress. Because all
 * state lives in state.json, a job survives across requests and can resume from
 * exactly where it left off - the core requirement for never timing out on big
 * sites.
 *
 * Subclasses implement:
 *   - stages()        : ordered list of stage keys.
 *   - step()          : do one unit of work; advance offsets; call advance_stage()
 *                       when the current stage is finished, or complete() at the end.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- direct filesystem calls are required for resumable, chunked backup/restore/import; WP_Filesystem cannot stream work across time-budgeted ticks.

/**
 * Abstract resumable job.
 */
abstract class Revertix_Job {

	const STATUS_RUNNING  = 'running';
	const STATUS_COMPLETE = 'complete';
	const STATUS_ERROR    = 'error';

	/**
	 * Unique job id (also the tmp subfolder name).
	 *
	 * @var string
	 */
	protected $id;

	/**
	 * Job state (persisted as JSON).
	 *
	 * @var array
	 */
	protected $state;

	/**
	 * Wall-clock deadline for the current run() invocation (epoch float).
	 *
	 * @var float
	 */
	protected $deadline = 0.0;

	/**
	 * Whether the shutdown fatal-guard has been registered for this request.
	 *
	 * @var bool
	 */
	protected $shutdown_registered = false;

	/**
	 * Map of job "type" => class name, so a job can be reloaded by id alone.
	 *
	 * @return array<string,string>
	 */
	protected static function type_map() {
		/**
		 * Allow add-ons (e.g. Revertix PRO) to register their own job types.
		 *
		 * @param array<string,string> $map type => class name.
		 */
		return apply_filters(
			'revertix_job_types',
			array(
				'export'  => 'Revertix_Export_Job',
				'import'  => 'Revertix_Import_Job',
				'staging' => 'Revertix_Staging_Job',
			)
		);
	}

	/**
	 * Constructor is protected - use create() or load().
	 *
	 * @param string $id    Job id.
	 * @param array  $state Job state.
	 */
	protected function __construct( $id, array $state ) {
		$this->id    = $id;
		$this->state = $state;
	}

	/* ---------------------------------------------------------------------
	 * Lifecycle
	 * ------------------------------------------------------------------- */

	/**
	 * Create a brand-new job of the called subclass.
	 *
	 * @param array $args Job-specific arguments stored under state['args'].
	 * @return static
	 */
	public static function create( array $args = array() ) {
		Revertix_Storage::ensure();

		$id    = self::generate_id();
		$type  = static::type_slug();
		$state = array(
			'id'        => $id,
			'type'      => $type,
			// Continuation secret: lets the AJAX run-loop keep authorizing ticks
			// even if the logged-in session is invalidated mid-job (e.g. when an
			// import replaces the users table). Handed to the browser once.
			'secret'    => wp_generate_password( 40, false, false ),
			'status'    => self::STATUS_RUNNING,
			'stage'     => '',
			'stage_idx' => 0,
			'message'   => '',
			'percent'   => 0,
			'progress'  => array(
				'processed' => 0,
				'total'     => 0,
			),
			'args'      => $args,
			'data'      => array(),
			'created'   => time(),
			'updated'   => time(),
		);

		$job          = new static( $id, $state );
		$stages       = $job->stages();
		$state['stage'] = isset( $stages[0] ) ? $stages[0] : '';
		$job->state   = $state;

		wp_mkdir_p( $job->dir() );
		$job->init();
		$job->save();

		Revertix_Log::info(
			'Job started.',
			array(
				'job'  => $id,
				'type' => $type,
			)
		);

		return $job;
	}

	/**
	 * Load an existing job by id, returning the correct subclass.
	 *
	 * @param string $id Job id.
	 * @return Revertix_Job|null
	 */
	public static function load( $id ) {
		$id = self::sanitize_id( $id );
		if ( '' === $id ) {
			return null;
		}

		$file = self::dir_for( $id ) . 'state.json';
		if ( ! file_exists( $file ) ) {
			return null;
		}

		$state = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $state ) || empty( $state['type'] ) ) {
			return null;
		}

		$map   = self::type_map();
		$class = isset( $map[ $state['type'] ] ) ? $map[ $state['type'] ] : '';

		if ( '' === $class || ! class_exists( $class ) ) {
			return null;
		}

		return new $class( $id, $state );
	}

	/**
	 * Optional hook for subclasses to prepare state right after creation.
	 */
	protected function init() {}

	/* ---------------------------------------------------------------------
	 * Subclass contract
	 * ------------------------------------------------------------------- */

	/**
	 * The job type slug. Subclasses must override (matches type_map()).
	 *
	 * @return string
	 */
	abstract public static function type_slug();

	/**
	 * Ordered list of stage keys for this job.
	 *
	 * @return string[]
	 */
	abstract public function stages();

	/**
	 * Human labels for each stage key (for the progress stepper). Subclasses
	 * override; anything missing falls back to a humanised key.
	 *
	 * @return array<string,string>
	 */
	protected function step_labels() {
		return array();
	}

	/**
	 * Ordered steps with labels, for the front-end progress UI.
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	public function steps() {
		$labels = $this->step_labels();
		$steps  = array();
		foreach ( $this->stages() as $key ) {
			$steps[] = array(
				'key'   => $key,
				'label' => isset( $labels[ $key ] ) ? $labels[ $key ] : ucfirst( str_replace( '_', ' ', $key ) ),
			);
		}
		return $steps;
	}

	/**
	 * Execute one unit of work for the current stage.
	 *
	 * Implementations should do a bounded amount of work, update progress, and
	 * either return (to be called again) or call advance_stage()/complete().
	 */
	abstract protected function step();

	/* ---------------------------------------------------------------------
	 * Run loop
	 * ------------------------------------------------------------------- */

	/**
	 * Run steps until the time budget is exhausted or the job ends.
	 *
	 * @param float|null $budget Seconds to spend; null uses the filtered default.
	 * @return array Public status (see public_status()).
	 */
	public function run( $budget = null ) {
		if ( self::STATUS_RUNNING !== $this->state['status'] ) {
			return $this->public_status();
		}

		if ( null === $budget ) {
			// A short per-request slice keeps two things healthy:
			//  - progress is visible: the browser polls between slices, so even a
			//    fast job reports several intermediate steps instead of 0% → done.
			//  - large sites are SAFE: each slice does a small, bounded amount of
			//    work and persists state, staying well under any PHP time limit;
			//    the job simply resumes on the next slice, however big the site.
			$budget = (float) apply_filters( 'revertix_job_time_budget', 5.0 );
		}

		$this->deadline = microtime( true ) + (float) $budget;

		// Catch a hard PHP fatal (e.g. out-of-memory) that bypasses try/catch, so
		// the job is recorded as failed instead of silently left "running".
		$this->register_shutdown_guard();

		try {
			while ( $this->time_left() > 1.0 && self::STATUS_RUNNING === $this->state['status'] ) {
				$this->step();
			}
		} catch ( Throwable $e ) {
			Revertix_Log::error(
				'Unhandled ' . get_class( $e ) . ' during job step.',
				array(
					'job'   => $this->id,
					'type'  => isset( $this->state['type'] ) ? $this->state['type'] : '',
					'stage' => isset( $this->state['stage'] ) ? $this->state['stage'] : '',
					'error' => $e->getMessage(),
					'where' => $e->getFile() . ':' . $e->getLine(),
				)
			);
			$this->fail( $e->getMessage() );
		}

		$this->save();

		return $this->public_status();
	}

	/**
	 * Seconds remaining in the current run budget.
	 *
	 * @return float
	 */
	protected function time_left() {
		return $this->deadline - microtime( true );
	}

	/**
	 * Register a one-time shutdown handler that records a hard PHP fatal (memory
	 * exhaustion, max-execution-time, etc.) which a try/catch can never see.
	 */
	protected function register_shutdown_guard() {
		if ( $this->shutdown_registered ) {
			return;
		}
		$this->shutdown_registered = true;
		register_shutdown_function( array( $this, 'on_shutdown' ) );
	}

	/**
	 * Shutdown callback: if the request is ending on a fatal while this job is
	 * still "running", log it with location and mark the job failed.
	 */
	public function on_shutdown() {
		if ( self::STATUS_RUNNING !== $this->state['status'] ) {
			return; // Ended cleanly (complete/error) - nothing to do.
		}

		$err = error_get_last();
		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

		if ( ! $err || ! in_array( $err['type'], $fatal_types, true ) ) {
			return; // Not a fatal (likely just the budget loop exiting).
		}

		Revertix_Log::error(
			'Fatal PHP error during job - request shut down.',
			array(
				'job'   => $this->id,
				'type'  => isset( $this->state['type'] ) ? $this->state['type'] : '',
				'stage' => isset( $this->state['stage'] ) ? $this->state['stage'] : '',
				'error' => $err['message'],
				'where' => $err['file'] . ':' . $err['line'],
			)
		);

		// Persist the failed state directly (avoid fail()'s duplicate log entry).
		$this->state['status']  = self::STATUS_ERROR;
		$this->state['message'] = sprintf(
			/* translators: %s: PHP fatal error message. */
			__( 'A fatal error stopped the operation: %s', 'revertix' ),
			$err['message']
		);
		$this->save();
	}

	/* ---------------------------------------------------------------------
	 * State transitions
	 * ------------------------------------------------------------------- */

	/**
	 * Move to the next stage, or complete the job if this was the last stage.
	 */
	protected function advance_stage() {
		$stages = $this->stages();
		$next   = $this->state['stage_idx'] + 1;

		if ( $next >= count( $stages ) ) {
			$this->complete();
			return;
		}

		$from = isset( $stages[ $this->state['stage_idx'] ] ) ? $stages[ $this->state['stage_idx'] ] : '';

		$this->state['stage_idx'] = $next;
		$this->state['stage']     = $stages[ $next ];

		// Keep the overall percent monotonic across stages (no reset-to-0 jumps).
		$this->state['percent'] = min( 99, (int) floor( $next / max( 1, count( $stages ) ) * 100 ) );

		Revertix_Log::debug(
			'Stage advanced.',
			array(
				'job'  => $this->id,
				'from' => $from,
				'to'   => $stages[ $next ],
			)
		);
	}

	/**
	 * Mark the job complete.
	 *
	 * @param string $message Optional final message.
	 */
	protected function complete( $message = '' ) {
		$this->state['status']  = self::STATUS_COMPLETE;
		$this->state['percent'] = 100;
		if ( '' !== $message ) {
			$this->state['message'] = $message;
		}

		Revertix_Log::info(
			'Job completed.',
			array(
				'job'  => $this->id,
				'type' => isset( $this->state['type'] ) ? $this->state['type'] : '',
			)
		);
	}

	/**
	 * Mark the job failed.
	 *
	 * @param string $message Error message.
	 */
	protected function fail( $message ) {
		$this->state['status']  = self::STATUS_ERROR;
		$this->state['message'] = $message;

		Revertix_Log::error(
			$message,
			array(
				'job'   => $this->id,
				'type'  => isset( $this->state['type'] ) ? $this->state['type'] : '',
				'stage' => isset( $this->state['stage'] ) ? $this->state['stage'] : '',
			)
		);
	}

	/**
	 * Update the progress numbers and recompute percent.
	 *
	 * @param int $processed Units processed so far.
	 * @param int $total     Total units (0 = indeterminate).
	 */
	protected function set_progress( $processed, $total ) {
		$this->state['progress']['processed'] = (int) $processed;
		$this->state['progress']['total']     = (int) $total;

		// Map the within-stage fraction onto this stage's slice of the whole job,
		// so the bar climbs smoothly 0→100 across every stage instead of resetting.
		$count   = max( 1, count( $this->stages() ) );
		$frac    = $total > 0 ? min( 1.0, $processed / $total ) : 0.0;
		$overall = ( $this->state['stage_idx'] + $frac ) / $count;

		$this->state['percent'] = min( 99, (int) floor( $overall * 100 ) );
	}

	/* ---------------------------------------------------------------------
	 * Persistence + accessors
	 * ------------------------------------------------------------------- */

	/**
	 * Persist state to disk (atomic write).
	 */
	public function save() {
		$this->state['updated'] = time();

		$file = $this->dir() . 'state.json';
		$tmp  = $file . '.' . wp_generate_password( 6, false, false ) . '.tmp';

		file_put_contents( $tmp, wp_json_encode( $this->state ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		@rename( $tmp, $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * The public, JSON-safe status returned to the browser.
	 *
	 * @return array
	 */
	public function public_status() {
		return array(
			'id'       => $this->id,
			'type'     => $this->state['type'],
			'status'   => $this->state['status'],
			'stage'    => $this->state['stage'],
			'percent'  => (int) $this->state['percent'],
			'message'  => (string) $this->state['message'],
			'progress' => $this->state['progress'],
			'result'   => $this->result(),
		);
	}

	/**
	 * Subclasses override to expose a final result (e.g. a download URL).
	 *
	 * @return array
	 */
	protected function result() {
		return array();
	}

	/**
	 * Absolute path to this job's working directory (trailing slash).
	 *
	 * @return string
	 */
	public function dir() {
		return self::dir_for( $this->id );
	}

	/**
	 * Job working directory for a given id.
	 *
	 * @param string $id Job id.
	 * @return string
	 */
	protected static function dir_for( $id ) {
		return Revertix_Storage::sub_dir( 'tmp' ) . $id . '/';
	}

	/**
	 * The job id.
	 *
	 * @return string
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * The continuation secret (used to authorize run-loop ticks).
	 *
	 * @return string
	 */
	public function secret() {
		return isset( $this->state['secret'] ) ? (string) $this->state['secret'] : '';
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Generate a random, filesystem-safe job id.
	 *
	 * @return string
	 */
	protected static function generate_id() {
		return static::type_slug() . '-' . wp_generate_password( 12, false, false );
	}

	/**
	 * Sanitize a job id coming from a request.
	 *
	 * @param string $id Raw id.
	 * @return string
	 */
	public static function sanitize_id( $id ) {
		return preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $id );
	}
}
