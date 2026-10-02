<?php
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-receipt-json.php';
$checks = 0;
function receipt_check( $ok, $message ) { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( $message ); } }
// RFC 8785 Appendix B: https://www.rfc-editor.org/rfc/rfc8785.html#appendix-B
$vectors = [ '0000000000000000' => '0', '8000000000000000' => '0', '0000000000000001' => '5e-324', '8000000000000001' => '-5e-324', '7fefffffffffffff' => '1.7976931348623157e+308', 'ffefffffffffffff' => '-1.7976931348623157e+308', '4340000000000000' => '9007199254740992', 'c340000000000000' => '-9007199254740992', '4430000000000000' => '295147905179352830000', '44b52d02c7e14af5' => '9.999999999999997e+22', '44b52d02c7e14af6' => '1e+23', '44b52d02c7e14af7' => '1.0000000000000001e+23', '444b1ae4d6e2ef4e' => '999999999999999700000', '444b1ae4d6e2ef4f' => '999999999999999900000', '444b1ae4d6e2ef50' => '1e+21', '3eb0c6f7a0b5ed8c' => '9.999999999999997e-7', '3eb0c6f7a0b5ed8d' => '0.000001', '41b3de4355555553' => '333333333.3333332', '41b3de4355555554' => '333333333.33333325', '41b3de4355555555' => '333333333.3333333', '41b3de4355555556' => '333333333.3333334', '41b3de4355555557' => '333333333.33333343', 'becbf647612f3696' => '-0.0000033333333333333333', '43143ff3c1cb0959' => '1424953923781206.2' ];
foreach ( $vectors as $hex => $expected ) { $actual = Nova_Bridge_Suite_Receipt_Json::encode( unpack( 'E', hex2bin( (string) $hex ) )[1] ); receipt_check( $actual === $expected, $hex . ': expected ' . $expected . ', got ' . $actual ); }
receipt_check( Nova_Bridge_Suite_Receipt_Json::encode( (object) [] ) === '{}' && Nova_Bridge_Suite_Receipt_Json::encode( [] ) === '[]', 'Objects and arrays remain distinct.' );
receipt_check( Nova_Bridge_Suite_Receipt_Json::encode( [ "\u{E000}" => 1, "\u{1F600}" => 2, 'a' => 3 ] ) === '{"a":3,"😀":2,"":1}', 'UTF-16 ordering differs from UTF-8 ordering.' );
receipt_check( Nova_Bridge_Suite_Receipt_Json::encode( "\u{2028}\u{2029}/\n\t\x00" ) === '"' . "\u{2028}\u{2029}" . '/\n\t\u0000"', 'Unicode remains literal, controls escaped, slashes unescaped.' );
receipt_check( Nova_Bridge_Suite_Receipt_Json::encode( (object) [ '2' => 2, '10' => 10 ] ) === '{"10":10,"2":2}', 'Numeric property names sort as UTF-16 strings.' );
foreach ( [ NAN, INF, -INF, "\xff", (object) [ "\xff" => 1 ], PHP_INT_MAX, 9007199254740993, -9007199254740993 ] as $bad ) {
    try { Nova_Bridge_Suite_Receipt_Json::encode( $bad ); receipt_check( false, 'Invalid value accepted.' ); }
    catch ( InvalidArgumentException | JsonException $expected ) { ++$checks; }
}
ini_set( 'serialize_precision', '3' ); receipt_check( Nova_Bridge_Suite_Receipt_Json::encode( 333333333.3333333 ) === '333333333.3333333' && ini_get( 'serialize_precision' ) === '3', 'Host precision cannot change wire bytes and is restored.' );
echo "PASS {$checks} canonical JSON checks\n";
