/* global QuarantinedCPTBodyClean, wp */
( function () {
	const settings = window.QuarantinedCPTBodyClean || {};
	const mainSelector = settings.mainSelector || 'main.quarantined-cpt';
	const keepAttr = settings.keepAttribute || 'data-quarantined-keep';
	const allowedSelectors = Array.isArray( settings.allowedSelectors ) ? settings.allowedSelectors : [];
	const boundarySelectors = Array.isArray( settings.boundarySelectors ) ? settings.boundarySelectors : [];
	const pruneSelectors = Array.isArray( settings.pruneSelectors ) ? settings.pruneSelectors : [];
	const customSelectors = Array.isArray( settings.customSelectors ) ? settings.customSelectors : [];

	const pruneTargets = Array.from(
		new Set(
			[].concat( pruneSelectors, customSelectors ).filter( function ( selector ) {
				return typeof selector === 'string' && selector.trim().length > 0;
			} )
		)
	);

	const domReady = ( function () {
		if ( window.wp && typeof window.wp.domReady === 'function' ) {
			return window.wp.domReady;
		}

		return function ( callback ) {
			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', callback, { once: true } );
			} else {
				callback();
			}
		};
	} )();

	const allowList = allowedSelectors.concat( [
		'header',
		'nav',
		'footer',
		'.site-header',
		'.main-header',
		'.top-bar',
		'.topbar',
		'.navbar',
		'.navigation',
		'.site-navigation',
		'.main-navigation',
		'.menu',
		'.menu-wrapper',
		'.elementor-location-header',
		'.elementor-location-top-bar',
		'.site-footer',
		'.main-footer',
		'.footer',
		'.colophon',
		'.elementor-location-footer',
		'#wpadminbar',
		'.wpadminbar',
		'.wp-site-blocks',
		'#wp-site-blocks',
	] );

	const matches = function ( element, selector ) {
		if ( ! element || element.nodeType !== 1 ) {
			return false;
		}

		try {
			return element.matches( selector );
		} catch ( e ) {
			return false;
		}
	};

	const matchesAny = function ( element, selectors ) {
		if ( ! element || element.nodeType !== 1 ) {
			return false;
		}

		for ( let i = 0; i < selectors.length; i += 1 ) {
			if ( matches( element, selectors[ i ] ) ) {
				return true;
			}
		}

		return false;
	};

	const containsStyles = function ( element ) {
		if ( ! element || element.nodeType !== 1 ) {
			return false;
		}

		return Boolean( element.querySelector( 'style, link[rel="stylesheet"], script[type="text/css"], script[data-loading="global-styles"]' ) );
	};

	const isAllowed = function ( element ) {
		if ( ! element || element.nodeType !== 1 ) {
			return false;
		}

		if ( element === document.body || element === document.documentElement ) {
			return true;
		}

		if ( element.hasAttribute( keepAttr ) ) {
			return true;
		}

		if ( matchesAny( element, allowList ) ) {
			return true;
		}

		if ( containsStyles( element ) ) {
			return true;
		}

		return false;
	};

	const markSuppressed = function ( element ) {
		if ( ! element ) {
			return;
		}

		element.setAttribute( 'data-quarantined-suppressed', 'true' );
		element.classList.add( 'quarantined-cpt-is-suppressed' );
		element.style.setProperty( 'display', 'none', 'important' );
	};

	const shouldKeep = function ( element, target ) {
		if ( ! element || element.nodeType !== 1 ) {
			return true;
		}

		if ( element === target || element.contains( target ) || target.contains( element ) ) {
			return true;
		}

		if ( element.hasAttribute( keepAttr ) ) {
			return true;
		}

		if ( element.closest( '[' + keepAttr + ']' ) ) {
			return true;
		}

		if ( isAllowed( element ) ) {
			return true;
		}

		return false;
	};

	const pruneNodes = function ( target ) {
		if ( ! pruneTargets.length ) {
			return;
		}

		const selector = pruneTargets.join( ', ' );
		const candidates = document.querySelectorAll( selector );

		candidates.forEach( function ( node ) {
			if ( shouldKeep( node, target ) ) {
				return;
			}

			markSuppressed( node );
		} );
	};

	const pruneSiblings = function ( target ) {
		let parent = target.parentElement;

		const stops = boundarySelectors.length
			? boundarySelectors
			: [ 'body', '.wp-site-blocks', '.site', '.site-container', '#page' ];

		while ( parent ) {
			if ( parent === document.body || matchesAny( parent, stops ) ) {
				break;
			}

			Array.prototype.forEach.call( parent.children, function ( child ) {
				if ( shouldKeep( child, target ) ) {
					return;
				}

				if ( containsStyles( child ) ) {
					return;
				}

				if ( matchesAny( child, pruneTargets ) || child.matches( 'article, section, .entry-content, .entry-header, .page-header, .breadcrumbs, .breadcrumb, .elementor, .wp-block-post-content' ) ) {
					markSuppressed( child );
				}
			} );

			if ( parent.nodeType === 1 && ! parent.hasAttribute( keepAttr ) ) {
				parent.setAttribute( keepAttr, 'true' );
			}

			parent = parent.parentElement;
		}
	};

	domReady( function () {
		if ( document.body && document.body.classList.contains( 'wp-admin' ) ) {
			return;
		}

		const main = document.querySelector( mainSelector );

		if ( ! main ) {
			return;
		}

		let ancestor = main.parentElement;

		while ( ancestor && ancestor !== document.body ) {
			if ( ancestor.nodeType === 1 && ! ancestor.hasAttribute( keepAttr ) ) {
				ancestor.setAttribute( keepAttr, 'true' );
			}

			ancestor = ancestor.parentElement;
		}

		pruneNodes( main );
		pruneSiblings( main );

		if ( document.body ) {
			document.body.setAttribute( 'data-quarantined-ready', 'true' );
		}
	} );
} )();
