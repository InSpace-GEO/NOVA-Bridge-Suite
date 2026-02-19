<?php
/**
 * NOVA Bridge Suite module: WooCommerce category content.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
class WCCBP_Content_Below_Products {
	const META_KEY = 'content_below_products';
	const VERSION  = '1.0.3';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_term_meta' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_editor_assets' ) );
		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'render_add_field' ) );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'render_edit_field' ) );
		add_action( 'created_product_cat', array( __CLASS__, 'save_term_field' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'save_term_field' ) );

		add_action( 'woocommerce_after_shop_loop', array( __CLASS__, 'output_category_content' ), 20 );
		add_action( 'woocommerce_no_products_found', array( __CLASS__, 'output_category_content' ), 20 );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_styles' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_field' ) );
		add_filter( 'woocommerce_rest_prepare_product_cat', array( __CLASS__, 'add_field_to_wc_response' ), 10, 3 );
	}

	public static function register_term_meta() {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return;
		}

		register_term_meta(
			'product_cat',
			self::META_KEY,
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => true,
			)
		);
	}

	public static function enqueue_editor_assets( $hook ) {
		if ( ! in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
			return;
		}

		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'product_cat' === $taxonomy ) {
			wp_enqueue_editor();
		}
	}

	public static function enqueue_frontend_styles() {
		if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
			return;
		}

		$max_width = isset( $GLOBALS['content_width'] ) ? (int) $GLOBALS['content_width'] : 0;
		$max_width = (int) apply_filters( 'wccbp_content_max_width', $max_width );
		$fallback  = $max_width > 0 ? sprintf( '%dpx', $max_width ) : '100%';

		wp_register_style( 'wc-content-below-products', false, array(), self::VERSION );
		wp_enqueue_style( 'wc-content-below-products' );

		$css = sprintf(
			'.wc-content-below-products{box-sizing:border-box;margin-left:auto;margin-right:auto;max-width:var(--wccbp-products-width,%s);width:100%%;}',
			$fallback
		);

		wp_add_inline_style( 'wc-content-below-products', $css );

		wp_register_script( 'wc-content-below-products', '', array(), self::VERSION, true );
		wp_enqueue_script( 'wc-content-below-products' );

		$script = '(function(){var resizeTimer;function updateWidth(){var products=document.querySelector("ul.products");if(!products){return;}var width=Math.round(products.getBoundingClientRect().width);if(width<=0){return;}var blocks=document.querySelectorAll(".wc-content-below-products");for(var i=0;i<blocks.length;i++){blocks[i].style.setProperty("--wccbp-products-width",width+"px");}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",updateWidth);}else{updateWidth();}window.addEventListener("resize",function(){clearTimeout(resizeTimer);resizeTimer=setTimeout(updateWidth,150);});})();';

		wp_add_inline_script( 'wc-content-below-products', $script );
	}

	public static function render_add_field() {
		$editor_id = self::META_KEY . '_add';
		?>
		<div class="form-field term-<?php echo esc_attr( self::META_KEY ); ?>-wrap">
			<label for="<?php echo esc_attr( $editor_id ); ?>"><?php esc_html_e( 'Content below products', 'nova-bridge-suite' ); ?></label>
			<?php wp_nonce_field( 'wccbp_save_term', 'wccbp_nonce' ); ?>
			<?php
			wp_editor(
				'',
				$editor_id,
				array(
					'textarea_name' => self::META_KEY,
					'media_buttons' => true,
					'textarea_rows' => 8,
				)
			);
			?>
			<p class="description"><?php esc_html_e( 'Shown underneath the product grid on this category page.', 'nova-bridge-suite' ); ?></p>
		</div>
		<?php
	}

	public static function render_edit_field( $term ) {
		$editor_id = self::META_KEY . '_edit';
		$value     = self::get_term_content( $term->term_id );
		?>
		<tr class="form-field term-<?php echo esc_attr( self::META_KEY ); ?>-wrap">
			<th scope="row">
				<label for="<?php echo esc_attr( $editor_id ); ?>"><?php esc_html_e( 'Content below products', 'nova-bridge-suite' ); ?></label>
			</th>
			<td>
				<?php wp_nonce_field( 'wccbp_save_term', 'wccbp_nonce' ); ?>
				<?php
				wp_editor(
					$value,
					$editor_id,
					array(
						'textarea_name' => self::META_KEY,
						'media_buttons' => true,
						'textarea_rows' => 8,
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Shown underneath the product grid on this category page.', 'nova-bridge-suite' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public static function save_term_field( $term_id ) {
		if ( ! isset( $_POST['wccbp_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['wccbp_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'wccbp_save_term' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::META_KEY ] ) ) {
			return;
		}

		$value = wp_kses_post( wp_unslash( (string) $_POST[ self::META_KEY ] ) );

		update_term_meta( $term_id, self::META_KEY, $value );
	}

	public static function output_category_content() {
		if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
			return;
		}

		$term = get_queried_object();

		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		$content = self::get_term_content( $term->term_id );

		if ( '' === trim( (string) $content ) ) {
			return;
		}

		echo '<div class="wc-content-below-products">';
		echo apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- content is intentionally unsanitized per requirements.
		echo '</div>';
	}

	public static function register_rest_field() {
		register_rest_field(
			'product_cat',
			self::META_KEY,
			array(
				'get_callback'    => array( __CLASS__, 'rest_get_field' ),
				'update_callback' => array( __CLASS__, 'rest_update_field' ),
				'schema'          => array(
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'description' => 'Content displayed under the product list on category pages.',
				),
			)
		);
	}

	public static function rest_get_field( $object, $field_name = '', $request = null, $object_type = '' ) {
		$term_id = self::resolve_term_id( $object );

		if ( ! $term_id ) {
			return '';
		}

		return self::get_term_content( $term_id );
	}

	public static function rest_update_field( $value, $object, $field_name = '', $request = null, $object_type = '' ) {
		$term_id = self::resolve_term_id( $object );

		if ( ! $term_id ) {
			return;
		}

		update_term_meta( $term_id, self::META_KEY, wp_kses_post( wp_unslash( (string) $value ) ) );
	}

	public static function add_field_to_wc_response( $response, $item, $request ) {
		$response->data[ self::META_KEY ] = self::get_term_content( $item->term_id );

		return $response;
	}

	private static function get_term_content( $term_id ) {
		return get_term_meta( $term_id, self::META_KEY, true );
	}

	private static function resolve_term_id( $object ) {
		if ( is_object( $object ) && isset( $object->term_id ) ) {
			return (int) $object->term_id;
		}

		if ( is_array( $object ) && isset( $object['id'] ) ) {
			return (int) $object['id'];
		}

		if ( is_array( $object ) && isset( $object['term_id'] ) ) {
			return (int) $object['term_id'];
		}

		return 0;
	}

}

WCCBP_Content_Below_Products::init();
