/**
 * The block editor's gallery and album blocks.
 *
 * Written against `wp.element.createElement` rather than JSX, for the same reason the rest of
 * this plugin's JavaScript is: there is no build step, and adding one to ship two pickers would
 * be the largest change in the repository for the smallest feature in it.
 *
 * NOTHING ABOUT THE BLOCKS IS DECLARED HERE EXCEPT THE EDITING EXPERIENCE.
 * =======================================================================
 *
 * Title, icon, category, keywords and attributes all live in each block's own `block.json` and
 * reach this file through WordPress's own server-side bootstrap — `register_block_type()` prints
 * the registry into the editor, and `registerBlockType()` merges it under the client settings. So
 * there is one declaration of what a Lichtbild block *is*, and the two halves cannot drift.
 *
 * The one thing that is deliberately not shared is `id`'s type. `block.json` says `number`; the
 * `SelectControl` below hands back a string, because a `<select>` value always is one. Every
 * write therefore goes through `parseInt`, and a block whose stored `id` is `"12"` rather than
 * `12` would be a block WordPress re-serialises differently on every save.
 */
( function ( wp, data ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! data ) {
		return;
	}

	var el = wp.element.createElement;
	var strings = data.i18n || {};

	/**
	 * Builds the `edit` component for one of the two blocks.
	 *
	 * @param {Object} config Block name, picker choices and the strings for its three states.
	 *
	 * @return {Function} The edit component.
	 */
	function makeEdit( config ) {
		return function ( props ) {
			var blockProps = wp.blockEditor.useBlockProps();
			var id = parseInt( props.attributes.id, 10 ) || 0;

			/**
			 * The chooser. Built per call site rather than once: it appears either in the
			 * placeholder or in the sidebar, never in both at the same time, and a shared
			 * element object read as if it did would be a puzzle for whoever came next.
			 *
			 * @return {Object} A SelectControl element.
			 */
			function picker() {
				return el( wp.components.SelectControl, {
					label: config.chooseLabel,
					value: id,
					options: [ { value: 0, label: strings.none } ].concat( config.choices ),
					onChange: function ( value ) {
						props.setAttributes( { id: parseInt( value, 10 ) || 0 } );
					},
					__nextHasNoMarginBottom: true
				} );
			}

			// A site with nothing to pick gets a plain statement rather than an empty dropdown,
			// which otherwise reads as the block being broken.
			if ( ! config.choices.length ) {
				return el(
					'div',
					blockProps,
					el( wp.components.Placeholder, {
						label: config.title,
						instructions: config.noneMessage
					} )
				);
			}

			if ( ! id ) {
				return el(
					'div',
					blockProps,
					el( wp.components.Placeholder, {
						label: config.title,
						instructions: config.instructions
					}, picker() )
				);
			}

			return el(
				'div',
				blockProps,
				el(
					wp.blockEditor.InspectorControls,
					null,
					el( wp.components.PanelBody, { title: config.panelTitle }, picker() )
				),
				el(
					'div',
					{ className: 'lichtbild-block-preview' },
					el( wp.serverSideRender, {
						block: config.name,
						attributes: { id: id },

						// The server answers with an empty body for a gallery that exists but
						// may not be shown -- a draft, or one behind a password. Without this
						// the editor prints its own "Block rendered as empty", which is true
						// and says nothing about why.
						EmptyResponsePlaceholder: function () {
							return el( wp.components.Placeholder, {
								label: config.title,
								instructions: config.emptyMessage
							} );
						}
					} )
				)
			);
		};
	}

	/**
	 * Registers one block.
	 *
	 * @param {Object} config Block name, picker choices and strings.
	 * @param {string} tag    Shortcode this block can be converted from.
	 */
	function register( config, tag ) {
		wp.blocks.registerBlockType( config.name, {
			edit: makeEdit( config ),

			// Dynamic: the markup is produced by the render callback on every request, so
			// nothing is written into the post content but the block comment and its id.
			// That is what keeps a gallery edited on its own screen current in every post
			// that embeds it.
			save: function () {
				return null;
			},

			// Only Lichtbild's own shortcode transforms. `[envira-gallery]` deliberately does
			// not: it still renders under a rollback, because Envira registers it again, and
			// a one-click conversion with no confirmation is the wrong place to quietly spend
			// that. Someone who wants the block can pick it.
			transforms: {
				from: [
					{
						type: 'shortcode',
						tag: tag,
						attributes: {
							id: {
								type: 'number',
								shortcode: function ( attrs ) {
									return parseInt( attrs.named.id, 10 ) || 0;
								}
							}
						}
					}
				]
			}
		} );
	}

	register(
		{
			name: 'lichtbild/gallery',
			choices: data.galleries || [],
			title: strings.galleryTitle,
			chooseLabel: strings.chooseGallery,
			instructions: strings.galleryInstructions,
			panelTitle: strings.settings,
			noneMessage: strings.noGalleries,
			emptyMessage: strings.emptyGallery
		},
		'lichtbild-gallery'
	);

	register(
		{
			name: 'lichtbild/album',
			choices: data.albums || [],
			title: strings.albumTitle,
			chooseLabel: strings.chooseAlbum,
			instructions: strings.albumInstructions,
			panelTitle: strings.albumSettings,
			noneMessage: strings.noAlbums,
			emptyMessage: strings.emptyAlbum
		},
		'lichtbild-album'
	);
} )( window.wp, window.LichtbildBlocks );
