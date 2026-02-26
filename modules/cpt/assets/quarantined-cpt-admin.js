( function ( window, $, undefined ) {
	'use strict';

	function formatMessage( template, replacements ) {
		var output = String( template || '' );

		$.each( replacements || [], function ( index, value ) {
			var position = String( index + 1 );
			var tokenString = '%' + position + '$s';
			var tokenNumber = '%' + position + '$d';
			output = output.split( tokenString ).join( String( value ) );
			output = output.split( tokenNumber ).join( String( value ) );
		} );

		return output;
	}

	function initAvatarControl( $control ) {
		if ( ! $control.length || 'undefined' === typeof wp || ! wp.media ) {
			return;
		}

		var localized = window.quarantinedCptAdmin || {};
		var placeholder = $control.data( 'placeholder' ) || localized.placeholder || '';
		var l10n = localized.l10n || {};

		var $preview = $control.find( '.quarantined-cpt-avatar-preview img' );
		var $field = $control.find( '#quarantined-cpt-author-avatar-id' );
		var $remove = $control.find( '.quarantined-cpt-avatar-remove' );
		var frame;

		if ( $field.val() ) {
			$control.addClass( 'has-image' );
		} else {
			$remove.hide();
		}

		function setState( attachment ) {
			if ( attachment && attachment.url ) {
				var url = attachment.url;

				if ( attachment.sizes ) {
					if ( attachment.sizes.medium_large ) {
						url = attachment.sizes.medium_large.url;
					} else if ( attachment.sizes.medium ) {
						url = attachment.sizes.medium.url;
					} else if ( attachment.sizes.thumbnail ) {
						url = attachment.sizes.thumbnail.url;
					}
				}

				$preview.attr( 'src', url );
				$field.val( attachment.id );
				$remove.show();
				$control.addClass( 'has-image' );
				return;
			}

			if ( placeholder ) {
				$preview.attr( 'src', placeholder );
			}

			$field.val( '' );
			$remove.hide();
			$control.removeClass( 'has-image' );
		}

		$control.on( 'click', '.quarantined-cpt-avatar-select', function ( event ) {
			event.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = wp.media( {
				title: l10n.select || 'Select image',
				button: {
					text: l10n.use || 'Use this image',
				},
				library: {
					type: 'image',
				},
				multiple: false,
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				setState( attachment );
			} );

			frame.open();
		} );

		$control.on( 'click', '.quarantined-cpt-avatar-remove', function ( event ) {
			event.preventDefault();
			setState();
		} );
	}

	function initComponentOrdering( $container ) {
		var $lists = $container.find( '[data-component-order-list]' );

		if ( ! $lists.length ) {
			return;
		}

		function syncOrderValues( $list ) {
			$list.children( '.quarantined-cpt-component-order__item' ).each( function ( index ) {
				var position = index + 1;
				$( this ).find( '[data-order-position]' ).text( String( position ) );
				$( this ).find( '[data-order-input]' ).val( String( position * 10 ) );
			} );
		}

		function initNativeSortable( $list ) {
			var $items = $list.children( '.quarantined-cpt-component-order__item' );
			var draggedItem = null;

			if ( ! $items.length ) {
				return;
			}

			$items.attr( 'draggable', 'true' );

			$items.on( 'dragstart', function ( event ) {
				var originalEvent = event.originalEvent || {};
				var dataTransfer = originalEvent.dataTransfer || null;

				draggedItem = this;
				$( draggedItem ).addClass( 'is-sorting' );

				if ( dataTransfer ) {
					dataTransfer.effectAllowed = 'move';

					try {
						dataTransfer.setData( 'text/plain', 'move' );
					} catch ( error ) {}
				}
			} );

			$items.on( 'dragover', function ( event ) {
				var originalEvent = event.originalEvent || {};
				var clientY = Number( originalEvent.clientY || 0 );
				var rect;
				var before;

				if ( ! draggedItem || draggedItem === this ) {
					return;
				}

				event.preventDefault();

				rect = this.getBoundingClientRect();
				before = clientY < ( rect.top + ( rect.height / 2 ) );

				if ( before ) {
					this.parentNode.insertBefore( draggedItem, this );
				} else {
					this.parentNode.insertBefore( draggedItem, this.nextSibling );
				}
			} );

			$list.on( 'dragover', function ( event ) {
				if ( draggedItem ) {
					event.preventDefault();
				}
			} );

			$items.on( 'drop', function ( event ) {
				event.preventDefault();
				syncOrderValues( $list );
			} );

			$items.on( 'dragend', function () {
				$( this ).removeClass( 'is-sorting' );
				draggedItem = null;
				syncOrderValues( $list );
			} );
		}

		$lists.each( function () {
			var $list = $( this );

			initNativeSortable( $list );
			syncOrderValues( $list );
		} );
	}

	function initCptTabs( $container ) {
		var $tabSets = $container.find( '[data-cpt-tabs]' );

		if ( ! $tabSets.length ) {
			return;
		}

		$tabSets.each( function () {
			var $set = $( this );
			var $buttons = $set.find( '[data-cpt-tab-button]' );
			var $panels = $set.find( '[data-cpt-tab-panel]' );

			if ( ! $buttons.length || ! $panels.length ) {
				return;
			}

			function activateTab( target ) {
				$buttons.each( function () {
					var $button = $( this );
					var isActive = String( $button.data( 'tab-target' ) ) === String( target );
					$button.attr( 'aria-selected', isActive ? 'true' : 'false' );
					$button.toggleClass( 'button-primary', isActive );
				} );

				$panels.each( function () {
					var $panel = $( this );
					var isActive = String( $panel.data( 'cpt-tab-panel' ) ) === String( target );
					$panel.prop( 'hidden', ! isActive );
				} );
			}

			$buttons.on( 'click', function ( event ) {
				event.preventDefault();
				activateTab( $( this ).data( 'tab-target' ) );
			} );

			activateTab( $buttons.first().data( 'tab-target' ) );
		} );
	}

	function initCptDefinitionRows( $container ) {
		var localized = window.quarantinedCptAdmin || {};
		var settings = localized.settings || {};
		var $table = $container.find( '[data-cpt-definitions-table]' );
		var $template = $( '#quarantined-cpt-row-template' );
		var $addButton = $( '#quarantined-cpt-add-row' );

		if ( ! $table.length || ! $template.length || ! $addButton.length ) {
			return;
		}

		var $tbody = $table.find( 'tbody' );
		var nextIndex = parseInt( $table.attr( 'data-next-index' ), 10 );

		if ( isNaN( nextIndex ) || nextIndex < 0 ) {
			nextIndex = $tbody.find( '[data-cpt-row]' ).length;
		}

		function getRowSlug( $row ) {
			var $slugField = $row.find( 'input[name*="[slug]"]' ).first();
			return $.trim( $slugField.val() || '' );
		}

		function getRowPostCount( $row ) {
			var count = parseInt( $row.attr( 'data-post-count' ), 10 );
			return isNaN( count ) ? 0 : Math.max( 0, count );
		}

		function getRemovalMessage( $row ) {
			var slug = getRowSlug( $row );
			var count = getRowPostCount( $row );
			var fallbackSlug = settings.removeFallback || 'this CPT';

			if ( ! slug ) {
				slug = fallbackSlug;
			}

			if ( count > 0 ) {
				return formatMessage( settings.removeWithPosts, [ slug, count ] );
			}

			return formatMessage( settings.removeNoPosts, [ slug ] );
		}

		$addButton.on( 'click', function ( event ) {
			event.preventDefault();

			var templateHtml = String( $template.html() || '' );

			if ( ! templateHtml ) {
				return;
			}

			var nextHtml = templateHtml.replace( /__index__/g, String( nextIndex ) );
			$tbody.append( nextHtml );
			nextIndex += 1;
			$table.attr( 'data-next-index', String( nextIndex ) );
		} );

		$tbody.on( 'click', '[data-remove-cpt-row]', function ( event ) {
			event.preventDefault();

			var $row = $( this ).closest( '[data-cpt-row]' );

			if ( ! $row.length ) {
				return;
			}

			if ( ! window.confirm( getRemovalMessage( $row ) ) ) {
				return;
			}

			$row.remove();
		} );
	}

	function initAnchoredDropdowns( $container ) {
		function openByHash( hash ) {
			if ( ! hash ) {
				return;
			}

			var $target = $container.find( hash );

			if ( ! $target.length || ! $target.is( 'details' ) ) {
				return;
			}

			$target.attr( 'open', true );
		}

		$container.on( 'click', '.quarantined-cpt-settings__anchors a', function () {
			var href = $( this ).attr( 'href' ) || '';

			if ( href.charAt( 0 ) !== '#' ) {
				return;
			}

			openByHash( href );
		} );

		openByHash( window.location.hash || '' );
	}

	function clampChannel( value ) {
		var channel = parseInt( value, 10 );

		if ( isNaN( channel ) ) {
			return 0;
		}

		return Math.max( 0, Math.min( 255, channel ) );
	}

	function toHexChannel( value ) {
		var hex = clampChannel( value ).toString( 16 );
		return 1 === hex.length ? '0' + hex : hex;
	}

	function parseColorToHex( rawValue ) {
		var value = $.trim( String( rawValue || '' ) );
		var hexMatch;
		var rgbMatch;

		if ( ! value ) {
			return '';
		}

		hexMatch = value.match( /^#([0-9a-f]{3}|[0-9a-f]{6})$/i );

		if ( hexMatch ) {
			if ( 4 === value.length ) {
				return '#' + value.charAt( 1 ) + value.charAt( 1 ) + value.charAt( 2 ) + value.charAt( 2 ) + value.charAt( 3 ) + value.charAt( 3 );
			}

			return value.toLowerCase();
		}

		rgbMatch = value.match( /^rgba?\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})(?:\s*,\s*(?:0|0?\.[0-9]+|1(?:\.0+)?)\s*)?\)$/i );

		if ( rgbMatch ) {
			return '#' + toHexChannel( rgbMatch[ 1 ] ) + toHexChannel( rgbMatch[ 2 ] ) + toHexChannel( rgbMatch[ 3 ] );
		}

		return '';
	}

	function initColorControls( $container ) {
		var localized = window.quarantinedCptAdmin || {};
		var settings = localized.settings || {};
		var pickColorLabel = settings.pickColor || 'Pick color';
		var pickFromScreenLabel = settings.pickFromScreen || 'Pick from screen';
		var $inputs = $container.find( '.quarantined-cpt-color-control__value' );

		if ( ! $inputs.length ) {
			return;
		}

		$inputs.each( function () {
			var $valueInput = $( this );
			var $colorPicker;
			var $eyeDropperButton;

			if ( $valueInput.parent().hasClass( 'quarantined-cpt-color-control' ) ) {
				return;
			}

			$valueInput.wrap( '<div class="quarantined-cpt-color-control"></div>' );

			$colorPicker = $( '<input type="color" class="quarantined-cpt-color-control__picker" />' );
			$colorPicker.attr( 'aria-label', pickColorLabel );
			$colorPicker.val( parseColorToHex( $valueInput.val() ) || '#000000' );
			$valueInput.after( $colorPicker );

			if ( 'function' === typeof window.EyeDropper ) {
				$eyeDropperButton = $( '<button type="button" class="button button-secondary quarantined-cpt-color-control__eyedropper"><span class="dashicons dashicons-edit"></span></button>' );
				$eyeDropperButton.attr( 'title', pickFromScreenLabel );
				$eyeDropperButton.attr( 'aria-label', pickFromScreenLabel );
				$colorPicker.after( $eyeDropperButton );

				$eyeDropperButton.on( 'click', function ( event ) {
					var picker;

					event.preventDefault();

					picker = new window.EyeDropper();

					picker.open().then( function ( result ) {
						if ( ! result || ! result.sRGBHex ) {
							return;
						}

						$colorPicker.val( result.sRGBHex );
						$valueInput.val( result.sRGBHex ).trigger( 'change' );
					} ).catch( function () {} );
				} );
			}

			$colorPicker.on( 'input change', function () {
				$valueInput.val( $colorPicker.val() ).trigger( 'change' );
			} );

			$valueInput.on( 'input change', function () {
				var parsedHex = parseColorToHex( $valueInput.val() );

				if ( parsedHex ) {
					$colorPicker.val( parsedHex );
				}
			} );
		} );
	}

	function initSettingsControls() {
		var $settings = $( '.quarantined-cpt-settings' );

		if ( ! $settings.length ) {
			return;
		}

		$settings.each( function () {
			var $container = $( this );
			initCptTabs( $container );
			initComponentOrdering( $container );
			initColorControls( $container );
			initCptDefinitionRows( $container );
			initAnchoredDropdowns( $container );
		} );
	}

	$( function () {
		$( '.quarantined-cpt-avatar-control' ).each( function () {
			initAvatarControl( $( this ) );
		} );

		initSettingsControls();
	} );
}( window, jQuery ) );
