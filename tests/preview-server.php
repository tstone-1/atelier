<?php
/**
 * A local preview of real galleries, without a WordPress install.
 *
 * Usage:
 *   php -S 127.0.0.1:8765 -t . tests/preview-server.php
 *   open http://127.0.0.1:8765/
 *
 * It serves the plugin's own CSS, JavaScript and vendored PhotoSwipe from disk and answers
 * the two AJAX endpoints out of the fixture, so pagination, tag filtering and the
 * across-pages lightbox all behave as they would on the site. Images are loaded from the
 * live site, so the page needs a network connection but touches nothing on the server.
 *
 * @package Lichtbild\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

define( 'ABSPATH', __DIR__ );
define( 'LICHTBILD_VERSION', 'preview' );
define( 'LICHTBILD_FILE', dirname( __DIR__ ) . '/lichtbild-gallery.php' );
define( 'LICHTBILD_DIR', dirname( __DIR__ ) . '/' );
define( 'LICHTBILD_URL', '/' );

require __DIR__ . '/wp-stubs.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-settings.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-assets.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-config.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-album-config.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-item.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-gallery.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-album.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-repository.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-exif.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-renderer.php';
require LICHTBILD_DIR . 'includes/class-lichtbild-ajax.php';

$fixture = getenv( 'LICHTBILD_FIXTURE' );
$fixture = $fixture ? $fixture : __DIR__ . '/fixture.json';

if ( ! is_readable( $fixture ) ) {
	http_response_code( 500 );
	echo 'fixture not readable: ' . htmlspecialchars( $fixture );
	exit;
}

Lichtbild_Test_Site::load( $fixture );

$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

// Let the built-in server deliver anything that exists on disk.
if ( '/' !== $path && file_exists( LICHTBILD_DIR . ltrim( $path, '/' ) ) ) {
	return false;
}

$repository = new Lichtbild_Repository();
$assets     = new Lichtbild_Assets( new Lichtbild_Settings() );
$renderer   = new Lichtbild_Renderer( $assets );

if ( '/wp-admin/admin-ajax.php' === $path ) {
	$ajax = new Lichtbild_Ajax( $repository, $renderer );

	/*
	 * `wp_send_json_*()` ends the request in WordPress; the stub cannot call `exit` because the
	 * test harness has to keep running, so it throws instead. Uncaught, that appended a PHP
	 * fatal-error dump after a perfectly good JSON body -- which `response.json()` rejects, so
	 * the browser silently kept the previous page and pagination and tag filtering appeared to
	 * do nothing at all. The endpoint was correct throughout; only the harness around it lied.
	 */
	try {
		if ( isset( $_REQUEST['action'] ) && 'lichtbild_items' === $_REQUEST['action'] ) {
			$ajax->handle_items();
		}

		$ajax->handle_page();
	} catch ( Lichtbild_Test_Halt $halt ) {
		// The response has already been written; this only stands in for the exit.
	}

	exit;
}

/**
 * Galleries shown in the preview, chosen to exercise different code paths.
 *
 * The third entry switches the tag filter on. It is off on every gallery on the site, so
 * this is the only way to see that feature — the images themselves really are tagged.
 */
$showcase = array(
	array(
		'id'    => 10,
		'note'  => 'Justified rows, 13 pages, EXIF and sharing in the lightbox.',
		'force' => array(),
	),
	array(
		'id'    => 951,
		'note'  => 'Tag filter switched on for the preview; every image here carries species tags.',
		'force' => array(
			'tags'             => true,
			'tags_position'    => 'above',
			'tags_all_enabled' => true,
		),
	),
	array(
		'id'    => 363,
		'note'  => 'Fixed-column grid rather than justified rows.',
		'force' => array(),
	),
);

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lichtbild preview</title>
<link rel="stylesheet" href="/assets/vendor/photoswipe/photoswipe.css">
<link rel="stylesheet" href="/assets/css/lichtbild.css">
<style>
	body { margin: 0; font: 16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
		color: #1a1a1a; background: #fff; }
	main { max-width: 1180px; margin: 0 auto; padding: 2.5rem 1.25rem 5rem; }
	header.page { border-bottom: 1px solid #e5e5e5; padding-bottom: 1.25rem; margin-bottom: 2.5rem; }
	h1 { font-size: 1.6rem; margin: 0 0 .35rem; }
	.lede { margin: 0; color: #666; font-size: .95rem; }
	section { margin-bottom: 3.5rem; }
	h2 { font-size: 1.15rem; margin: 0 0 .2rem; }
	.meta { margin: 0 0 1rem; color: #777; font-size: .85rem; }
	.meta code { background: #f3f3f3; padding: .1em .4em; border-radius: 3px; font-size: .95em; }
	@media (prefers-color-scheme: dark) {
		body { color: #e8e8e8; background: #141414; }
		header.page { border-color: #2c2c2c; }
		.lede, .meta { color: #9a9a9a; }
		.meta code { background: #262626; }
	}
</style>
</head>
<body>
<main>
<header class="page">
	<h1>Lichtbild preview</h1>
	<p class="lede">Real galleries from timo-stein.com, rendered by Lichtbild. Pagination, tag
		filtering and the lightbox are live; images load from the site itself.</p>
</header>

<?php foreach ( $showcase as $entry ) : ?>
	<?php
	$gallery = $repository->gallery( $entry['id'] );

	if ( null === $gallery ) {
		continue;
	}

	if ( ! empty( $entry['force'] ) ) {
		$gallery = new Lichtbild_Gallery(
			$gallery->id(),
			array_merge( $gallery->settings(), $entry['force'] ),
			$gallery->items()
		);
	}
	?>
	<section>
		<h2><?php echo esc_html( $gallery->title() ); ?></h2>
		<p class="meta">
			<code>[envira-gallery id="<?php echo (int) $gallery->id(); ?>"]</code>
			&middot; <?php echo (int) $gallery->count(); ?> images
			&middot; <?php echo (int) $gallery->page_count(); ?> page(s)
			&middot; <?php echo $gallery->is_justified() ? 'justified' : $gallery->columns() . ' columns'; ?>
			<?php echo $gallery->has_exif() ? '&middot; EXIF' : ''; ?>
			<?php echo $gallery->has_social() ? '&middot; sharing' : ''; ?>
			<br><?php echo esc_html( $entry['note'] ); ?>
		</p>
		<?php echo $renderer->gallery( $gallery, 1 ); ?>
	</section>
<?php endforeach; ?>
</main>

<script>
window.LichtbildSettings = {
	ajaxUrl: '/wp-admin/admin-ajax.php',
	nonce: 'preview',
	photoswipe: '/assets/vendor/photoswipe/photoswipe.esm.min.js',
	i18n: {
		close: 'Close', next: 'Next', previous: 'Previous', zoom: 'Zoom',
		download: 'Download', share: 'Share', shareOn: 'Share on %s',
		copyLink: 'Copy link', copied: 'Link copied'
	}
};
</script>
<script src="/assets/js/lichtbild.js"></script>
</body>
</html>
