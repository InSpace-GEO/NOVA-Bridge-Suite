<?php
/**
 * Author archive template for quarantined CPT body clean plugin.
 *
 * @package SEORCPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
get_header();

$author              = get_queried_object();
$author_id           = $author instanceof \WP_User ? $author->ID : 0;
$display_name        = $author instanceof \WP_User ? \SEORAI\BodycleanCPT\Plugin::get_author_display_name( $author ) : get_the_archive_title();
$description         = $author instanceof \WP_User ? get_the_author_meta( 'description', $author_id ) : '';
$avatar_url          = $author instanceof \WP_User ? \SEORAI\BodycleanCPT\Plugin::get_author_avatar_url( $author, 192 ) : '';
$author_archive_name = \SEORAI\BodycleanCPT\Plugin::get_author_archive_title_text();
$show_breadcrumbs = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'breadcrumbs' );
$show_title       = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'title' );
$show_date        = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'date' );
$show_author      = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'author' );
$show_featured    = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'featured' );
$profile          = [
	'job_title'    => '',
	'organisation' => [ 'name' => '', 'url' => '' ],
	'location'     => '',
	'website'      => '',
	'social'       => [],
];

if ( $author instanceof \WP_User ) {
	$profile = \SEORAI\BodycleanCPT\Plugin::get_author_profile( $author );
}

$breadcrumbs = '';

if ( $show_breadcrumbs ) {
	$base_slug = \SEORAI\BodycleanCPT\Plugin::get_author_base_slug();
	$breadcrumbs = \SEORAI\BodycleanCPT\Plugin::render_breadcrumbs(
		[
			[
				'label' => __( 'Home', 'nova-bridge-suite' ),
				'url'   => home_url( '/' ),
			],
			[
				'label' => $author_archive_name,
				'url'   => home_url( '/' . $base_slug . '/' ),
			],
			[
				'label'   => $display_name,
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

		<div class="quarantined-cpt__author-header">
			<div class="quarantined-cpt__author-media">
				<?php if ( $avatar_url ) : ?>
					<img class="quarantined-cpt__author-avatar" src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $display_name ); ?>" />
				<?php endif; ?>
			</div>

			<div class="quarantined-cpt__author-meta">
				<?php if ( $show_title ) : ?>
					<h1 class="quarantined-cpt__title"><?php echo esc_html( $display_name ); ?></h1>
				<?php endif; ?>

				<?php
				$job_title     = isset( $profile['job_title'] ) ? (string) $profile['job_title'] : '';
				$organisation  = isset( $profile['organisation']['name'] ) ? (string) $profile['organisation']['name'] : '';
				$organisation_url = isset( $profile['organisation']['url'] ) ? (string) $profile['organisation']['url'] : '';

				$role_parts = [];

				if ( '' !== $job_title ) {
					$role_parts[] = esc_html( $job_title );
				}

				if ( '' !== $organisation ) {
					$org_markup = esc_html( $organisation );

					if ( '' !== $organisation_url ) {
						$org_markup = sprintf(
							'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
							esc_url( $organisation_url ),
							esc_html( $organisation )
						);
					}

					$role_parts[] = $org_markup;
				}

				if ( ! empty( $role_parts ) ) :
					?>
					<p class="quarantined-cpt__author-role">
						<?php
						echo wp_kses(
							implode( ' · ', $role_parts ),
							[
								'a' => [
									'href'   => [],
									'target' => [],
									'rel'    => [],
								],
							]
						);
						?>
					</p>
				<?php endif; ?>

				<?php
				$location = isset( $profile['location'] ) ? (string) $profile['location'] : '';

				if ( '' !== $location ) :
					?>
					<p class="quarantined-cpt__author-location"><?php echo esc_html( $location ); ?></p>
				<?php endif; ?>

				<?php if ( $description ) : ?>
					<div class="quarantined-cpt__author-description">
						<?php echo wp_kses_post( wpautop( $description ) ); ?>
					</div>
				<?php endif; ?>

				<?php
				$website = isset( $profile['website'] ) ? (string) $profile['website'] : '';
				$social  = [];

				if ( '' !== $website ) {
					$social[] = [
						'key'   => 'website',
						'label' => __( 'Website', 'nova-bridge-suite' ),
						'url'   => $website,
					];
				}

				if ( ! empty( $profile['social'] ) && is_array( $profile['social'] ) ) {
					foreach ( $profile['social'] as $item ) {
						if ( empty( $item['url'] ) ) {
							continue;
						}

						$social[] = [
							'key'   => isset( $item['key'] ) ? (string) $item['key'] : '',
							'label' => isset( $item['label'] ) ? (string) $item['label'] : '',
							'url'   => (string) $item['url'],
						];
					}
				}

					if ( ! empty( $social ) ) :
						?>
						<ul class="quarantined-cpt__author-social">
							<?php foreach ( $social as $link ) :
								if ( '' === $link['url'] ) {
									continue;
								}
								$item_key = $link['key'] ? sanitize_html_class( $link['key'] ) : 'generic';
								$label    = $link['label'] ? $link['label'] : $link['url'];
								?>
								<li class="quarantined-cpt__author-social-item quarantined-cpt__author-social-item--<?php echo esc_attr( $item_key ); ?>">
									<a
										href="<?php echo esc_url( $link['url'] ); ?>"
										target="_blank"
										rel="noopener noreferrer"
										aria-label="<?php echo esc_attr( $label ); ?>"
										title="<?php echo esc_attr( $label ); ?>"
									>
										<?php
										$icon_markup = \SEORAI\BodycleanCPT\Plugin::get_social_icon_markup( $item_key );
										if ( $icon_markup ) {
											echo $icon_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										}
										?>
										<span class="quarantined-cpt__sr-only"><?php echo esc_html( $label ); ?></span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
			</div>
		</div>

		<?php if ( have_posts() ) : ?>
			<div class="quarantined-cpt__grid">
				<?php
				while ( have_posts() ) :
					the_post();

						$card_date_readable = get_the_date( get_option( 'date_format' ) );
						$card_date_attr     = get_the_date( DATE_W3C );
						$summary            = \SEORAI\BodycleanCPT\Plugin::summary( 30 );

						if ( '' === $summary ) {
							$summary_source = get_the_excerpt();

							if ( '' === $summary_source ) {
								$summary_source = get_post_field( 'post_content', get_the_ID(), 'raw' );
							}

							$summary_source = wp_strip_all_tags( (string) $summary_source );

							if ( '' !== $summary_source ) {
								$summary = wp_html_excerpt( $summary_source, 30, '…' );
							}
						}

						$thumbnail = '';

						if ( $show_featured ) {
							$thumbnail = \SEORAI\BodycleanCPT\Plugin::thumbnail( 'quarantined-cpt-card' );

							if ( '' === $thumbnail && has_post_thumbnail() ) {
								$thumbnail = get_the_post_thumbnail(
									get_the_ID(),
									'quarantined-cpt-card',
									[ 'class' => 'quarantined-cpt__thumbnail' ]
								);
							}
						}
						?>
					<article <?php post_class( 'quarantined-cpt__card' ); ?>>
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
				<?php esc_html_e( 'Er zijn momenteel geen publicaties voor deze auteur.', 'nova-bridge-suite' ); ?>
			</p>
		<?php endif; ?>
	</div>
</main>
<?php

get_footer();
