<?php
/**
 * Single template for quarantined custom post type.
 *
 * @package SEORCPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
get_header();
?>
<main id="quarantined-cpt-primary" class="quarantined-cpt" data-quarantined-keep="true">
	<div class="quarantined-cpt__inner">
		<?php
		while ( have_posts() ) :
			the_post();
			$show_breadcrumbs        = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'breadcrumbs' );
			$show_title              = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'title' );
			$show_date               = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'date' );
			$show_author             = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'author' );
			$show_featured           = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'featured' );
			$breadcrumbs             = $show_breadcrumbs ? \SEORAI\BodycleanCPT\Plugin::breadcrumbs() : '';
			$published_date_readable = get_the_date( get_option( 'date_format' ) );
			$published_date_attr     = get_the_date( DATE_W3C );
			$author_id               = (int) get_the_author_meta( 'ID' );
			$author_user             = $author_id ? get_userdata( $author_id ) : null;
			$author_name             = $author_user instanceof \WP_User ? \SEORAI\BodycleanCPT\Plugin::get_author_display_name( $author_user ) : get_the_author();
			$author_url              = ( $author_user instanceof \WP_User && \SEORAI\BodycleanCPT\Plugin::author_archive_enabled() ) ? \SEORAI\BodycleanCPT\Plugin::get_author_permalink( $author_user ) : ( $author_id ? get_author_posts_url( $author_id ) : '' );
			?>
			<article id="post-<?php the_ID(); ?>" class="quarantined-cpt__entry" data-quarantined-keep="true">
				<?php if ( $breadcrumbs ) : ?>
					<?php echo $breadcrumbs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>

				<?php if ( $show_title ) : ?>
					<div class="quarantined-cpt__header" role="presentation">
						<h1 class="quarantined-cpt__title"><?php echo esc_html( get_the_title() ); ?></h1>
					</div>
				<?php endif; ?>

				<?php if ( ( $show_date && $published_date_readable ) || ( $show_author && $author_name ) ) : ?>
					<div class="quarantined-cpt__meta">
						<?php if ( $show_date && $published_date_readable ) : ?>
							<span class="quarantined-cpt__meta-item quarantined-cpt__meta-item--date">
								<time datetime="<?php echo esc_attr( $published_date_attr ); ?>">
									<?php echo esc_html( $published_date_readable ); ?>
								</time>
							</span>
						<?php endif; ?>

						<?php if ( $show_date && $published_date_readable && $show_author && $author_name ) : ?>
							<span class="quarantined-cpt__meta-separator">•</span>
						<?php endif; ?>

						<?php if ( $show_author && $author_name ) : ?>
							<span class="quarantined-cpt__meta-item quarantined-cpt__meta-item--author">
								<?php echo esc_html( \SEORAI\BodycleanCPT\Plugin::author_label_text() ); ?>
								<?php if ( $author_url && \SEORAI\BodycleanCPT\Plugin::author_archive_enabled() ) : ?>
									<a href="<?php echo esc_url( $author_url ); ?>">
										<?php echo esc_html( $author_name ); ?>
									</a>
								<?php else : ?>
									<?php echo esc_html( $author_name ); ?>
								<?php endif; ?>
							</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php
				$thumbnail = $show_featured ? \SEORAI\BodycleanCPT\Plugin::thumbnail( 'quarantined-cpt-hero' ) : '';

				if ( $thumbnail ) :
					?>
					<div class="quarantined-cpt__media" data-quarantined-keep="true">
						<?php echo wp_kses_post( $thumbnail ); ?>
					</div>
				<?php endif; ?>

				<div class="quarantined-cpt__content" data-quarantined-keep="true">
					<?php echo \SEORAI\BodycleanCPT\Plugin::content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</article>
		<?php endwhile; ?>
	</div>
</main>
<?php

get_footer();
