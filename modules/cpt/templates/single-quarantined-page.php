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
			$post_id                 = (int) get_the_ID();
			$show_breadcrumbs        = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'breadcrumbs' );
			$show_title              = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'title' );
			$show_date               = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'date' );
			$show_author             = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'author' );
			$show_featured           = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'featured' );
			$show_read_time          = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'read_time' );
			$show_share_links        = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'share_links' );
				$show_intro              = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'intro' );
				$show_key_takeaways      = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'key_takeaways' );
				$show_toc                = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'toc' );
				$show_content_1          = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'content_1' );
				$show_wide_cta           = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'wide_cta' );
				$show_content_2          = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'content_2' );
				$show_faq                = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'faq' );
				$show_related            = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'related' );
				$show_wide_cta_after_related = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'wide_cta_after_related' );
				$show_author_box         = \SEORAI\BodycleanCPT\Plugin::component_enabled( 'author_box' );
			$breadcrumbs             = $show_breadcrumbs ? \SEORAI\BodycleanCPT\Plugin::breadcrumbs() : '';
			$published_date_readable = get_the_date( get_option( 'date_format' ) );
			$published_date_attr     = get_the_date( DATE_W3C );
			$author_id               = (int) get_the_author_meta( 'ID' );
			$author_user             = $author_id ? get_userdata( $author_id ) : null;
			$author_name             = $author_user instanceof \WP_User ? \SEORAI\BodycleanCPT\Plugin::get_author_display_name( $author_user ) : get_the_author();
			$author_url              = ( $author_user instanceof \WP_User && \SEORAI\BodycleanCPT\Plugin::author_archive_enabled() ) ? \SEORAI\BodycleanCPT\Plugin::get_author_permalink( $author_user ) : ( $author_id ? get_author_posts_url( $author_id ) : '' );
			$author_avatar_small     = $author_user instanceof \WP_User ? \SEORAI\BodycleanCPT\Plugin::get_author_avatar_url( $author_user, 56 ) : '';
			$author_avatar_large     = $author_user instanceof \WP_User ? \SEORAI\BodycleanCPT\Plugin::get_author_avatar_url( $author_user, 192 ) : '';
			$author_profile          = $author_user instanceof \WP_User ? \SEORAI\BodycleanCPT\Plugin::get_author_profile( $author_user ) : [];
			$author_description      = $author_user instanceof \WP_User ? (string) get_the_author_meta( 'description', $author_user->ID ) : '';
			$layout                  = \SEORAI\BodycleanCPT\Plugin::get_blog_layout_data( $post_id );
			$intro_html              = isset( $layout['intro'] ) ? (string) $layout['intro'] : '';
				$key_takeaways           = isset( $layout['key_takeaways'] ) && is_array( $layout['key_takeaways'] ) ? $layout['key_takeaways'] : [];
				$toc_items               = isset( $layout['toc'] ) && is_array( $layout['toc'] ) ? $layout['toc'] : [];
				$part_one_html           = isset( $layout['part_1'] ) ? (string) $layout['part_1'] : \SEORAI\BodycleanCPT\Plugin::content();
				$part_two_html           = isset( $layout['part_2'] ) ? (string) $layout['part_2'] : '';
				$faq_items               = isset( $layout['faqs'] ) && is_array( $layout['faqs'] ) ? $layout['faqs'] : [];
				$cta                     = isset( $layout['cta'] ) && is_array( $layout['cta'] ) ? $layout['cta'] : [];
				$cta_is_active           = ! empty( $cta['active'] );
				$cta_title               = isset( $cta['title'] ) ? (string) $cta['title'] : '';
				$cta_copy                = isset( $cta['copy'] ) ? (string) $cta['copy'] : '';
				$cta_button_label        = isset( $cta['button_label'] ) ? (string) $cta['button_label'] : '';
				$cta_button_url          = isset( $cta['button_url'] ) ? (string) $cta['button_url'] : '';
				$cta_after_related       = isset( $layout['cta_after_related'] ) && is_array( $layout['cta_after_related'] ) ? $layout['cta_after_related'] : [];
				$cta_after_related_active = ! empty( $cta_after_related['active'] );
				$cta_after_related_title = isset( $cta_after_related['title'] ) ? (string) $cta_after_related['title'] : '';
				$cta_after_related_copy  = isset( $cta_after_related['copy'] ) ? (string) $cta_after_related['copy'] : '';
				$cta_after_related_button_label = isset( $cta_after_related['button_label'] ) ? (string) $cta_after_related['button_label'] : '';
				$cta_after_related_button_url = isset( $cta_after_related['button_url'] ) ? (string) $cta_after_related['button_url'] : '';
				$related_posts           = isset( $layout['related_posts'] ) && is_array( $layout['related_posts'] ) ? $layout['related_posts'] : [];
			$share_links             = $show_share_links ? \SEORAI\BodycleanCPT\Plugin::get_blog_share_links( $post_id ) : [];
			$read_time_minutes       = max( 1, \SEORAI\BodycleanCPT\Plugin::get_estimated_read_time( $post_id ) );
			$read_time_label         = \SEORAI\BodycleanCPT\Plugin::read_time_label_text( $read_time_minutes );
			$thumbnail               = $show_featured ? \SEORAI\BodycleanCPT\Plugin::thumbnail( 'quarantined-cpt-hero' ) : '';
			$author_job_title        = isset( $author_profile['job_title'] ) ? (string) $author_profile['job_title'] : '';
			$author_org_name         = isset( $author_profile['organisation']['name'] ) ? (string) $author_profile['organisation']['name'] : '';
			$author_org_url          = isset( $author_profile['organisation']['url'] ) ? (string) $author_profile['organisation']['url'] : '';
			$key_takeaways_label     = \SEORAI\BodycleanCPT\Plugin::key_takeaways_label_text();
			$toc_label               = \SEORAI\BodycleanCPT\Plugin::toc_label_text();
			$toc_read_more_label     = \SEORAI\BodycleanCPT\Plugin::toc_read_more_label_text();
			$toc_read_less_label     = \SEORAI\BodycleanCPT\Plugin::toc_read_less_label_text();
			$faq_title               = \SEORAI\BodycleanCPT\Plugin::faq_title_text();
			$related_articles_title  = \SEORAI\BodycleanCPT\Plugin::related_articles_label_text();
			$author_role_parts       = [];
			$has_toc                 = $show_toc && ! empty( $toc_items );
			$has_intro               = $show_intro && '' !== trim( wp_strip_all_tags( $intro_html ) );
			$has_part_one_content    = $show_content_1 && '' !== trim( wp_strip_all_tags( $part_one_html ) );
			$has_part_two_content    = $show_content_2 && '' !== trim( wp_strip_all_tags( $part_two_html ) );
				$has_meta_primary        = ( $show_author && '' !== trim( $author_name ) )
					|| $show_read_time
					|| ( $show_date && '' !== trim( (string) $published_date_readable ) );
				$has_meta_row            = $has_meta_primary || ! empty( $share_links );
				$has_pre_cta_flow        = ( $show_key_takeaways && ! empty( $key_takeaways ) ) || $has_toc || $has_part_one_content;
				$use_legacy_layout       = \SEORAI\BodycleanCPT\Plugin::is_legacy_blog_post( $post_id );

				if ( $use_legacy_layout ) :
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

						<?php if ( $thumbnail ) : ?>
							<div class="quarantined-cpt__media" data-quarantined-keep="true">
								<?php echo wp_kses_post( $thumbnail ); ?>
							</div>
						<?php endif; ?>

						<div class="quarantined-cpt__content" data-quarantined-keep="true">
							<?php echo \SEORAI\BodycleanCPT\Plugin::content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					</article>
					<?php
					continue;
				endif;

				$render_toc_list = static function ( array $items, int $collapse_after, string $read_more_label, string $read_less_label ): string {
					$normalized = [];

				foreach ( $items as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}

					$label = isset( $item['label'] ) ? trim( (string) $item['label'] ) : '';

					if ( '' === $label ) {
						continue;
					}

					$url   = isset( $item['url'] ) ? (string) $item['url'] : '';
					$level = isset( $item['level'] ) ? (int) $item['level'] : 2;
					$level = max( 2, min( 4, $level ) );

					$normalized[] = [
						'label' => $label,
						'url'   => $url,
						'level' => $level,
					];
				}

				if ( empty( $normalized ) ) {
					return '';
				}

				$prev_level = 2;
				$counters   = [ 0, 0, 0 ];
				$rows       = [];
				$index      = 0;
				$has_overflow = false;

				foreach ( $normalized as $item ) {
					$level = (int) $item['level'];
					$label = esc_html( (string) $item['label'] );
					$url   = (string) $item['url'];

					if ( 0 === $index ) {
						$level = 2;
					} elseif ( $level > $prev_level + 1 ) {
						$level = $prev_level + 1;
					}

					$level_index = $level - 2;

					for ( $depth = $level_index + 1; $depth < 3; ++$depth ) {
						$counters[ $depth ] = 0;
					}

					$counters[ $level_index ] += 1;

					$number_parts = array_values(
						array_filter(
							array_slice( $counters, 0, $level_index + 1 ),
							static function ( $part ) {
								return $part > 0;
							}
						)
					);
					$number = implode( '.', $number_parts );
					$class  = 'quarantined-cpt__toc-item quarantined-cpt__toc-item--level-' . $level;

					if ( $index >= $collapse_after ) {
						$class .= ' is-collapsed';
						$has_overflow = true;
					}

					$link = '' !== trim( $url )
						? '<a href="' . esc_url( $url ) . '">' . $label . '</a>'
						: $label;

					$row_markup = sprintf(
						'<li class="%1$s"><span class="quarantined-cpt__toc-number">%2$s</span><span class="quarantined-cpt__toc-label">%3$s</span></li>',
						esc_attr( $class ),
						esc_html( $number ),
						$link
					);

					$rows[] = $row_markup;

					$prev_level = $level;
					++$index;
				}

				if ( $has_overflow ) {
					$rows[] = sprintf(
						'<li class="quarantined-cpt__toc-item quarantined-cpt__toc-item--toggle"><a href="#" class="quarantined-cpt__toc-toggle" data-toc-toggle data-label-more="%1$s" data-label-less="%2$s" aria-expanded="false">%3$s</a></li>',
						esc_attr( $read_more_label ),
						esc_attr( $read_less_label ),
						esc_html( $read_more_label )
					);
				}

				return '<ol class="quarantined-cpt__toc-list" data-toc-list>' . implode( '', $rows ) . '</ol>';
			};

			$toc_primary_html  = $render_toc_list( $toc_items, 5, $toc_read_more_label, $toc_read_less_label );

			if ( '' !== $author_job_title ) {
				$author_role_parts[] = esc_html( $author_job_title );
			}

			if ( '' !== $author_org_name ) {
				if ( '' !== $author_org_url ) {
					$author_role_parts[] = sprintf(
						'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
						esc_url( $author_org_url ),
						esc_html( $author_org_name )
					);
				} else {
					$author_role_parts[] = esc_html( $author_org_name );
				}
			}
			?>
			<article id="post-<?php the_ID(); ?>" class="quarantined-cpt__entry quarantined-cpt__entry--article" data-quarantined-keep="true">
				<?php if ( $breadcrumbs ) : ?>
					<div class="quarantined-cpt__component quarantined-cpt__component--breadcrumbs" data-quarantined-keep="true">
						<?php echo $breadcrumbs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>

				<?php if ( $thumbnail ) : ?>
					<div class="quarantined-cpt__media quarantined-cpt__media--hero quarantined-cpt__component quarantined-cpt__component--featured" data-quarantined-keep="true">
						<?php echo wp_kses_post( $thumbnail ); ?>
					</div>
				<?php endif; ?>

				<?php if ( $show_title ) : ?>
					<div class="quarantined-cpt__header quarantined-cpt__component quarantined-cpt__component--title" role="presentation">
						<h1 class="quarantined-cpt__title"><?php echo esc_html( get_the_title() ); ?></h1>
					</div>
				<?php endif; ?>

				<?php if ( $has_meta_row ) : ?>
					<div class="quarantined-cpt__meta-row quarantined-cpt__component quarantined-cpt__component--meta<?php echo $show_read_time ? '' : ' quarantined-cpt__meta-row--no-readtime'; ?>">
						<div class="quarantined-cpt__meta-primary">
							<?php if ( $show_author && $author_name ) : ?>
								<div class="quarantined-cpt__author-chip">
									<?php if ( $author_avatar_small ) : ?>
										<img class="quarantined-cpt__author-chip-avatar" src="<?php echo esc_url( $author_avatar_small ); ?>" alt="<?php echo esc_attr( $author_name ); ?>" />
									<?php endif; ?>
									<span class="quarantined-cpt__author-chip-text">
										<?php echo esc_html( \SEORAI\BodycleanCPT\Plugin::author_label_text() ); ?>
										<?php if ( $author_url ) : ?>
											<a href="<?php echo esc_url( $author_url ); ?>"><?php echo esc_html( $author_name ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $author_name ); ?>
										<?php endif; ?>
									</span>
								</div>
							<?php endif; ?>

							<?php if ( $show_read_time ) : ?>
								<span class="quarantined-cpt__meta-item quarantined-cpt__meta-item--readtime"><?php echo esc_html( $read_time_label ); ?></span>
							<?php endif; ?>

							<?php if ( $show_date && $published_date_readable ) : ?>
								<span class="quarantined-cpt__meta-item quarantined-cpt__meta-item--date">
									<time datetime="<?php echo esc_attr( $published_date_attr ); ?>">
										<?php echo esc_html( $published_date_readable ); ?>
									</time>
								</span>
							<?php endif; ?>
						</div>

						<?php if ( ! empty( $share_links ) ) : ?>
							<div class="quarantined-cpt__share-links">
								<?php foreach ( $share_links as $share_link ) : ?>
									<?php
									$share_key   = isset( $share_link['key'] ) ? (string) $share_link['key'] : 'generic';
									$share_label = isset( $share_link['label'] ) ? (string) $share_link['label'] : '';
									$share_url   = isset( $share_link['url'] ) ? (string) $share_link['url'] : '';
									$is_copy     = ! empty( $share_link['copy'] );
									$icon_markup = \SEORAI\BodycleanCPT\Plugin::get_social_icon_markup( $share_key );
									?>
									<?php if ( $is_copy ) : ?>
										<button
											type="button"
											class="quarantined-cpt__share-link quarantined-cpt__share-link--copy"
											data-copy-url="<?php echo esc_url( $share_url ); ?>"
											data-copy-default-label="<?php echo esc_attr( $share_label ); ?>"
											data-copy-success-label="<?php esc_attr_e( 'Gekopieerd', 'nova-bridge-suite' ); ?>"
											data-copy-error-label="<?php esc_attr_e( 'Kopieren mislukt', 'nova-bridge-suite' ); ?>"
											aria-label="<?php echo esc_attr( $share_label ); ?>"
										>
											<?php if ( $icon_markup ) : ?>
												<?php echo $icon_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<?php endif; ?>
											<span class="quarantined-cpt__share-link-label" data-copy-label aria-live="polite" aria-atomic="true"><?php echo esc_html( $share_label ); ?></span>
										</button>
									<?php elseif ( '' !== $share_url ) : ?>
										<a class="quarantined-cpt__share-link" href="<?php echo esc_url( $share_url ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( $share_label ); ?>">
											<?php if ( $icon_markup ) : ?>
												<?php echo $icon_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<?php endif; ?>
										</a>
									<?php endif; ?>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $has_intro ) : ?>
					<div class="quarantined-cpt__intro quarantined-cpt__component quarantined-cpt__component--intro" data-quarantined-keep="true">
						<?php echo wp_kses_post( $intro_html ); ?>
					</div>
				<?php endif; ?>

				<?php if ( $has_pre_cta_flow ) : ?>
					<div class="quarantined-cpt__pre-cta-flow quarantined-cpt__component quarantined-cpt__component--content-1<?php echo $has_toc ? ' quarantined-cpt__pre-cta-flow--has-toc' : ''; ?>">
							<?php if ( $has_toc ) : ?>
								<section class="quarantined-cpt__panel quarantined-cpt__panel--toc" aria-label="<?php echo esc_attr( $toc_label ); ?>">
									<h2 class="quarantined-cpt__panel-title"><?php echo esc_html( $toc_label ); ?></h2>
									<?php echo $toc_primary_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</section>
							<?php endif; ?>

						<?php if ( $show_key_takeaways && ! empty( $key_takeaways ) ) : ?>
							<section class="quarantined-cpt__panel quarantined-cpt__panel--takeaways" aria-label="<?php echo esc_attr( $key_takeaways_label ); ?>">
								<h2 class="quarantined-cpt__panel-title"><?php echo esc_html( $key_takeaways_label ); ?></h2>
								<ul>
									<?php foreach ( $key_takeaways as $takeaway ) : ?>
										<li><?php echo esc_html( (string) $takeaway ); ?></li>
									<?php endforeach; ?>
								</ul>
							</section>
						<?php endif; ?>

							<?php if ( $has_part_one_content ) : ?>
								<section class="quarantined-cpt__content quarantined-cpt__content--part-one" data-quarantined-keep="true">
									<?php echo wp_kses_post( $part_one_html ); ?>
								</section>
							<?php endif; ?>
						</div>
					<?php endif; ?>

				<?php if ( $show_wide_cta && $cta_is_active ) : ?>
					<section class="quarantined-cpt__wide-cta quarantined-cpt__component quarantined-cpt__component--wide-cta" data-quarantined-keep="true">
						<div class="quarantined-cpt__wide-cta-inner">
							<?php if ( '' !== trim( $cta_title ) ) : ?>
								<h2 class="quarantined-cpt__wide-cta-title"><?php echo esc_html( $cta_title ); ?></h2>
							<?php endif; ?>

							<?php if ( '' !== trim( wp_strip_all_tags( $cta_copy ) ) ) : ?>
								<div class="quarantined-cpt__wide-cta-copy">
									<?php echo wp_kses_post( $cta_copy ); ?>
								</div>
							<?php endif; ?>

							<?php if ( '' !== trim( $cta_button_label ) && '' !== trim( $cta_button_url ) ) : ?>
								<p class="quarantined-cpt__wide-cta-actions">
									<a class="quarantined-cpt__wide-cta-button" href="<?php echo esc_url( $cta_button_url ); ?>">
										<?php echo esc_html( $cta_button_label ); ?>
									</a>
								</p>
							<?php endif; ?>
						</div>
					</section>
				<?php endif; ?>

				<?php if ( $has_part_two_content ) : ?>
					<section class="quarantined-cpt__content quarantined-cpt__content--part-two quarantined-cpt__component quarantined-cpt__component--content-2" data-quarantined-keep="true">
						<?php echo wp_kses_post( $part_two_html ); ?>
					</section>
				<?php endif; ?>

				<?php if ( $show_faq && ! empty( $faq_items ) ) : ?>
					<section class="quarantined-cpt__faq quarantined-cpt__component quarantined-cpt__component--faq" data-quarantined-keep="true">
						<h2 class="quarantined-cpt__faq-title"><?php echo esc_html( $faq_title ); ?></h2>
						<div class="quarantined-cpt__faq-list">
							<?php foreach ( $faq_items as $faq_item ) : ?>
								<?php
								$faq_question = isset( $faq_item['question'] ) ? trim( (string) $faq_item['question'] ) : '';
								$faq_answer   = isset( $faq_item['answer'] ) ? (string) $faq_item['answer'] : '';

								if ( '' === $faq_question ) {
									continue;
								}
								?>
								<details class="quarantined-cpt__faq-item">
									<summary><?php echo esc_html( $faq_question ); ?></summary>
									<?php if ( '' !== trim( wp_strip_all_tags( $faq_answer ) ) ) : ?>
										<div class="quarantined-cpt__faq-answer">
											<?php echo wp_kses_post( wpautop( $faq_answer ) ); ?>
										</div>
									<?php endif; ?>
								</details>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>

				<?php if ( $show_related && ! empty( $related_posts ) ) : ?>
					<section class="quarantined-cpt__related quarantined-cpt__component quarantined-cpt__component--related" data-quarantined-keep="true">
						<h2 class="quarantined-cpt__related-title"><?php echo esc_html( $related_articles_title ); ?></h2>
						<div class="quarantined-cpt__grid quarantined-cpt__grid--related">
							<?php foreach ( $related_posts as $related_post ) : ?>
								<?php
								$related_title   = isset( $related_post['title'] ) ? (string) $related_post['title'] : '';
								$related_url     = isset( $related_post['url'] ) ? (string) $related_post['url'] : '';
								$related_excerpt = isset( $related_post['excerpt'] ) ? (string) $related_post['excerpt'] : '';
								$related_thumb   = isset( $related_post['thumbnail'] ) ? (string) $related_post['thumbnail'] : '';
								?>
								<article class="quarantined-cpt__card quarantined-cpt__related-card" data-quarantined-keep="true">
									<a class="quarantined-cpt__card-thumb" href="<?php echo esc_url( $related_url ); ?>">
										<?php if ( '' !== $related_thumb ) : ?>
											<img src="<?php echo esc_url( $related_thumb ); ?>" alt="<?php echo esc_attr( $related_title ); ?>" loading="lazy" />
										<?php else : ?>
											<?php echo \SEORAI\BodycleanCPT\Plugin::placeholder( 'card-thumb' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php endif; ?>
									</a>
									<div class="quarantined-cpt__card-body">
										<h3 class="quarantined-cpt__card-title">
											<a href="<?php echo esc_url( $related_url ); ?>"><?php echo esc_html( $related_title ); ?></a>
										</h3>
										<?php if ( '' !== trim( $related_excerpt ) ) : ?>
											<p class="quarantined-cpt__card-summary"><?php echo esc_html( $related_excerpt ); ?></p>
										<?php endif; ?>
									</div>
								</article>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>

				<?php if ( $show_wide_cta_after_related && $cta_after_related_active ) : ?>
					<section class="quarantined-cpt__wide-cta quarantined-cpt__wide-cta--after-related quarantined-cpt__component quarantined-cpt__component--wide-cta-after" data-quarantined-keep="true">
						<div class="quarantined-cpt__wide-cta-inner">
							<?php if ( '' !== trim( $cta_after_related_title ) ) : ?>
								<h2 class="quarantined-cpt__wide-cta-title"><?php echo esc_html( $cta_after_related_title ); ?></h2>
							<?php endif; ?>

							<?php if ( '' !== trim( wp_strip_all_tags( $cta_after_related_copy ) ) ) : ?>
								<div class="quarantined-cpt__wide-cta-copy">
									<?php echo wp_kses_post( $cta_after_related_copy ); ?>
								</div>
							<?php endif; ?>

							<?php if ( '' !== trim( $cta_after_related_button_label ) && '' !== trim( $cta_after_related_button_url ) ) : ?>
								<p class="quarantined-cpt__wide-cta-actions">
									<a class="quarantined-cpt__wide-cta-button" href="<?php echo esc_url( $cta_after_related_button_url ); ?>">
										<?php echo esc_html( $cta_after_related_button_label ); ?>
									</a>
								</p>
							<?php endif; ?>
						</div>
					</section>
				<?php endif; ?>

				<?php if ( $show_author && $show_author_box && $author_name ) : ?>
					<section class="quarantined-cpt__author-box quarantined-cpt__component quarantined-cpt__component--author-box" data-quarantined-keep="true">
						<?php if ( $author_avatar_large ) : ?>
							<div class="quarantined-cpt__author-box-media">
								<img class="quarantined-cpt__author-box-avatar" src="<?php echo esc_url( $author_avatar_large ); ?>" alt="<?php echo esc_attr( $author_name ); ?>" />
							</div>
						<?php endif; ?>
						<div class="quarantined-cpt__author-box-content">
							<h2 class="quarantined-cpt__author-box-name">
								<?php if ( $author_url ) : ?>
									<a href="<?php echo esc_url( $author_url ); ?>"><?php echo esc_html( $author_name ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $author_name ); ?>
								<?php endif; ?>
							</h2>

							<?php if ( ! empty( $author_role_parts ) ) : ?>
								<p class="quarantined-cpt__author-box-role">
									<?php
									echo wp_kses(
										implode( ' · ', $author_role_parts ),
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

							<?php if ( '' !== trim( $author_description ) ) : ?>
								<div class="quarantined-cpt__author-box-description">
									<?php echo wp_kses_post( wpautop( $author_description ) ); ?>
								</div>
							<?php endif; ?>
						</div>
					</section>
				<?php endif; ?>
			</article>
		<?php endwhile; ?>
	</div>
</main>
<script>
(function () {
	function fallbackCopyToClipboard(text) {
		var textArea = document.createElement('textarea');
		var copied = false;

		textArea.value = text;
		textArea.setAttribute('readonly', '');
		textArea.style.position = 'fixed';
		textArea.style.top = '-9999px';
		textArea.style.left = '-9999px';

		document.body.appendChild(textArea);
		textArea.focus();
		textArea.select();

		try {
			copied = document.execCommand('copy');
		} catch (error) {
			copied = false;
		}

		document.body.removeChild(textArea);

		return copied;
	}

	function copyToClipboard(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}

		return new Promise(function (resolve, reject) {
			if (fallbackCopyToClipboard(text)) {
				resolve();
				return;
			}

			reject(new Error('copy-failed'));
		});
	}

	function showCopyFeedback(button, message, stateClass) {
		var label = button.querySelector('[data-copy-label]');
		var defaultLabel;

		if (!label) {
			return;
		}

		defaultLabel = button.getAttribute('data-copy-default-label') || label.textContent;
		label.textContent = message;
		button.classList.remove('is-copy-success', 'is-copy-error');

		if (stateClass) {
			button.classList.add(stateClass);
		}

		if (button.__copyResetTimeout) {
			window.clearTimeout(button.__copyResetTimeout);
		}

		button.__copyResetTimeout = window.setTimeout(function () {
			label.textContent = defaultLabel;
			button.classList.remove('is-copy-success', 'is-copy-error');
			button.__copyResetTimeout = null;
		}, 1800);
	}

document.addEventListener('click', function (event) {
	var copyButton = event.target.closest('.quarantined-cpt__share-link--copy');
	var tocToggle;
	var tocList;
	var expanded;
	var nextLabel;
	if (copyButton) {
		var url = copyButton.getAttribute('data-copy-url');
		var successMessage = copyButton.getAttribute('data-copy-success-label') || 'Copied';
		var errorMessage = copyButton.getAttribute('data-copy-error-label') || 'Copy failed';

		event.preventDefault();

		if (url) {
			copyToClipboard(url).then(function () {
				showCopyFeedback(copyButton, successMessage, 'is-copy-success');
			}).catch(function () {
				showCopyFeedback(copyButton, errorMessage, 'is-copy-error');
			});
		}
		return;
	}

	tocToggle = event.target.closest('[data-toc-toggle]');
	if (!tocToggle) {
		return;
	}

	event.preventDefault();
	tocList = tocToggle.closest('[data-toc-list]');
	if (!tocList) {
		return;
	}

	expanded = tocToggle.getAttribute('aria-expanded') === 'true';
	expanded = !expanded;
	tocToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
	nextLabel = expanded
		? (tocToggle.getAttribute('data-label-less') || 'Show less...')
		: (tocToggle.getAttribute('data-label-more') || 'Show more...');
	tocToggle.textContent = nextLabel;

	tocList.classList.toggle('is-expanded', expanded);
});
}());
</script>
<?php

get_footer();
