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
 * @package Atelier\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

define( 'ABSPATH', __DIR__ );
define( 'ATELIER_VERSION', 'preview' );
define( 'ATELIER_FILE', dirname( __DIR__ ) . '/atelier.php' );
define( 'ATELIER_DIR', dirname( __DIR__ ) . '/' );
define( 'ATELIER_URL', '/' );

require __DIR__ . '/wp-stubs.php';
require ATELIER_DIR . 'includes/class-atelier-assets.php';
require ATELIER_DIR . 'includes/class-atelier-config.php';
require ATELIER_DIR . 'includes/class-atelier-album-config.php';
require ATELIER_DIR . 'includes/class-atelier-item.php';
require ATELIER_DIR . 'includes/class-atelier-gallery.php';
require ATELIER_DIR . 'includes/class-atelier-album.php';
require ATELIER_DIR . 'includes/class-atelier-repository.php';
require ATELIER_DIR . 'includes/class-atelier-exif.php';
require ATELIER_DIR . 'includes/class-atelier-renderer.php';
require ATELIER_DIR . 'includes/class-atelier-ajax.php';

$fixture = getenv( 'ATELIER_FIXTURE' );
$fixture = $fixture ? $fixture : __DIR__ . '/fixture.json';

if ( ! is_readable( $fixture ) ) {
	http_response_code( 500 );
	echo 'fixture not readable: ' . htmlspecialchars( $fixture );
	exit;
}

Atelier_Test_Site::load( $fixture );

$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

// Let the built-in server deliver anything that exists on disk.
if ( '/' !== $path && file_exists( ATELIER_DIR . ltrim( $path, '/' ) ) ) {
	return false;
}

$repository = new Atelier_Repository();
$assets     = new Atelier_Assets( new Atelier_Settings() );
$renderer   = new Atelier_Renderer( $assets );

if ( '/wp-admin/admin-ajax.php' === $path ) {
	$ajax = new Atelier_Ajax( $repository, $renderer );

	if ( isset( $_REQUEST['action'] ) && 'atelier_items' === $_REQUEST['action'] ) {
		$ajax->handle_items();
	}

	$ajax->handle_page();
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
<title>Atelier preview</title>
<link rel="stylesheet" href="/assets/vendor/photoswipe/photoswipe.css">
<link rel="stylesheet" href="/assets/css/atelier.css">
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
	<h1>Atelier preview</h1>
	<p class="lede">Real galleries from timo-stein.com, rendered by Atelier. Pagination, tag
		filtering and the lightbox are live; images load from the site itself.</p>
</header>

<?php foreach ( $showcase as $entry ) : ?>
	<?php
	$gallery = $repository->gallery( $entry['id'] );

	if ( null === $gallery ) {
		continue;
	}

	if ( ! empty( $entry['force'] ) ) {
		$gallery = new Atelier_Gallery(
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
window.AtelierSettings = {
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
<script src="/assets/js/atelier.js"></script>
</body>
</html>
