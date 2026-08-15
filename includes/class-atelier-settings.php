<?php
/**
 * Plugin options and the settings screen.
 *
 * @package Atelier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and presents the one decision Atelier needs from the site owner.
 *
 * That decision is when to take the `[envira-gallery]` shortcode over. Both plugins can be
 * installed at once — they read the same rows and neither writes to the other's — so the
 * useful default is to defer to Envira while it is active and step in the moment it is
 * switched off. That makes the comparison a single toggle in the plugins screen, and makes
 * backing out of Atelier exactly as cheap.
 */
class Atelier_Settings {

	/**
	 * Option name holding the takeover mode.
	 */
	const OPTION_TAKEOVER = 'atelier_takeover';

	/**
	 * Option recording which storage schema the site's galleries are on.
	 */
	const OPTION_SCHEMA = 'atelier_schema_version';

	/**
	 * Schema version written by the migration to Atelier's own post types.
	 */
	const SCHEMA_MIGRATED = 2;

	/**
	 * Option recording whether a gallery renders on its own permalink.
	 */
	const OPTION_STANDALONE = 'atelier_standalone';

	/**
	 * Envira's equivalent option, read until the migration copies it across.
	 */
	const OPTION_STANDALONE_ENVIRA = 'envira_gallery_standalone_enabled';

	/**
	 * Option recording which set of URL paths this site serves its galleries from.
	 *
	 * Note this is an OPTION and `atelier_url_slugs` is a FILTER; they are deliberately not
	 * named the same thing. The option records what the site decided, the filter overrides it.
	 */
	const OPTION_SLUG_SCHEME = 'atelier_slug_scheme';

	/**
	 * The migration section rendered below the settings form.
	 *
	 * @var Atelier_Migration_Screen|null
	 */
	private $migration_screen = null;

	/**
	 * Returns the URL paths this site serves galleries, albums and tag archives from.
	 *
	 * **Decided once and written down, never re-derived.** The derivation asks whether the site
	 * has an Envira history, and a site's answer to that changes over time — Envira's records
	 * can be deleted long after the migration — while its published URLs must not. Deriving on
	 * every request would therefore move 57 indexed URLs the day somebody tidied up an old post
	 * meta key, with no action that looks anything like a permalink change. So the first request
	 * that asks records the answer, and every later one reads it.
	 *
	 * The default for a site with no such history is generic. A fresh install has no reason to
	 * publish its galleries under a path named after another company's product, and doing so
	 * would be the single most likely thing for a plugin reviewer to object to.
	 *
	 * @return array Paths keyed by `gallery`, `album` and `tag`.
	 */
	public function slug_scheme_paths() {
		return 'envira' === $this->slug_scheme()
			? Atelier_Post_Types::SLUGS_ENVIRA
			: Atelier_Post_Types::SLUGS_GENERIC;
	}

	/**
	 * Returns the recorded slug scheme, deciding and storing it on first use.
	 *
	 * @return string Either `envira` or `generic`.
	 */
	/**
	 * Reports whether this site is continuing an Envira installation.
	 *
	 * The question "is there anything to roll back TO", asked once and named, because two places
	 * need it and a chooser that merely declines to offer an action is markup rather than a rule.
	 *
	 * @return bool True when the site has an Envira history.
	 */
	public function continues_envira() {
		return 'envira' === $this->slug_scheme();
	}

	public function slug_scheme() {
		$this->initialise();

		return 'generic' === get_option( self::OPTION_SLUG_SCHEME, '' ) ? 'generic' : 'envira';
	}

	/**
	 * Reports whether Envira ever stored anything on this site.
	 *
	 * Deliberately a bare query calling nothing else: it is the single observation both stored
	 * answers are derived from, and anything it called would be something that reads one of them.
	 *
	 * @return bool True when Envira gallery or album records exist.
	 */
	private function has_envira_history() {
		global $wpdb;

		// COUNT(*) rather than `SELECT meta_id ... LIMIT 1`, and the reason is worth keeping:
		// `get_var()` answers null for no rows and the column value otherwise, so a null check
		// reads correctly against real WordPress and yet cannot be modelled by a stub that
		// answers a count -- which is exactly what this project's stub does, correctly, for
		// every other query it serves. A count is unambiguous to both.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- asked once per site, and its answer is then stored in an option and never re-derived; that option IS the cache. A meta_key existence test has no WP_Query form that does not load every matching post.
		$found = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ( '_eg_gallery_data', '_eg_album_data' )"
		);

