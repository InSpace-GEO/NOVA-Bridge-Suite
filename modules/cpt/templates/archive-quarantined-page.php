<?php
/**
 * Archive template for quarantined custom post type.
 *
 * @package SEORCPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
get_header();

$archive_title        = post_type_archive_title( '', false );
$archive_description  = get_the_archive_description();
$show_breadcrumbs     = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'breadcrumbs' );
$show_title           = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'title' );
$show_date            = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'date' );
$show_author          = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'author' );
$show_featured        = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'featured' );

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
				<?php if ( $archive_description ) : ?>
					<div class="quarantined-cpt__description">
						<?php echo wp_kses_post( wpautop( $archive_description ) ); ?>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( have_posts() ) : ?>
			<div class="quarantined-cpt__grid">
				<?php
				while ( have_posts() ) :
					the_post();

                        $card_date_readable = get_the_date( get_option( 'date_format' ) );
                        $card_date_attr     = get_the_date( DATE_W3C );
                        $author_id          = (int) get_the_author_meta( 'ID' );
                        $author_user        = $author_id ? get_userdata( $author_id ) : null;
                        $author_name        = $author_user instanceof \WP_User ? \SEORAI\BodycleanCPT\Plugin::get_author_display_name( $author_user ) : get_the_author();
                        $author_url         = ( $author_user instanceof \WP_User && \SEORAI\BodycleanCPT\Plugin::author_archive_enabled() ) ? \SEORAI\BodycleanCPT\Plugin::get_author_permalink( $author_user ) : ( $author_id ? get_author_posts_url( $author_id ) : '' );
                        $summary            = \SEORAI\BodycleanCPT\Plugin::summary( 30 );
                        $thumbnail          = $show_featured ? \SEORAI\BodycleanCPT\Plugin::thumbnail( 'quarantined-cpt-card' ) : '';
					?>
					<article <?php post_class( 'quarantined-cpt__card' ); ?> data-quarantined-keep="true">
						<a class="quarantined-cpt__card-thumb" href="<?php the_permalink(); ?>">
							<?php
							if ( $thumbnail ) {
								echo wp_kses_post( $thumbnail );
							} else {
								echo wp_kses_post( \SEORAI\BodycleanCPT\Plugin::placeholder( 'card-thumb' ) );
							}
							?>
						</a>

						<div class="quarantined-cpt__card-body">
							<h2 class="quarantined-cpt__card-title">
								<a href="<?php the_permalink(); ?>">
									<?php echo esc_html( get_the_title() ); ?>
								</a>
							</h2>

							<?php if ( $summary ) : ?>
								<p class="quarantined-cpt__card-summary"><?php echo esc_html( $summary ); ?></p>
							<?php endif; ?>

                                <div class="quarantined-cpt__meta quarantined-cpt__card-meta">
                                    <?php if ( $show_date && $card_date_readable ) : ?>
                                        <span class="quarantined-cpt__meta-item quarantined-cpt__meta-item--date">
                                            <time datetime="<?php echo esc_attr( $card_date_attr ); ?>">
                                                <?php echo esc_html( $card_date_readable ); ?>
                                            </time>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ( $show_date && $card_date_readable && $show_author && $author_name ) : ?>
                                        <span class="quarantined-cpt__meta-separator">•</span>
                                    <?php endif; ?>

						<?php if ( $show_author && $author_name ) : ?>
							<span class="quarantined-cpt__meta-item quarantined-cpt__meta-item--author">
								<?php echo esc_html( \SEORAI\BodycleanCPT\Plugin::author_label_text() ); ?>
								<?php if ( $author_url ) : ?>
									<a href="<?php echo esc_url( $author_url ); ?>">
										<?php echo esc_html( $author_name ); ?>
									</a>
								<?php else : ?>
									<?php echo esc_html( $author_name ); ?>
								<?php endif; ?>
							</span>
						<?php endif; ?>
					</div>
						</div>
					</article>
				<?php endwhile; ?>
			</div>

			<?php
			the_posts_pagination(
				[
					'mid_size'  => 2,
					'prev_text' => esc_html__( 'Vorige', 'nova-bridge-suite' ),
					'next_text' => esc_html__( 'Volgende', 'nova-bridge-suite' ),
				]
			);
			?>
		<?php else : ?>
			<p class="quarantined-cpt__empty">
				<?php esc_html_e( 'Er zijn momenteel geen quarantined pagina\'s beschikbaar.', 'nova-bridge-suite' ); ?>
			</p>
		<?php endif; ?>
	</div>
</main>
<?php

get_footer();
