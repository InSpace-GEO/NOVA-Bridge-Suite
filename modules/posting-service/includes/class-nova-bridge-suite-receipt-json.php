<?php
/** RFC 8785 serializer, isolated from the historical writing compiler's hash domain. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Receipt_Json {
    public static function encode( $value, int $depth = 0 ): string {
        if ( $depth > 64 ) { throw new InvalidArgumentException( 'JSON nesting exceeds the supported bound.' ); }
        if ( is_float( $value ) ) { return self::number( $value ); }
        if ( is_int( $value ) ) {
            if ( $value > 9007199254740992 || $value < -9007199254740992 ) { throw new InvalidArgumentException( 'Unsafe JSON integer; use a decimal string.' ); }
            return (string) $value;
        }
        if ( is_string( $value ) || is_bool( $value ) || null === $value ) {
            return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR );
        }
        if ( ! is_array( $value ) && ! $value instanceof stdClass ) { throw new InvalidArgumentException( 'Only JSON values can be canonicalized.' ); }
        $list = is_array( $value ) && array_values( $value ) === $value;
        $members = (array) $value; $parts = [];
        if ( ! $list ) { uksort( $members, static function ( $a, $b ) { return strcmp( self::utf16( (string) $a ), self::utf16( (string) $b ) ); } ); }
        foreach ( $members as $key => $child ) { $parts[] = ( $list ? '' : self::encode( (string) $key ) . ':' ) . self::encode( $child, $depth + 1 ); }
        return ( $list ? '[' : '{' ) . implode( ',', $parts ) . ( $list ? ']' : '}' );
    }

    private static function utf16( string $text ): string {
        $chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
        if ( false === $chars ) { throw new InvalidArgumentException( 'Invalid UTF-8 property name.' ); }
        $result = '';
        foreach ( $chars as $char ) {
            $bytes = array_values( unpack( 'C*', $char ) ); $size = count( $bytes );
            $point = $bytes[0] & ( 1 === $size ? 127 : ( 127 >> $size ) );
            for ( $i = 1; $i < $size; ++$i ) { $point = ( $point << 6 ) | ( $bytes[$i] & 63 ); }
            $result .= $point < 65536 ? pack( 'n', $point ) : pack( 'nn', 0xd800 + ( ( $point - 65536 ) >> 10 ), 0xdc00 + ( ( $point - 65536 ) & 1023 ) );
        }
        return $result;
    }

    private static function number( float $value ): string {
        if ( ! is_finite( $value ) ) { throw new InvalidArgumentException( 'Nonfinite JSON number.' ); }
        if ( 0.0 === $value ) { return '0'; }
        // PHP's shortest round-trip binary64 conversion, followed by ECMAScript's display thresholds.
        $previous = ini_get( 'serialize_precision' );
        try { ini_set( 'serialize_precision', '-1' ); $text = json_encode( abs( $value ), JSON_THROW_ON_ERROR ); }
        finally { ini_set( 'serialize_precision', $previous ); }
        $parts = explode( 'e', strtolower( $text ) ); $mantissa = $parts[0];
        $dot = strpos( $mantissa, '.' ); $position = ( false === $dot ? strlen( $mantissa ) : $dot ) + (int) ( $parts[1] ?? 0 );
        $digits = str_replace( '.', '', $mantissa );
        while ( strlen( $digits ) > 1 && '0' === $digits[0] ) { $digits = substr( $digits, 1 ); --$position; }
        $digits = rtrim( $digits, '0' );
        if ( $position > 0 && $position <= 21 ) { $formatted = $position >= strlen( $digits ) ? $digits . str_repeat( '0', $position - strlen( $digits ) ) : substr( $digits, 0, $position ) . '.' . substr( $digits, $position ); }
        elseif ( $position <= 0 && $position > -6 ) { $formatted = '0.' . str_repeat( '0', -$position ) . $digits; }
        else { $exponent = $position - 1; $formatted = $digits[0] . ( strlen( $digits ) > 1 ? '.' . substr( $digits, 1 ) : '' ) . 'e' . ( $exponent >= 0 ? '+' : '' ) . $exponent; }
        return ( $value < 0 ? '-' : '' ) . $formatted;
    }
}
