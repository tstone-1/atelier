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

		$( '#lichtbild-album-editor-items .lichtbild-editor__item' ).each( function () {
			var key = $( this ).attr( 'data-key' );

			if ( key ) {
				keys.push( key );
			}
		} );

		$( '#lichtbild-album-editor-order' ).val( keys.join( ',' ) );
		$( '#lichtbild-album-editor-empty' ).toggle( 0 === keys.length );
	}

	/**
	 * Fills one row's cover chooser with a gallery's images.
	 *
	 * @param {jQuery} row       The member row.
	 * @param {number} galleryId Gallery post ID.
	 */
	function loadCovers( row, galleryId ) {
		$.post( window.ajaxurl, {
			action: 'lichtbild_album_covers',
			nonce: LichtbildAlbumEditor.nonce,
			album: $( '#post_ID' ).val(),
			gallery: galleryId
		} ).done( function ( response ) {
			var select = row.find( '.lichtbild-editor__cover' );

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
					row.find( '.lichtbild-editor__thumb' ).html(
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
		var template = wp.template( 'lichtbild-album-editor-item' );
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

		$( '#lichtbild-album-editor-items' ).append( row );

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
		var row = select.closest( '.lichtbild-editor__item' );

		if ( ! thumb ) {
			// "First image in the gallery" carries no thumbnail of its own; the first real
			// option is that image, so it is what the row should show.
			thumb = select.find( 'option[data-thumb]' ).first().attr( 'data-thumb' );
		}

		if ( thumb ) {
			row.find( '.lichtbild-editor__thumb' ).html(
				$( '<img/>' ).attr( 'src', thumb ).attr( 'alt', '' )
			);
		}
	}

	$( function () {
		var list = $( '#lichtbild-album-editor-items' );

		if ( ! list.length ) {
			return;
		}

		list.sortable( {
			items: '> .lichtbild-editor__item',
			placeholder: 'lichtbild-editor__placeholder',
			forcePlaceholderSize: true,
			tolerance: 'pointer',
			update: syncOrder
		} );

		$( '#lichtbild-album-add-button' ).on( 'click', function () {
			var select = $( '#lichtbild-album-add' );
			var galleryId = parseInt( select.val(), 10 );

			if ( ! galleryId ) {
				return;
			}

			addItem( galleryId, select.find( 'option:selected' ).text() );
		} );

		list.on( 'click', '.lichtbild-editor__remove', function () {
			$( this ).closest( '.lichtbild-editor__item' ).remove();
			syncOrder();
		} );

		list.on( 'change', '.lichtbild-editor__cover', function () {
			syncThumb( $( this ) );
		} );

		syncOrder();
	} );
}( jQuery ) );