		return (int) $found > 0;
	}

	/**
	 * Decides, once, what kind of site this is — and records both consequences together.
	 *
	 * **The two answers have to be written from the same observation, and an earlier draft that
	 * derived them separately was wrong in a way worth recording.** Schema initialisation ran
	 * first and set a fresh site to `SCHEMA_MIGRATED`; the slug scheme then asked
	 * `has_migrated()`, saw `true`, and concluded the site had migrated *from Envira* — so a
	 * brand-new install got Envira's URL paths, which is the precise opposite of the intent. The
	 * signal was ambiguous because "migrated" had come to mean two different things.
	 *
	 * What matters about schema 1 is that it does not mean "new". It means *still on Envira's
	 * storage*: sensible for a site mid-migration, nonsensical for one that has never had Envira.
	 * Left at 1, a fresh install registered post types literally named `envira`, `envira_album`
	 * and `envira-tag` — visible in admin URLs, body classes and sitemap filenames — while every
	 * editor screen refused to work, telling the owner their gallery was "still stored in Envira
	 * Gallery's format" on a site where no such record has ever existed. The only route to a
	 * first gallery was to run a migration of zero rows.
	 *
	 * Guards, all load-bearing:
	 *
	 * - It does nothing once the slug scheme is recorded, which is what makes it idempotent and
	 *   what stops it re-deciding when Envira's records are deleted years later.
	 * - A schema already at or past `SCHEMA_MIGRATED` counts as an Envira history on its own,
	 *   because a site only reaches that by migrating from Envira. Read from the raw option
	 *   rather than through `has_migrated()`, so this cannot observe its own writes.
	 * - It advances the schema ONLY when that option has never been set, so it can never
	 *   overrule a site sitting deliberately at 1 while its owner prepares to migrate.
	 *
	 * @return void
	 */
	private function initialise() {
		$scheme = get_option( self::OPTION_SLUG_SCHEME, '' );

		if ( 'envira' === $scheme || 'generic' === $scheme ) {
			return;
		}

		$schema  = get_option( self::OPTION_SCHEMA, null );
		$history = ( null !== $schema && (int) $schema >= self::SCHEMA_MIGRATED )
			|| $this->has_envira_history();

		update_option( self::OPTION_SLUG_SCHEME, $history ? 'envira' : 'generic' );

		if ( ! $history && null === $schema ) {
			update_option( self::OPTION_SCHEMA, self::SCHEMA_MIGRATED );
		}
	}

	/**
	 * Reports whether a gallery renders on its own permalink.
	 *
	 * Atelier's own option wins; Envira's is the fallback, because before the migration this
	 * has to behave exactly as the site already does — the switch is meant to be invisible,
	 * and `/envira/<slug>/` is indexed. Once migrated the value has been copied across, so
	 * uninstalling Envira cannot take the setting with it and blank every gallery page.
	 *
	 * @return bool True when a gallery renders on its own permalink.
	 */
	public function standalone() {
		$own = get_option( self::OPTION_STANDALONE, null );

		if ( null !== $own && '' !== $own ) {
			return (bool) $own;
		}

		/*
		 * Both sentences above are about a site that HAS an Envira history, and a site without
		 * one inherited their conclusion by accident: there is no Envira option to read, so the
		 * fallback returned Envira's own default of off, and a brand-new gallery's permalink
		 * answered 200 with the title and no photographs on it. Nothing had copied a value
		 * across, because there had been no migration to copy one.
		 *
		 * So the fallback is asked only where it means something. A site on Atelier's own
		 * storage from the start renders a gallery on its own permalink, which is the only
		 * defensible answer for a public post type that WordPress offers a "View" link for.
		 */
		if ( ! $this->continues_envira() ) {
			return true;
		}

		return (bool) get_option( self::OPTION_STANDALONE_ENVIRA, false );
	}

	/**
	 * Gives the settings screen the migration section to render.
	 *
	 * Injected rather than constructed here because the migration screen needs the migrator,
	 * which needs these settings — building it inside would be a cycle. It is optional, so
	 * this class stays usable on its own.
	 *
	 * @param Atelier_Migration_Screen $screen The migration section.
	 *
	 * @return void
	 */
	public function set_migration_screen( Atelier_Migration_Screen $screen ) {
		$this->migration_screen = $screen;
	}

	/**
	 * Reports whether the data has been migrated onto Atelier's own post types.
	 *
	 * Everything that names a post type has to agree with this, so it is a single stored
	 * answer rather than something inferred by looking for rows — a site mid-migration, or
	 * one where the migration failed partway, would give two different answers to a probe.
	 *
	 * @return bool True once the migration has completed.
	 */
	public function has_migrated() {
		$this->initialise();

		return (int) get_option( self::OPTION_SCHEMA, 1 ) >= self::SCHEMA_MIGRATED;
	}

	/**
	 * Hooks the settings screen.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ATELIER_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Returns the configured takeover mode.
	 *
	 * @return string One of `auto`, `always` or `never`.
	 */
	public function takeover() {
		$value = get_option( self::OPTION_TAKEOVER, 'auto' );

		return in_array( $value, array( 'auto', 'always', 'never' ), true ) ? $value : 'auto';
	}

	/**
	 * Reports whether Atelier should handle the Envira shortcodes on this request.
	 *
	 * The setting stops applying once the data has moved, and that is not a convenience —
	 * after migration the rows say `atelier_gallery`, so Envira cannot find them whatever the
	 * setting says. Deferring to it would render nothing at all. The choice of who handles
	 * the shortcode is only a real choice while both plugins can still read the same rows.
	 *
	 * @return bool True when Atelier owns `[envira-gallery]` and `[envira-album]`.
	 */
	public function should_take_over() {
		if ( $this->has_migrated() ) {
			return true;
		}

		$mode = $this->takeover();

		if ( 'always' === $mode ) {
			return true;
		}

		if ( 'never' === $mode ) {
			return false;
		}

		return ! $this->envira_is_active();
	}

	/**
	 * Reports whether Atelier should answer to Envira's shortcode names.
	 *
	 * Taking over `[envira-gallery]` is the whole point on a site continuing an Envira
	 * installation, and it is meaningless on a site that never had one — there is no such
	 * shortcode in any post, and registering the name anyway claims another plugin's tag on a
	 * site with no reason to carry it. So the takeover mode decides *whether to stand aside for
	 * a running Envira*, and the slug scheme decides *whether Envira is part of this site's
	 * history at all*; both have to say yes.
	 *
	 * The scheme is the right second half rather than a fresh query, because it is the same
	 * observation the URL paths are already pinned to: recorded once, and deliberately not
	 * re-derived when Envira's records are deleted years later. A gallery's shortcode and its
	 * permalink must not answer that question differently.
	 *
	 * The one case this gives up: Envira active but having never stored a gallery reads as
	 * `generic`, so `[envira-gallery]` is left alone. It has no gallery to render either way.
	 *
	 * @return bool True when Envira's shortcode names should resolve to Atelier.
	 */
	public function claims_envira_shortcodes() {
		return $this->should_take_over() && 'envira' === $this->slug_scheme();
	}

	/**
	 * Reports whether Envira Gallery is active.
	 *
	 * @return bool True when the Envira plugin is running.
	 */
	public function envira_is_active() {
		return class_exists( 'Envira_Gallery' ) || defined( 'ENVIRA_VERSION' );
	}

	/**
	 * Registers the option with the settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'atelier',
			self::OPTION_TAKEOVER,
			array(
				'type'              => 'string',
				'default'           => 'auto',
				'sanitize_callback' => array( $this, 'sanitize_takeover' ),
			)
		);
	}

	/**
	 * Sanitises the takeover option.
	 *
	 * @param mixed $value Submitted value.
	 *
	 * @return string A valid takeover mode.
	 */
	public function sanitize_takeover( $value ) {
		return in_array( $value, array( 'auto', 'always', 'never' ), true ) ? $value : 'auto';
	}

	/**
	 * Adds the settings page under the Settings menu.
	 *
	 * @return void
	 */
	public function register_page() {
		add_options_page(
			__( 'Atelier', 'atelier' ),
			__( 'Atelier', 'atelier' ),
			'manage_options',
			'atelier',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Adds a settings link to the plugin row.
	 *
	 * @param string[] $links Existing action links.
	 *
	 * @return string[] Action links with the settings link prepended.
	 */
	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=atelier' );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'atelier' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Renders the settings screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$mode     = $this->takeover();
		$active   = $this->envira_is_active();
		$migrated = $this->has_migrated();
		$counts   = wp_count_posts( Atelier_Post_Types::gallery_type( $this ) );
		$total    = 0;

		if ( is_object( $counts ) ) {
			foreach ( array( 'publish', 'private', 'draft' ) as $status ) {
				$total += isset( $counts->$status ) ? (int) $counts->$status : 0;
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Atelier', 'atelier' ); ?></h1>

			<p>
				<?php
				printf(
					/* translators: %d: number of galleries found. */
					esc_html( _n( 'Reading %d gallery.', 'Reading %d galleries.', $total, 'atelier' ) ),
					(int) $total
				);
				?>
				<?php if ( $active ) : ?>
					<strong><?php esc_html_e( 'Envira Gallery is currently active.', 'atelier' ); ?></strong>
				<?php else : ?>
					<strong><?php esc_html_e( 'Envira Gallery is not active.', 'atelier' ); ?></strong>
				<?php endif; ?>
			</p>

			<form action="options.php" method="post">
				<?php settings_fields( 'atelier' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Handle Envira shortcodes', 'atelier' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="<?php echo esc_attr( self::OPTION_TAKEOVER ); ?>"
										value="auto" <?php checked( $mode, 'auto' ); ?> />
									<?php esc_html_e( 'Automatically — only when Envira Gallery is deactivated', 'atelier' ); ?>
								</label><br />
								<label>
									<input type="radio" name="<?php echo esc_attr( self::OPTION_TAKEOVER ); ?>"
										value="always" <?php checked( $mode, 'always' ); ?> />
									<?php esc_html_e( 'Always — render every gallery with Atelier, even if Envira is active', 'atelier' ); ?>
								</label><br />
								<label>
									<input type="radio" name="<?php echo esc_attr( self::OPTION_TAKEOVER ); ?>"
										value="never" <?php checked( $mode, 'never' ); ?> />
									<?php esc_html_e( 'Never — only the [atelier-gallery] shortcode uses Atelier', 'atelier' ); ?>
								</label>
							</fieldset>
							<p class="description">
								<?php if ( $migrated ) : ?>
									<?php esc_html_e( 'This setting no longer applies. The galleries have been migrated to Atelier\'s own storage, so Envira cannot read them whatever it is set to, and Atelier handles both shortcodes.', 'atelier' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Atelier reads the same gallery records Envira wrote and never modifies them, so switching between the two is lossless in both directions.', 'atelier' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php
			if ( null !== $this->migration_screen ) {
				$this->migration_screen->render();
			}
			?>

			<h2><?php esc_html_e( 'Shortcodes', 'atelier' ); ?></h2>
			<p>
				<code>[atelier-gallery id="123"]</code>
				<?php esc_html_e( 'renders a gallery, and always uses Atelier regardless of the setting above.', 'atelier' ); ?>
			</p>
			<p>
				<code>[atelier-album id="123"]</code>
				<?php esc_html_e( 'renders an album as a grid of gallery covers.', 'atelier' ); ?>
			</p>
		</div>
		<?php
	}
}
