<?php
/** Real legacy recipe readback; fixture persistence is entirely in-memory. */
require __DIR__ . '/recipe-engine-unit.php';
$before_checks = $checks;
$adapter = new Nova_Bridge_Suite_Recipe_Elementor();
$id = 700; $document = [ [ 'id' => 'empty-settings-check', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ];
$payload = [ 'elementor_data' => $document, 'template' => '', 'elementor_page_settings' => [] ];
$metadata[$id] = [ '_elementor_data' => json_encode( $document ), '_elementor_edit_mode' => 'builder' ];
$templates[$id] = '';
$before_writes = $writes;
foreach ( [ [ [], '' ], [ '', [] ], [ [], [] ], [ '', '' ] ] as [ $expected, $actual ] ) {
    $payload['elementor_page_settings'] = $expected; $metadata[$id]['_elementor_page_settings'] = $actual;
    check( ! is_wp_error( $adapter->verify_saved( $id, $payload ) ), 'Both exact empty forms verify in either direction for legacy recipes.' );
}
unset( $metadata[$id]['_elementor_page_settings'] ); $payload['elementor_page_settings'] = [];
check( ! is_wp_error( $adapter->verify_saved( $id, $payload ) ), 'WordPress missing metadata may represent empty settings.' );
foreach ( [ false, 0, '0', [ 'color' => '' ], [ 'color' => '#fff' ] ] as $actual ) {
    $metadata[$id]['_elementor_page_settings'] = $actual;
    check( is_wp_error( $adapter->verify_saved( $id, $payload ) ), 'Other falsy values and nonempty settings must not be normalized.' );
}
$metadata[$id]['_elementor_page_settings'] = ''; $payload['elementor_page_settings'] = [ 'color' => '#fff' ];
check( is_wp_error( $adapter->verify_saved( $id, $payload ) ), 'Removing a real requested setting remains a verification failure.' );
$payload['elementor_page_settings'] = [];
$metadata[$id]['_elementor_data'] = '[]';
check( is_wp_error( $adapter->verify_saved( $id, $payload ) ), 'An empty settings representation never hides document changes.' );
$metadata[$id]['_elementor_data'] = json_encode( $document ); $templates[$id] = 'different-template';
check( is_wp_error( $adapter->verify_saved( $id, $payload ) ), 'An empty settings representation never hides template changes.' );
check( $writes === $before_writes, 'Readback does not write fixture storage.' );
echo 'Legacy Elementor empty-settings checks: ' . ( $checks - $before_checks ) . " passed.\n";
