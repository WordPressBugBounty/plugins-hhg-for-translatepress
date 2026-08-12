<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HHG_Cache {
    public static function get( $key ) {
        return get_transient( self::normalize_key( $key ) );
    }

    public static function set( $key, $value, $ttl = 1800 ) {
        set_transient( self::normalize_key( $key ), $value, $ttl );
    }

    private static function normalize_key( $key ) {
        return 'hhgfotr_' . md5( $key );
    }
}

