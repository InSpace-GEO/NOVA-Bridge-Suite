<?php
/** Language scope for native service defaults, without changing the plugin language. */
namespace SEORAI\ServicePageCPT;

defined( 'ABSPATH' ) || exit;

final class ServiceLanguages {
	private static function code( $value ): string {
		if ( ! \is_string( $value ) ) { return ''; }
		$value = \strtolower( \str_replace( '_', '-', \trim( $value ) ) );
		return \preg_match( '/^[a-z][a-z0-9-]{0,19}$/', $value ) && ! \in_array( $value, [ 'all', 'all-languages' ], true ) ? $value : '';
	}

	/** Ask the provider directly so a hidden settings form cannot redefine the default. */
	public static function default_language(): string {
		if ( \function_exists( 'pll_default_language' ) ) { $language = self::code( \pll_default_language( 'slug' ) ); if ( $language ) { return $language; } }
		if ( \function_exists( 'apply_filters' ) ) { $language = self::code( \apply_filters( 'wpml_default_language', null ) ); if ( $language ) { return $language; } }
		return '';
	}

	public static function context( ?string $language = null, int $post_id = 0 ): array {
		$source = \class_exists( '\\Nova_Bridge_Suite_WPML_Support' ) ? \Nova_Bridge_Suite_WPML_Support::get_settings_language_context() : null;
		$languages = [];
		foreach ( (array) ( $source['languages'] ?? [] ) as $key => $item ) {
			$code = self::code( \is_array( $item ) ? ( $item['code'] ?? $key ) : $key );
			if ( ! $code ) { continue; }
			$languages[ $code ] = [ 'code' => $code, 'label' => \is_array( $item ) ? (string) ( $item['label'] ?? \strtoupper( $code ) ) : \strtoupper( $code ) ];
		}
		$default = self::default_language();
		if ( $default && ! isset( $languages[ $default ] ) ) { $languages[ $default ] = [ 'code' => $default, 'label' => \strtoupper( $default ) ]; }
		$current = self::resolve_current( $post_id, $language, $source, $languages, $default );
		foreach ( $languages as &$item ) { $item['is_default'] = $item['code'] === $default; $item['is_current'] = $item['code'] === $current; } unset( $item );
		return [ 'active' => null !== $source, 'provider' => (string) ( $source['provider'] ?? '' ), 'current' => $current, 'default' => $default, 'is_localized' => '' !== $default && $current !== $default, 'languages' => \array_values( $languages ) ];
	}

	private static function resolve_current( int $post_id, ?string $requested, $source, array $languages, string $default ): string {
		if ( null !== $requested && '' !== $requested ) { $code = self::code( $requested ); return isset( $languages[ $code ] ) ? $code : $default; }
		// A REST/admin request can be in a different language from the target page.
		if ( $post_id > 0 ) {
			if ( \function_exists( 'pll_get_post_language' ) ) { $code = self::code( \pll_get_post_language( $post_id, 'slug' ) ); if ( isset( $languages[ $code ] ) ) { return $code; } }
			$details = \function_exists( 'apply_filters' ) ? \apply_filters( 'wpml_post_language_details', null, $post_id ) : null;
			$code = self::code( \is_array( $details ) ? ( $details['language_code'] ?? '' ) : '' );
			if ( isset( $languages[ $code ] ) ) { return $code; }
		}
		$code = self::code( $source['current'] ?? '' );
		return isset( $languages[ $code ] ) ? $code : $default;
	}

	public static function current( int $post_id = 0, ?string $requested = null ): string { return self::context( $requested, $post_id )['current']; }
	public static function is_localized( ?string $language = null ): bool { return self::context( $language )['is_localized']; }
	public static function is_translated( ?string $language = null ): bool { return self::is_localized( $language ); }

	/** Translate factory headings only, as the legacy service renderer already does. */
	public static function generic_section_title( string $type, string $title, int $post_id = 0, ?string $language = null ): string {
		if ( ! \in_array( $type, [ 'faq', 'related' ], true ) || '' === \trim( $title ) ) { return $title; }
		$scope = self::context( $language, $post_id );
		if ( ! $scope['active'] ) { return $title; }
		$labels = [
			'en' => [ 'faq' => 'FAQ', 'related' => 'Related services' ],
			'nl' => [ 'faq' => 'Veelgestelde vragen', 'related' => 'Andere relevante diensten' ],
			'de' => [ 'faq' => 'Häufig gestellte Fragen', 'related' => 'Weitere Leistungen' ],
			'fr' => [ 'faq' => 'Questions fréquentes', 'related' => 'Autres services' ],
			'es' => [ 'faq' => 'Preguntas frecuentes', 'related' => 'Otros servicios' ],
			'pt' => [ 'faq' => 'Perguntas frequentes', 'related' => 'Outros serviços' ],
			'it' => [ 'faq' => 'Domande frequenti', 'related' => 'Altri servizi' ],
			'pl' => [ 'faq' => 'Najczęściej zadawane pytania', 'related' => 'Inne usługi' ],
			'sv' => [ 'faq' => 'Vanliga frågor', 'related' => 'Andra tjänster' ],
			'da' => [ 'faq' => 'Ofte stillede spørgsmål', 'related' => 'Andre tjenester' ],
			'no' => [ 'faq' => 'Ofte stilte spørsmål', 'related' => 'Andre tjenester' ],
			'fi' => [ 'faq' => 'Usein kysytyt kysymykset', 'related' => 'Muut palvelut' ],
			'cs' => [ 'faq' => 'Často kladené otázky', 'related' => 'Další služby' ],
			'ro' => [ 'faq' => 'Întrebări frecvente', 'related' => 'Alte servicii' ],
			'tr' => [ 'faq' => 'Sık sorulan sorular', 'related' => 'Diğer hizmetler' ],
		];
		$prefix = \explode( '-', $scope['current'] )[0];
		if ( ! isset( $labels[ $prefix ] ) ) { return $title; }
		$aliases = \array_column( $labels, $type );
		if ( 'faq' === $type ) { $aliases[] = 'Frequently asked questions'; }
		else {
			// Old service templates also used the suite's article-heading labels.
			$aliases = \array_merge( $aliases, [ 'Other relevant services', 'Relevant services', 'Gerelateerde diensten', 'Relevante diensten', 'Related articles', 'Gerelateerde artikelen', 'Ähnliche Artikel', 'Articles associés', 'Artículos relacionados', 'Artigos relacionados', 'Articoli correlati', 'Powiązane artykuły', 'Relaterade artiklar', 'Relaterede artikler', 'Relaterte artikler', 'Aiheeseen liittyvät artikkelit', 'Související články', 'Articole similare', 'İlgili yazılar' ] );
		}
		$normalize = static function ( $value ) { return \strtolower( \trim( $value ) ); };
		return \in_array( $normalize( $title ), \array_map( $normalize, $aliases ), true ) ? $labels[ $prefix ][ $type ] : $title;
	}

	/** Only an enabled provider language may be written; invalid scopes never hit base. */
	public static function validate( ?string $language = null ) {
		$context = self::context();
		if ( null === $language || '' === $language ) { return $context['current']; }
		$code = self::code( $language );
		if ( $code && \in_array( $code, \array_column( $context['languages'], 'code' ), true ) ) { return $code; }
		return new \WP_Error( 'nova_service_invalid_language', 'Select an active site language before saving service defaults.', [ 'status' => 400 ] );
	}
}
