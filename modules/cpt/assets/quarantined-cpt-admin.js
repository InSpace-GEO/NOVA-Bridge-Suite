( function ( window, $, undefined ) {
	'use strict';

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

	$( function () {
		$( '.quarantined-cpt-avatar-control' ).each( function () {
			initAvatarControl( $( this ) );
		} );
	} );
}( window, jQuery ));
