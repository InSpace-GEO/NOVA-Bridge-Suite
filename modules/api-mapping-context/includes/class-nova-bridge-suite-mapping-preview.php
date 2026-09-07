<?php
/** Authenticated frontend rendering for the mapping inspector. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Nova_Bridge_Suite_Mapping_Preview {
	public static function bootstrap(): void {
		add_action( 'init', [ __CLASS__, 'prepare' ], 0 );
		add_action( 'template_redirect', [ __CLASS__, 'render' ], -100 );
	}
	public static function url( array $entity ): string {
		$url = $entity['url'];
		if ( 'post' === $entity['reference_type'] && 'publish' !== get_post_status( $entity['reference_id'] ) ) { $url = get_preview_post_link( $entity['reference_id'] ); }
		if ( ! is_string( $url ) || ! $url ) { return ''; }
		return add_query_arg( [ 'nova_mapping_preview' => $entity['reference_type'], 'nova_reference' => $entity['reference_id'], '_nova_nonce' => wp_create_nonce( 'nova_mapping_preview:' . $entity['reference_type'] . ':' . $entity['reference_id'] ) ], $url );
	}
	public static function prepare(): void {
		if ( ! isset( $_GET['nova_mapping_preview'] ) ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers();
		$type = is_string( $_GET['nova_mapping_preview'] ) ? sanitize_key( wp_unslash( $_GET['nova_mapping_preview'] ) ) : '';
		$id = isset( $_GET['nova_reference'] ) && is_scalar( $_GET['nova_reference'] ) ? absint( $_GET['nova_reference'] ) : 0;
		$nonce = isset( $_GET['_nova_nonce'] ) && is_string( $_GET['_nova_nonce'] ) ? wp_unslash( $_GET['_nova_nonce'] ) : '';
		if ( ! in_array( $type, [ 'post', 'term' ], true ) || ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'nova_mapping_preview:' . $type . ':' . $id ) || ! Nova_Bridge_Suite_Strategy::entity( $type, $id ) ) {
			wp_die( 'This mapping preview has expired or is not available to your account.', 'Mapping preview', [ 'response' => 403 ] );
		}
		remove_action( 'template_redirect', 'redirect_canonical' );
		add_filter( 'show_admin_bar', '__return_false' );
	}
	public static function render(): void {
		if ( ! isset( $_GET['nova_mapping_preview'] ) ) { return; }
		$type = sanitize_key( wp_unslash( $_GET['nova_mapping_preview'] ) );
		$id = absint( $_GET['nova_reference'] );
		if ( get_queried_object_id() !== $id || ( 'post' === $type ? ! is_singular() : ! is_tax( 'product_cat' ) ) ) {
			wp_die( 'WordPress rendered a different reference. Open the correct page before mapping.', 'Mapping preview', [ 'response' => 409 ] );
		}
		nocache_headers();
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: no-referrer' );
		$script = NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_DIR . 'assets/mapping-preview.js';
		wp_enqueue_script( 'nova-mapping-preview', NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_URL . 'assets/mapping-preview.js', [], (string) filemtime( $script ), false );
		wp_add_inline_script( 'nova-mapping-preview', 'window.NovaMappingPreview=' . wp_json_encode( [ 'referenceId' => $id, 'referenceType' => $type, 'parentOrigin' => ( is_ssl() ? 'https://' : 'http://' ) . wp_parse_url( admin_url(), PHP_URL_HOST ) . ( wp_parse_url( admin_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( admin_url(), PHP_URL_PORT ) : '' ) ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}
}
