<?php
/**
 * The migration section of the settings screen, and the actions behind it.
 *
 * @package Atelier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Presents the migration and performs it on request.
 *
 * Separate from `Atelier_Settings` because it is a different kind of thing: the settings
 * screen edits an option through the Settings API, while this runs a one-way action that
 * writes to `wp_posts`. Mixing them would put an irreversible-looking button inside a form
 * whose other control is a radio group, which is a poor place for it.
 *
 * Three properties are deliberate:
 *
 * - **Post/redirect/get.** Both actions redirect before rendering anything, so a refresh
 *   cannot re-run them. That is not only good manners: this request registered the post
 *   types as they were *before* the rename, so the screen that reports the result has to be
 *   a fresh request or every count on it is read through the wrong registration.
 * - **The guards are in the handler, not the markup.** A button this screen declines to draw
 *   is still reachable by anyone who kept the URL, so the capability check, the nonce and the
 *   Envira-is-active precondition are all enforced where the work happens.
 * - **The result survives the redirect.** It is stashed in a per-user transient rather than
 *   passed through query arguments, so the counts shown are the ones the migration returned
 *   rather than numbers reconstructed from a second look at the database.
 */
class Atelier_Migration_Screen {

	/**
	 * Action name for performing the migration.
	 */
	const ACTION_MIGRATE = 'atelier_migrate';

	/**
	 * Action name for reversing it.
	 */
	const ACTION_ROLLBACK = 'atelier_rollback';

	/**
	 * Transient prefix holding the outcome across the redirect.
	 */
	const RESULT = 'atelier_migration_result_';

	/**
	 * The migrator.
	 *
	 * @var Atelier_Migration
	 */
	private $migration;

	/**
	 * Plugin settings.
	 *
	 * @var Atelier_Settings
	 */
	private $settings;

	/**
	 * Builds the screen.
	 *
	 * @param Atelier_Migration $migration The migrator.
	 * @param Atelier_Settings  $settings  Plugin settings.
	 */
	public function __construct( Atelier_Migration $migration, Atelier_Settings $settings ) {
		$this->migration = $migration;
		$this->settings  = $settings;
	}

