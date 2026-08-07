<?php
/**
 * Run with: wp eval-file tests/cpt-archive-regression.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

function nova_cpt_archive_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

nova_cpt_archive_test_assert( class_exists( 'SEORAI\\BodycleanCPT\\Plugin' ), 'Enable the NOVA Blog CPT module before running this check.' );

$author_archive_filter = static function () {
	return true;
};
$posts_per_page_filter = static function () {
	return 3;
};

add_filter( 'pre_option_quarantined_cpt_bodyclean_author_archive', $author_archive_filter );
add_filter( 'pre_option_quarantined_cpt_bodyclean_author_posts_per_page', $posts_per_page_filter );

global $wp_the_query;
$original_query = $wp_the_query;
$test_query     = new WP_Query();

try {
	$wp_the_query          = $test_query;
	$test_query->is_author = true;
	do_action_ref_array( 'pre_get_posts', [ $test_query ] );

	nova_cpt_archive_test_assert( 3 === (int) $test_query->get( 'posts_per_page' ), 'Author archives did not use the configured three-card page size.' );
} finally {
	$wp_the_query = $original_query;
	remove_filter( 'pre_option_quarantined_cpt_bodyclean_author_archive', $author_archive_filter );
	remove_filter( 'pre_option_quarantined_cpt_bodyclean_author_posts_per_page', $posts_per_page_filter );
}

WP_CLI::success( 'NOVA Blog author archive regression checks passed.' );
