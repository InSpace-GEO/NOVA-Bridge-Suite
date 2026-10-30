<?php
/** No native theme/bootstrap/database: verify builder attribution before and after theme availability. */
define( 'ABSPATH', __DIR__ . '/no-wordpress/' );
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-flatsome.php';
$checks = 0;
function check( $value, $message ) { ++$GLOBALS['checks']; if ( ! $value ) { throw new RuntimeException( $message ); } }
function ux_row() {} function ux_col() {}
check( ! Nova_Bridge_Suite_Recipe_Flatsome::has_document( '[section][row][col]Another builder[/col][/row][/section]' ), 'Generic shortcode tags do not claim another theme’s content while Flatsome is inactive.' );
check( Nova_Bridge_Suite_Recipe_Flatsome::has_document( '[ux_text]<p>Native content</p>[/ux_text]' ), 'A distinctive retained UX shortcode is discoverable even if its theme is disabled.' );
check( Nova_Bridge_Suite_Recipe_Flatsome::has_document( '<!-- wp:flatsome/uxbuilder --><p>Native wrapped content</p><!-- /wp:flatsome/uxbuilder -->' ), 'The native Flatsome block wrapper identifies its own provider.' );
check( ! Nova_Bridge_Suite_Recipe_Flatsome::has_document( '<!-- wp:paragraph --><p>Ordinary block</p><!-- /wp:paragraph -->' ), 'Ordinary Gutenberg blocks retain their original builder attribution.' );
define( 'UX_BUILDER_VERSION', '3.20.7-fixture' );
check( Nova_Bridge_Suite_Recipe_Flatsome::has_document( '[section][row_inner][col_inner]<p>Native content</p>[/col_inner][/row_inner][/section]' ), 'Active native Flatsome renderers identify structural shortcode layouts.' );
echo 'Flatsome detection checks: ' . $checks . " passed.\n";
