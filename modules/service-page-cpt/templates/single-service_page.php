<?php
/**
 * Single template for service pages.
 *
 * @package ServiceCPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
$header_template = locate_template( 'header.php', false, false );
if ( '' !== $header_template && false === strpos( $header_template, 'theme-compat/header.php' ) ) {
	get_header();
} else {
	?>
	<!DOCTYPE html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="<?php bloginfo( 'charset' ); ?>" />
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
<main id="service-cpt-primary" class="service-cpt">
	<?php
	while ( have_posts() ) :
		the_post();
		echo \SEORAI\ServicePageCPT\Plugin::render_page( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	endwhile;
	?>
</main>
<?php
$footer_template = locate_template( 'footer.php', false, false );
if ( '' !== $footer_template && false === strpos( $footer_template, 'theme-compat/footer.php' ) ) {
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
