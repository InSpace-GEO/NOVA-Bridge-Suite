<?php
/**
 * One Service template, preserving the site's header, footer, and brand assets.
 *
 * @package NOVABridgeSuite
 */

defined( 'ABSPATH' ) || exit;

// Render first so installed shortcode plugins can enqueue their form/calendar
// assets before wp_head runs. The output remains native, crawlable page HTML.
$nova_service_html = '';
while ( have_posts() ) {
	the_post();
	$nova_service_html .= \SEORAI\ServicePageCPT\ServiceRenderer::render( (int) get_the_ID() );
}

$nova_service_header = locate_template( 'header.php', false, false );
if ( '' !== $nova_service_header && false === strpos( $nova_service_header, 'theme-compat/header.php' ) ) {
	get_header();
} else {
	?>
	<!DOCTYPE html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>" />
		<meta name="viewport" content="width=device-width, initial-scale=1" />
		<?php wp_head(); ?>
	</head>
	<body <?php body_class(); ?>>
	<?php
	wp_body_open();
	if ( function_exists( 'block_template_part' ) ) {
		block_template_part( 'header' );
	}
}
?>
<main id="nova-service-primary" class="nova-service-primary">
	<?php
	echo $nova_service_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</main>
<?php
$nova_service_footer = locate_template( 'footer.php', false, false );
if ( '' !== $nova_service_footer && false === strpos( $nova_service_footer, 'theme-compat/footer.php' ) ) {
	get_footer();
} else {
	if ( function_exists( 'block_template_part' ) ) {
		block_template_part( 'footer' );
	}
	wp_footer();
	?>
	</body>
	</html>
	<?php
}