	/**
	 * Hooks the two form actions.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_' . self::ACTION_MIGRATE, array( $this, 'handle_migrate' ) );
		add_action( 'admin_post_' . self::ACTION_ROLLBACK, array( $this, 'handle_rollback' ) );
	}

	/**
	 * Performs the migration and returns to the settings screen.
	 *
	 * @return void
	 */
	public function handle_migrate() {
		$this->authorise( self::ACTION_MIGRATE );

		// Enforced here rather than left to the `required` attribute on the checkbox. A form
		// control is a hint to a browser, not a guard: the same request can be replayed, typed
		// by hand, or bookmarked, and none of those carry the box.
		if ( empty( $_POST['atelier_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->remember(
				'migrate',
				array( 'errors' => array( __( 'Confirm that you have a database backup before migrating.', 'atelier' ) ) )
			);
			$this->go_back();
		}

		$result = $this->migration->migrate();

		$this->remember( 'migrate', $result );
		$this->go_back();
	}

	/**
	 * Reverses the migration and returns to the settings screen.
	 *
	 * @return void
	 */
	public function handle_rollback() {
		$this->authorise( self::ACTION_ROLLBACK );

		$result = $this->migration->rollback();

		$this->remember( 'rollback', $result );
		$this->go_back();
	}

	/**
	 * Stops the request unless it is an authorised, intentional submission.
	 *
	 * @param string $action Action being performed.
	 *
	 * @return void
	 */
	private function authorise( $action ) {
		// POST only. `admin_post_<action>` fires for GET too, and a state-changing action
		// reachable by GET is one a prefetcher, a link checker or a bookmark can trigger.
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_die( esc_html__( 'This action must be submitted from the settings screen.', 'atelier' ), '', array( 'response' => 405 ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to migrate galleries.', 'atelier' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Stores an outcome for the request that follows the redirect.
	 *
	 * @param string $action Which action produced it.
	 * @param array  $result The action's return value.
	 *
	 * @return void
	 */
	private function remember( $action, array $result ) {
		set_transient(
			self::RESULT . get_current_user_id(),
			array_merge( $result, array( 'action' => $action ) ),
			MINUTE_IN_SECONDS * 5
		);
	}

	/**
	 * Returns to the settings screen.
	 *
	 * @return void
	 */
	private function go_back() {
		wp_safe_redirect( admin_url( 'options-general.php?page=atelier' ) );
		exit;
	}

	/**
	 * Takes the stored outcome, clearing it so a refresh does not repeat it.
	 *
	 * @return array|null The outcome, or null when there is none.
	 */
	private function take_result() {
		$key    = self::RESULT . get_current_user_id();
		$result = get_transient( $key );

		if ( ! is_array( $result ) ) {
			return null;
		}

		delete_transient( $key );

		return $result;
	}

	/**
	 * Renders the migration section.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->render_result( $this->take_result() );

		$plan = $this->migration->plan();

		echo '<h2>' . esc_html__( 'Storage', 'atelier' ) . '</h2>';

		// A mixed state is reported before anything else and always offers the rollback, even
		// when the schema option says the site was never migrated. That combination is exactly
		// what an interrupted migration leaves behind, and gating the button on the option
		// would hide it in the one state where it is the only way back.
		if ( ! empty( $plan['mixed'] ) ) {
			$this->render_mixed( $plan );

			return;
		}

		if ( $plan['migrated'] ) {
			$this->render_migrated( $plan );

			return;
		}

		$this->render_pending( $plan );
	}

	/**
	 * Renders the recovery state left behind by an interrupted migration.
	 *
	 * @param array $plan Output of `Atelier_Migration::plan()`.
	 *
	 * @return void
	 */
	private function render_mixed( array $plan ) {
		?>
		<div class="notice notice-error inline">
			<p>
				<strong><?php esc_html_e( 'The migration did not finish.', 'atelier' ); ?></strong>
				<?php
				printf(
					/* translators: %d: number of rows under the other set of names. */
					esc_html( _n( '%d row is stored under the other set of names, so some galleries are unreachable.', '%d rows are stored under the other set of names, so some galleries are unreachable.', (int) $plan['stranded'], 'atelier' ) ),
					(int) $plan['stranded']
				);
				?>
			</p>
			<p><?php esc_html_e( 'Roll back to put everything on Envira\'s types, then migrate again. Nothing was deleted, so this is safe to repeat.', 'atelier' ); ?></p>
		</div>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_ROLLBACK ); ?>" />
			<?php wp_nonce_field( self::ACTION_ROLLBACK ); ?>
			<?php submit_button( __( 'Roll back to Envira\'s storage', 'atelier' ), 'primary', 'submit', true ); ?>
		</form>
		<?php
	}

	/**
	 * Renders the outcome of a completed action.
	 *
	 * @param array|null $result Stored outcome, or null.
	 *
	 * @return void
	 */
	private function render_result( $result ) {
		if ( null === $result ) {
			return;
		}

		$errors = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array();

		if ( ! empty( $errors ) ) {
			echo '<div class="notice notice-error">';

			// One error is a sentence and reads as one. Several joined by a space are a wall of
			// run-together sentences, and more than one only ever arrives here from a migration
			// that half-failed — which is the moment someone has to read every word of it and
			// decide what to do. Each error is escaped where it is printed rather than once over
			// the joined string, because there is no joined string any more.
			if ( 1 === count( $errors ) ) {
				echo '<p>' . esc_html( reset( $errors ) ) . '</p>';
			} else {
				echo '<ul class="ul-disc">';

				foreach ( $errors as $error ) {
					echo '<li>' . esc_html( $error ) . '</li>';
				}

				echo '</ul>';
			}

			echo '</div>';

			return;
		}

		$message = 'rollback' === $result['action']
			? sprintf(
				/* translators: 1: galleries, 2: albums, 3: tags. */
				__( 'Rolled back. %1$d galleries, %2$d albums and %3$d image tags are back on Envira\'s types.', 'atelier' ),
				(int) $result['galleries'],
				(int) $result['albums'],
				(int) $result['terms']
			)
			// Every number the migration returns is reported, and the last two are the point.
			// Album records went a whole release without being converted at all, and the SEO
			// settings a rename orphans took the titles and canonical links off 58 indexed
			// archives — both of them invisible from the front end, both of them counted here
			// and, until now, counted into a value nothing printed. An operator reading a
			// success notice cannot otherwise tell either of them happened.
			: sprintf(
				/* translators: 1: galleries, 2: albums, 3: tags, 4: gallery records, 5: album records, 6: SEO settings. */
				__( 'Migrated. %1$d galleries, %2$d albums and %3$d image tags now belong to Atelier. %4$d gallery records and %5$d album records were converted, and %6$d Yoast SEO settings were carried onto the new names.', 'atelier' ),
				(int) $result['galleries'],
				(int) $result['albums'],
				(int) $result['terms'],
				isset( $result['converted'] ) ? (int) $result['converted'] : 0,
				isset( $result['albums_converted'] ) ? (int) $result['albums_converted'] : 0,
				isset( $result['seo_keys'] ) ? (int) $result['seo_keys'] : 0
			);

		echo '<div class="notice notice-success"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Renders the state before migration, with the confirmation form.
	 *
	 * @param array $plan Output of `Atelier_Migration::plan()`.
	 *
	 * @return void
	 */
	private function render_pending( array $plan ) {
		$blocked = $this->settings->envira_is_active();

		?>
		<p>
			<?php esc_html_e( 'Galleries are still stored the way Envira wrote them. Atelier reads them in place, so nothing needs to change — but while that is true, uninstalling Envira would take the gallery, album and tag URLs off the site along with it.', 'atelier' ); ?>
		</p>

		<p><?php esc_html_e( 'Migrating moves the galleries onto Atelier\'s own post types. What it does:', 'atelier' ); ?></p>

		<ul class="ul-disc">
			<li>
				<?php
				printf(
					/* translators: 1: galleries, 2: albums, 3: image tags. */
					esc_html__( 'Renames %1$d galleries, %2$d albums and %3$d image tags to Atelier\'s types, in place. Post IDs do not change, so every [envira-gallery id="…"] shortcode keeps working.', 'atelier' ),
					(int) $plan['galleries'],
					(int) $plan['albums'],
					(int) $plan['terms']
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: 1: convertible galleries, 2: Envira's defaults record count. */
					esc_html__( 'Converts %1$d gallery records into Atelier\'s own format. %2$d are Envira\'s stored defaults rather than galleries and are skipped.', 'atelier' ),
					(int) $plan['convertible'],
					(int) $plan['defaults']
				);
				?>
			</li>
			<li><?php esc_html_e( 'Keeps every URL exactly as it is. /envira/, /envira_album/ and /envira-tag/ continue to work and are still canonical.', 'atelier' ); ?></li>
			<li><?php esc_html_e( 'Leaves Envira\'s own records untouched, so this can be reversed from this screen.', 'atelier' ); ?></li>
		</ul>

		<?php if ( (int) $plan['unreadable'] > 0 ) : ?>
			<p class="notice notice-warning" style="padding: 8px 12px;">
				<?php
				printf(
					/* translators: %d: number of galleries with no readable record. */
					esc_html( _n( '%d gallery has no readable record. It will be renamed but not converted, and will render as empty.', '%d galleries have no readable record. They will be renamed but not converted, and will render as empty.', (int) $plan['unreadable'], 'atelier' ) ),
					(int) $plan['unreadable']
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( $blocked ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'Deactivate Envira Gallery first.', 'atelier' ); ?></strong>
					<?php esc_html_e( 'Migrating renames the rows out from under it, which would leave its screens empty and its shortcodes rendering nothing.', 'atelier' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_MIGRATE ); ?>" />
			<?php wp_nonce_field( self::ACTION_MIGRATE ); ?>

			<p>
				<label>
					<input type="checkbox" name="atelier_confirm" value="1" required <?php disabled( $blocked ); ?> />
					<?php esc_html_e( 'I have a current backup of the database.', 'atelier' ); ?>
				</label>
			</p>

			<?php
			submit_button(
				__( 'Migrate galleries to Atelier', 'atelier' ),
				'primary',
				'submit',
				true,
				$blocked ? array( 'disabled' => 'disabled' ) : array()
			);
			?>
		</form>
		<?php
	}

	/**
	 * Renders the state after migration, with the rollback form.
	 *
	 * @param array $plan Output of `Atelier_Migration::plan()`.
	 *
	 * @return void
	 */
	private function render_migrated( array $plan ) {
		?>
		<p>
			<strong><?php esc_html_e( 'Galleries belong to Atelier.', 'atelier' ); ?></strong>
			<?php
			printf(
				/* translators: 1: galleries, 2: albums, 3: image tags. */
				esc_html__( '%1$d galleries, %2$d albums and %3$d image tags are stored under Atelier\'s own types. Envira Gallery can be uninstalled without taking any URL off the site.', 'atelier' ),
				(int) $plan['galleries'],
				(int) $plan['albums'],
				(int) $plan['terms']
			);
			?>
		</p>

		<p class="description">
			<?php esc_html_e( 'Envira\'s original records were left in place, so rolling back restores exactly what was there rather than reconstructing it.', 'atelier' ); ?>
		</p>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_ROLLBACK ); ?>" />
			<?php wp_nonce_field( self::ACTION_ROLLBACK ); ?>
			<?php submit_button( __( 'Roll back to Envira\'s storage', 'atelier' ), 'secondary', 'submit', true ); ?>
		</form>
		<?php
	}
}
