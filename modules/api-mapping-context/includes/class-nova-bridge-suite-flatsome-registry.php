<?php
/** Load native UX Builder control definitions on ordinary REST/worker requests. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Flatsome_Registry {
    private static $registry = null;
    private static bool $attempted = false;

    /**
     * Flatsome stops its normal bootstrap before registry helpers when the UX
     * editor is inactive. Initialize the real application in its non-editor
     * mode; never change request flags or substitute a native singleton.
     */
    public static function get() {
        if ( is_object( self::$registry ) ) { return self::$registry; }
        if ( self::$attempted || ! defined( 'UX_BUILDER_VERSION' ) ) { return null; }
        try {
            // Preserve a registry already loaded by the normal UX editor.
            if ( function_exists( 'ux_builder_shortcodes' ) ) {
                $registry = ux_builder_shortcodes();
                if ( is_object( $registry ) && is_callable( [ $registry, 'get' ] ) ) { return self::$registry = $registry; }
            }
            if ( ! function_exists( 'ux_builder_is_active' ) || ux_builder_is_active() || ! function_exists( 'ux_builder' ) || ! did_action( 'init' ) ) { return null; }
            $core = realpath( get_template_directory() . '/inc/builder/core' );
            if ( ! $core || ! defined( 'UX_BUILDER_PATH' ) || $core !== realpath( UX_BUILDER_PATH ) ) { return null; }
            $files = [];
            foreach ( [ 'components', 'shortcodes', 'templates', 'misc', 'modules', 'options', 'page', 'paths', 'strings', 'templating', 'transformers' ] as $helper ) { $files[] = '/server/helpers/' . $helper . '.php'; }
            $files = array_merge( $files, [ '/server/filters/filters.php', '/server/filters/post-options.php', '/server/filters/meta-options.php', '/server/src/Application.php', '/shortcodes/shortcodes.php', '/components/components.php', '/server/setup.php' ] );
            foreach ( $files as $file ) { if ( ! is_file( $core . $file ) ) { return null; } }
            self::$attempted = true;

            // Scope loading to native UX Builder classes in the active theme.
            // Handler constructors only register hooks; no AJAX action runs.
            spl_autoload_register( static function ( string $class ) use ( $core ): void {
                if ( 0 !== strpos( $class, 'UxBuilder\\' ) || ! preg_match( '/^UxBuilder\\\\[A-Za-z0-9_\\\\]+$/D', $class ) ) { return; }
                $relative = str_replace( [ '\\', '_' ], '/', substr( $class, strlen( 'UxBuilder\\' ) ) );
                $file = $core . '/server/src/' . $relative . '.php';
                if ( is_file( $file ) ) { require_once $file; }
            } );
            foreach ( $files as $file ) { require_once $core . $file; }
            $application = \UxBuilder\Application::get_instance();
            // init already ran on these endpoints. initialize() publishes the
            // normal definition-registration hook and returns before editor
            // post loading because the original request remains non-editor.
            if ( ! did_action( 'ux_builder_setup' ) ) { $application->initialize(); }
            $registry = ux_builder_shortcodes();
            if ( is_object( $registry ) && is_callable( [ $registry, 'get' ] ) ) { self::$registry = $registry; }
        } catch ( Throwable $error ) {
            // Missing/incompatible native files make the provider unavailable.
            self::$registry = null;
        }
        return self::$registry;
    }
}
