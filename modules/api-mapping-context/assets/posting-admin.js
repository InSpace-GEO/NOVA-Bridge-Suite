( function () {
    'use strict';
    function start() {
        var host = document.getElementById( 'nova-posting-status' ), config = window.NovaStrategyAdmin;
        if ( ! host || ! config || ! config.postingUrl ) { return; }
        var busy = false;
        function node( tag, text ) { var item = document.createElement( tag ); if ( text !== undefined ) { item.textContent = String( text ); } return item; }
        async function call( path, body ) {
            var response = await fetch( config.postingUrl.replace( /\/$/, '' ) + path, { method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' }, body: body === undefined ? undefined : JSON.stringify( body ) } );
            var data = await response.json(); if ( ! response.ok ) { throw new Error( data.message || 'Could not load publishing status.' ); } return data;
        }
        function button( label, action ) { var item = node( 'button', label ); item.type = 'button'; item.className = 'button'; item.addEventListener( 'click', action ); return item; }
        async function load() {
            if ( busy ) { return; } busy = true; host.setAttribute( 'aria-busy', 'true' );
            try {
                var result = await call( '/jobs' ); host.replaceChildren();
                host.appendChild( node( 'h3', 'Publishing and recovery' ) );
                host.appendChild( node( 'p', ! result.enabled ? 'Connection disabled.' : result.paused ? 'New content changes are paused. Recovery and result delivery can continue.' : 'Content delivery is enabled. Each queued delivery must pass configuration and writer checks before content changes.' ) );
                host.appendChild( button( 'Refresh publishing status', load ) );
                var discovery = result.discovery || {};
                if ( discovery.last_error ) { host.appendChild( node( 'p', 'Discovery issue: ' + String( discovery.last_error ) ) ); }
                if ( discovery.suspended ) {
                    host.appendChild( node( 'p', Number( discovery.auth_status ) === 403 ? 'NOVA has restricted new work. Recovery can continue only where NOVA still permits authenticated access.' : 'NOVA authentication needs attention. Delivery is stopped until access is restored.' ) );
                    if ( result.enabled ) { host.appendChild( button( 'Recheck NOVA access', async function () {
                        if ( busy ) { return; } busy = true; host.setAttribute( 'aria-busy', 'true' );
                        try { await call( '/discovery/retry', {} ); busy = false; await load(); }
                        catch ( error ) { var failure = node( 'p', error.message ); failure.setAttribute( 'role', 'alert' ); host.prepend( failure ); }
                        finally { busy = false; host.setAttribute( 'aria-busy', 'false' ); }
                    } ) ); }
                }
                var jobs = result.jobs || [];
                if ( ! jobs.length ) { host.appendChild( node( 'p', 'No delivery jobs yet.' ) ); return; }
                var table = node( 'table' ); table.className = 'widefat striped'; var head = node( 'tr' );
                [ 'Delivery', 'State / phase', 'Last issue', 'Action' ].forEach( function ( label ) { head.appendChild( node( 'th', label ) ); } ); table.appendChild( head );
                jobs.forEach( function ( job ) {
                    var current = job.protocol === 'nova.delivery-snapshot/v1';
                    var row = node( 'tr' ); row.appendChild( node( 'td', current ? job.content_id : 'Historical content ' + job.content_id + ' / ' + job.version ) ); row.appendChild( node( 'td', current ? [ job.state, job.phase ].filter( Boolean ).join( ' / ' ).replace( /_/g, ' ' ) : 'Historical protocol: manual review required' ) ); row.appendChild( node( 'td', typeof job.last_error === 'string' ? job.last_error : '' ) );
                    var action = node( 'td' );
                    if ( current && 'blocked' === job.state ) { action.appendChild( button( 'Resume safely', async function () {
                        if ( busy ) { return; } busy = true;
                        try { await call( '/jobs/' + encodeURIComponent( job.id ) + '/resume', {} ); busy = false; await load(); }
                        catch ( error ) { busy = false; var failure = node( 'p', error.message ); failure.setAttribute( 'role', 'alert' ); host.prepend( failure ); }
                    } ) ); }
                    row.appendChild( action ); table.appendChild( row );
                } ); host.appendChild( table );
            } catch ( error ) { host.replaceChildren( node( 'p', error.message ), button( 'Retry publishing status', load ) ); }
            finally { busy = false; host.setAttribute( 'aria-busy', 'false' ); }
        }
        load();
    }
    if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', start ); } else { start(); }
}() );
