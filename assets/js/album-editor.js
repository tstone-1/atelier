/**
 * The album editor: gallery picker, drag reordering, per-member cover chooser.
 *
 * The gallery editor's twin, and built the same way — jQuery UI sortable for the dragging,
 * wp.template for the row markup, no build step. The one thing it needs that the gallery
 * editor does not is a round trip: a gallery added here has images the browser has never
 * seen, and its cover chooser has to list them.
 *
 * The cover chooser is populated asynchronously, so it starts as "first image in the gallery"
 * and stays a usable, submittable field the whole time. A row saved before the list arrives
 * stores a cover of 0, which is exactly what that option means — never a wrong image.
 */
( function ( $ ) {
	'use strict';

	var counter = 0;

	/**
	 * Rewrites the hidden order field from the current DOM order.
	 */
	function syncOrder() {
		var keys = [];

		$( '#atelier-album-editor-items .atelier-editor__item' ).each( function () {
			var key = $( this ).attr( 'data-key' );

			if ( key ) {
				keys.push( key );
			}
		} );

		$( '#atelier-album-editor-order' ).val( keys.join( ',' ) );
		$( '#atelier-album-editor-empty' ).toggle( 0 === keys.length );
	}

	/**
	 * Fills one row's cover chooser with a gallery's images.
	 *
	 * @param {jQuery} row       The member row.
	 * @param {number} galleryId Gallery post ID.
	 */
	function loadCovers( row, galleryId ) {
		$.post( window.ajaxurl, {
			action: 'atelier_album_covers',
			nonce: AtelierAlbumEditor.nonce,
			album: $( '#post_ID' ).val(),
			gallery: galleryId
		} ).done( function ( response ) {
			var select = row.find( '.atelier-editor__cover' );

			if ( ! response || ! response.success || ! response.data ) {
				return;
			}

			$.each( response.data.covers, function ( index, cover ) {
				$( '<option/>' )
					.attr( 'value', cover.id )
					.attr( 'data-thumb', cover.thumb )
					.text( cover.label )
					.appendTo( select );

				// The renderer shows the first image when no cover is chosen, so showing it
				// here is what makes the thumbnail agree with the page.
				if ( 0 === index ) {
					row.find( '.atelier-editor__thumb' ).html(
						$( '<img/>' ).attr( 'src', cover.thumb ).attr( 'alt', '' )
					);
				}
			} );
		} );
	}

	/**
	 * Appends one gallery to the album.
	 *
	 * @param {number} galleryId Gallery post ID.
	 * @param {string} title     Gallery title, for the row heading.
	 */
	function addItem( galleryId, title ) {
		var template = wp.template( 'atelier-album-editor-item' );
		var row;

		counter += 1;

		row = $(
			template( {
				key: 'n' + counter,
				id: galleryId,
				title: title,
				thumb: ''
			} )
		);

		$( '#atelier-album-editor-items' ).append( row );

		loadCovers( row, galleryId );
		syncOrder();
	}

	/**
	 * Shows the image a cover chooser has just been set to.
	 *
	 * @param {jQuery} select The chooser.
	 */
	function syncThumb( select ) {
		var option = select.find( 'option:selected' );
		var thumb = option.attr( 'data-thumb' );
		var row = select.closest( '.atelier-editor__item' );

		if ( ! thumb ) {
			// "First image in the gallery" carries no thumbnail of its own; the first real
			// option is that image, so it is what the row should show.
			thumb = select.find( 'option[data-thumb]' ).first().attr( 'data-thumb' );
		}

		if ( thumb ) {
			row.find( '.atelier-editor__thumb' ).html(
				$( '<img/>' ).attr( 'src', thumb ).attr( 'alt', '' )
			);
		}
	}

	$( function () {
		var list = $( '#atelier-album-editor-items' );

		if ( ! list.length ) {
			return;
		}

		list.sortable( {
			items: '> .atelier-editor__item',
			placeholder: 'atelier-editor__placeholder',
			forcePlaceholderSize: true,
			tolerance: 'pointer',
			update: syncOrder
		} );

		$( '#atelier-album-add-button' ).on( 'click', function () {
			var select = $( '#atelier-album-add' );
			var galleryId = parseInt( select.val(), 10 );

			if ( ! galleryId ) {
				return;
			}

			addItem( galleryId, select.find( 'option:selected' ).text() );
		} );

		list.on( 'click', '.atelier-editor__remove', function () {
			$( this ).closest( '.atelier-editor__item' ).remove();
			syncOrder();
		} );

		list.on( 'change', '.atelier-editor__cover', function () {
			syncThumb( $( this ) );
		} );

		syncOrder();
	} );
}( jQuery ) );
