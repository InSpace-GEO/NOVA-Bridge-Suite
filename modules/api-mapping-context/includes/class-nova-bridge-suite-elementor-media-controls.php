<?php
/** Image destinations certified by Elementor's registered native control schema. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Elementor_Media_Controls {
    private static function pointer( array $parts ): string {
        return '/' . implode( '/', array_map( static function ( $part ) { return str_replace( [ '~', '/' ], [ '~0', '~1' ], (string) $part ); }, $parts ) );
    }
    private static function controls( array $node ): array {
        if ( ! class_exists( '\Elementor\Plugin' ) ) { return []; }
        try {
            $plugin = \Elementor\Plugin::instance();
            if ( ! \SEOR_Elementor_Bridge\Element_Registry::available( $plugin ) ) { return []; }
            $registered = 'widget' === ( $node['elType'] ?? '' )
                ? $plugin->widgets_manager->get_widget_types( $node['widgetType'] ?? '' )
                : $plugin->elements_manager->get_element_types( $node['elType'] ?? '' );
            if ( ! is_object( $registered ) || ! is_callable( [ $registered, 'get_controls' ] ) ) { return []; }
            $controls = $registered->get_controls();
            return is_array( $controls ) ? $controls : [];
        } catch ( Throwable $error ) { return []; }
    }
    private static function devices(): array {
        try {
            $plugin = \Elementor\Plugin::instance();
            if ( ! isset( $plugin->breakpoints ) || ! is_callable( [ $plugin->breakpoints, 'get_active_devices_list' ] ) ) { return []; }
            $devices = $plugin->breakpoints->get_active_devices_list();
            return is_array( $devices ) ? array_values( array_filter( $devices, static function ( $device ) { return is_string( $device ) && 'desktop' !== $device && preg_match( '/^[a-z0-9_]+$/D', $device ); } ) ) : [];
        } catch ( Throwable $error ) { return []; }
    }
    private static function walk( array $controls, array $settings, array $parts, array &$result, bool $dynamic = false, int $depth = 0 ): void {
        if ( $depth > 16 ) { return; }
        foreach ( $controls as $key => $control ) {
            if ( ! is_array( $control ) ) { continue; }
            $name = is_string( $key ) ? $key : ( $control['name'] ?? '' );
            if ( ! is_string( $name ) || '' === $name ) { continue; }
            $names = [ $name ];
            // Elementor may register one responsive control and create its device controls in JS.
            if ( ! empty( $control['is_responsive'] ) ) {
                $devices = self::devices();
                if ( isset( $control['responsive']['devices'] ) && is_array( $control['responsive']['devices'] ) ) { $devices = array_intersect( $devices, $control['responsive']['devices'] ); }
                foreach ( $devices as $device ) { if ( ! isset( $controls[$name . '_' . $device] ) ) { $names[] = $name . '_' . $device; } }
            }
            foreach ( $names as $native_name ) {
                if ( ! array_key_exists( $native_name, $settings ) ) { continue; }
                $value = $settings[$native_name]; $path = array_merge( $parts, [ $native_name ] );
                $protected = $dynamic || isset( $settings['__dynamic__'][$native_name] ) || isset( $settings['__globals__'][$native_name] ) || ( $native_name !== $name && ( isset( $settings['__dynamic__'][$name] ) || isset( $settings['__globals__'][$name] ) ) );
                if ( 'media' === ( $control['type'] ?? '' ) ) {
                    // MEDIA is an image control by default. Do not reinterpret file/video controls.
                    if ( isset( $control['media_types'] ) && ( ! is_array( $control['media_types'] ) || ! in_array( 'image', $control['media_types'], true ) ) ) { continue; }
                    if ( ! is_array( $value ) || ! is_string( $value['url'] ?? null ) ) { continue; }
                    $url_path = array_merge( $path, [ 'url' ] );
                    $label = is_string( $control['label'] ?? null ) && '' !== trim( $control['label'] ) ? strip_tags( $control['label'] ) : ucwords( str_replace( '_', ' ', $name ) );
                    if ( $native_name !== $name ) { $label .= ' · ' . ucwords( str_replace( '_', ' ', substr( $native_name, strlen( $name ) + 1 ) ) ); }
                    $result[self::pointer( $url_path )] = [ 'url_path' => $url_path, 'id_path' => array_merge( $path, [ 'id' ] ), 'label' => $label, 'protected' => $protected ];
                } elseif ( 'repeater' === ( $control['type'] ?? '' ) && is_array( $control['fields'] ?? null ) && is_array( $value ) && Nova_Bridge_Suite_Recipe_Engine::list_array( $value ) ) {
                    foreach ( $value as $index => $row ) { if ( is_array( $row ) ) { self::walk( $control['fields'], $row, array_merge( $path, [ $index ] ), $result, $protected, $depth + 1 ); } }
                }
            }
        }
    }
    /** Keys are settings-relative JSON pointers; only existing native media values qualify. */
    public static function inventory( array $node ): array {
        $settings = $node['settings'] ?? []; if ( ! is_array( $settings ) ) { return []; }
        $result = []; self::walk( self::controls( $node ), $settings, [], $result );
        // Keep mappings made before registered-control discovery compatible with pure/local compilation.
        if ( 'widget' === ( $node['elType'] ?? '' ) && 'image' === ( $node['widgetType'] ?? '' ) && is_string( $settings['image']['url'] ?? null ) && ! isset( $result['/image/url'] ) ) {
            $result['/image/url'] = [ 'url_path' => [ 'image', 'url' ], 'id_path' => [ 'image', 'id' ], 'label' => 'Image', 'protected' => isset( $settings['__dynamic__']['image'] ) || isset( $settings['__globals__']['image'] ) ];
        }
        return $result;
    }
    public static function descriptor( array $node, array $path ): ?array { return self::inventory( $node )[self::pointer( $path )] ?? null; }
}
