<?php
/**
 * Compatibility with Elementor's public element and widget registries.
 *
 * @package SEOR_Elementor_Bridge
 */

namespace SEOR_Elementor_Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Element_Registry {
	/** Whether both native registries expose their supported lookup APIs. */
	public static function available( $plugin ): bool {
		return is_object( $plugin )
			&& isset( $plugin->elements_manager, $plugin->widgets_manager )
			&& is_callable( array( $plugin->elements_manager, 'get_element_types' ) )
			&& is_callable( array( $plugin->widgets_manager, 'get_widget_types' ) );
	}

	/** Resolve registered core, Pro and third-party elements without an allowlist. */
	public static function contains( $plugin, $element_type, $widget_type = null ): bool {
		if ( ! self::available( $plugin ) || ! is_string( $element_type ) || '' === $element_type ) {
			return false;
		}

		// Older Elementor releases lack get_element(); these are its underlying public APIs.
		if ( 'widget' === $element_type ) {
			return is_string( $widget_type ) && '' !== $widget_type
				&& (bool) $plugin->widgets_manager->get_widget_types( $widget_type );
		}

		return (bool) $plugin->elements_manager->get_element_types( $element_type );
	}
}
