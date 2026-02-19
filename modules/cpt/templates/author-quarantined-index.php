<?php
/**
 * Author listing template for quarantined CPT body clean plugin.
 *
 * @package SEORCPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
get_header();

$show_breadcrumbs = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'breadcrumbs' );
$show_title       = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'title' );
$show_author      = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'author' );
$show_featured    = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'featured' );
$archive_title    = \SEORAI\BodycleanCPT\Plugin::get_author_archive_title_text();
$managed_types    = array_filter( \SEORAI\BodycleanCPT\Plugin::get_post_types() );
$has_posts_types  = array_unique( array_merge( [ 'post' ], $managed_types ) );

$authors = get_users(
	[
		'capability'          => 'edit_posts',
		'orderby'            => 'display_name',
		'order'              => 'ASC',
		'has_published_posts' => $has_posts_types,
	]
);

$breadcrumbs = '';

if ( $show_breadcrumbs ) {
	$breadcrumbs = \SEORAI\BodycleanCPT\Plugin::render_breadcrumbs(
		[
			[
				'label' => __( 'Home', 'nova-bridge-suite' ),
				'url'   => home_url( '/' ),
			],
			[
				'label'   => $archive_title,
				'url'     => '',
				'current' => true,
			],
		]
	);
}
?>
<main id="quarantined-cpt-primary" class="quarantined-cpt" data-quarantined-keep="true">
	<div class="quarantined-cpt__inner">
		<?php if ( $breadcrumbs ) : ?>
			<?php echo $breadcrumbs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php endif; ?>

		<?php if ( $show_title ) : ?>
			<div class="quarantined-cpt__header" role="presentation">
				<h1 class="quarantined-cpt__title"><?php echo esc_html( $archive_title ); ?></h1>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $authors ) ) : ?>
			<div class="quarantined-cpt__grid">
				<?php foreach ( $authors as $author ) :
					if ( ! ( $author instanceof \WP_User ) ) {
						continue;
					}

					$author_name  = \SEORAI\BodycleanCPT\Plugin::get_author_display_name( $author );
					$author_url   = \SEORAI\BodycleanCPT\Plugin::author_archive_enabled() ? \SEORAI\BodycleanCPT\Plugin::get_author_permalink( $author ) : get_author_posts_url( $author->ID, $author->user_nicename );
					$description  = get_the_author_meta( 'description', $author->ID );
					$avatar_url   = \SEORAI\BodycleanCPT\Plugin::get_author_avatar_url( $author, 128 );
					$post_count   = count_user_posts( $author->ID, 'post', true );

					foreach ( $managed_types as $post_type ) {
						$post_count += count_user_posts( $author->ID, $post_type, true );
					}
					?>
					<article class="quarantined-cpt__card quarantined-cpt__card-author" data-quarantined-keep="true">
						<a class="quarantined-cpt__card-thumb" href="<?php echo esc_url( $author_url ); ?>">
							<img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $author_name ); ?>" />
						</a>
						<div class="quarantined-cpt__card-body">
							<h2 class="quarantined-cpt__card-title">
								<a href="<?php echo esc_url( $author_url ); ?>"><?php echo esc_html( $author_name ); ?></a>
							</h2>

							<?php if ( $description ) : ?>
								<p class="quarantined-cpt__card-summary"><?php echo wp_kses_post( wp_trim_words( $description, 24, '…' ) ); ?></p>
							<?php endif; ?>

							<div class="quarantined-cpt__meta quarantined-cpt__card-meta">
								<span class="quarantined-cpt__meta-item">
									<?php echo esc_html( \SEORAI\BodycleanCPT\Plugin::publications_label( $post_count, $author ) ); ?>
								</span>
							</div>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<p class="quarantined-cpt__empty"><?php esc_html_e( 'Er zijn momenteel geen auteurs beschikbaar.', 'nova-bridge-suite' ); ?></p>
		<?php endif; ?>
	</div>
</main>
<?php

get_footer();
