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
$archive_post_type    = get_query_var( 'post_type', '' );

if ( is_array( $archive_post_type ) ) {
	$archive_post_type = reset( $archive_post_type );
}

if ( ! is_string( $archive_post_type ) || '' === $archive_post_type ) {
	$queried_object = get_queried_object();

	if ( $queried_object instanceof \WP_Post_Type ) {
		$archive_post_type = (string) $queried_object->name;
	}
}

$archive_post_type = sanitize_key( (string) $archive_post_type );
$archive_layout    = \SEORAI\BodycleanCPT\Plugin::get_archive_layout_data( $archive_post_type );
$archive_intro     = isset( $archive_layout['intro'] ) ? (string) $archive_layout['intro'] : '';
$archive_intro_has_text = '' !== trim( wp_strip_all_tags( $archive_intro ) );
$archive_cta_before = isset( $archive_layout['cta_before'] ) && is_array( $archive_layout['cta_before'] ) ? $archive_layout['cta_before'] : [];
$archive_cta_after  = isset( $archive_layout['cta_after'] ) && is_array( $archive_layout['cta_after'] ) ? $archive_layout['cta_after'] : ( isset( $archive_layout['cta'] ) && is_array( $archive_layout['cta'] ) ? $archive_layout['cta'] : [] );
$archive_cta_before_active = ! empty( $archive_cta_before['active'] );
$archive_cta_before_title = isset( $archive_cta_before['title'] ) ? (string) $archive_cta_before['title'] : '';
$archive_cta_before_copy  = isset( $archive_cta_before['copy'] ) ? (string) $archive_cta_before['copy'] : '';
$archive_cta_before_button_label = isset( $archive_cta_before['button_label'] ) ? (string) $archive_cta_before['button_label'] : '';
$archive_cta_before_button_url   = isset( $archive_cta_before['button_url'] ) ? (string) $archive_cta_before['button_url'] : '';
$archive_cta_after_active = ! empty( $archive_cta_after['active'] );
$archive_cta_after_title = isset( $archive_cta_after['title'] ) ? (string) $archive_cta_after['title'] : '';
$archive_cta_after_copy  = isset( $archive_cta_after['copy'] ) ? (string) $archive_cta_after['copy'] : '';
$archive_cta_after_button_label = isset( $archive_cta_after['button_label'] ) ? (string) $archive_cta_after['button_label'] : '';
$archive_cta_after_button_url   = isset( $archive_cta_after['button_url'] ) ? (string) $archive_cta_after['button_url'] : '';
$archive_bottom_content   = isset( $archive_layout['content_after_cta'] ) ? (string) $archive_layout['content_after_cta'] : '';
$archive_bottom_has_text  = '' !== trim( wp_strip_all_tags( $archive_bottom_content ) );
$show_breadcrumbs     = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'breadcrumbs', $archive_post_type );
$show_title           = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'title', $archive_post_type );
$show_date            = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'date', $archive_post_type );
$show_author          = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'author', $archive_post_type );
$show_featured        = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'featured', $archive_post_type );

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
				<?php if ( $archive_intro_has_text ) : ?>
					<div class="quarantined-cpt__description quarantined-cpt__description--archive-intro">
						<?php echo wp_kses_post( $archive_intro ); ?>
					</div>
				<?php endif; ?>
			</div>
		<?php elseif ( $archive_intro_has_text ) : ?>
			<div class="quarantined-cpt__archive-intro">
				<?php echo wp_kses_post( $archive_intro ); ?>
			</div>
		<?php endif; ?>

		<?php if ( have_posts() ) : ?>
			<?php if ( $archive_cta_before_active ) : ?>
				<section class="quarantined-cpt__wide-cta quarantined-cpt__wide-cta--archive-before" data-quarantined-keep="true">
					<div class="quarantined-cpt__wide-cta-inner">
						<?php if ( '' !== trim( $archive_cta_before_title ) ) : ?>
							<h2 class="quarantined-cpt__wide-cta-title"><?php echo esc_html( $archive_cta_before_title ); ?></h2>
						<?php endif; ?>

						<?php if ( '' !== trim( wp_strip_all_tags( $archive_cta_before_copy ) ) ) : ?>
							<div class="quarantined-cpt__wide-cta-copy">
								<?php echo wp_kses_post( $archive_cta_before_copy ); ?>
							</div>
						<?php endif; ?>

						<?php if ( '' !== trim( $archive_cta_before_button_label ) && '' !== trim( $archive_cta_before_button_url ) ) : ?>
							<p class="quarantined-cpt__wide-cta-actions">
								<a class="quarantined-cpt__wide-cta-button" href="<?php echo esc_url( $archive_cta_before_button_url ); ?>">
									<?php echo esc_html( $archive_cta_before_button_label ); ?>
								</a>
							</p>
						<?php endif; ?>
					</div>
				</section>
			<?php endif; ?>

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
					$summary_length     = \SEORAI\BodycleanCPT\Plugin::is_legacy_blog_post( (int) get_the_ID() ) ? 30 : 120;
					$summary            = \SEORAI\BodycleanCPT\Plugin::summary( $summary_length );
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
			$archive_pagination = \SEORAI\BodycleanCPT\Plugin::archive_pagination(
				[
					'mid_size'  => 2,
					'prev_text' => esc_html__( 'Vorige', 'nova-bridge-suite' ),
					'next_text' => esc_html__( 'Volgende', 'nova-bridge-suite' ),
				]
			);

			if ( '' !== trim( $archive_pagination ) ) {
				echo $archive_pagination; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>

			<?php if ( $archive_cta_after_active ) : ?>
				<section class="quarantined-cpt__wide-cta quarantined-cpt__wide-cta--archive" data-quarantined-keep="true">
					<div class="quarantined-cpt__wide-cta-inner">
						<?php if ( '' !== trim( $archive_cta_after_title ) ) : ?>
							<h2 class="quarantined-cpt__wide-cta-title"><?php echo esc_html( $archive_cta_after_title ); ?></h2>
						<?php endif; ?>

						<?php if ( '' !== trim( wp_strip_all_tags( $archive_cta_after_copy ) ) ) : ?>
							<div class="quarantined-cpt__wide-cta-copy">
								<?php echo wp_kses_post( $archive_cta_after_copy ); ?>
							</div>
						<?php endif; ?>

						<?php if ( '' !== trim( $archive_cta_after_button_label ) && '' !== trim( $archive_cta_after_button_url ) ) : ?>
							<p class="quarantined-cpt__wide-cta-actions">
								<a class="quarantined-cpt__wide-cta-button" href="<?php echo esc_url( $archive_cta_after_button_url ); ?>">
									<?php echo esc_html( $archive_cta_after_button_label ); ?>
								</a>
							</p>
						<?php endif; ?>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( $archive_bottom_has_text ) : ?>
				<section class="quarantined-cpt__content quarantined-cpt__content--archive-bottom" data-quarantined-keep="true">
					<?php echo wp_kses_post( $archive_bottom_content ); ?>
				</section>
			<?php endif; ?>
		<?php else : ?>
			<p class="quarantined-cpt__empty">
				<?php esc_html_e( 'Er zijn momenteel geen quarantined pagina\'s beschikbaar.', 'nova-bridge-suite' ); ?>
			</p>
		<?php endif; ?>
	</div>
</main>
<?php

get_footer();
