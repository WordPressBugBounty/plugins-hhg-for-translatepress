<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HHG_Logger {
    public static function log( $message, $context = array() ) {
        $prefix = '[HHG-TP] ';
        if ( ! empty( $context ) ) {
            if ( is_array( $context ) ) {
                $message .= ' ' . wp_json_encode( $context );
            } else {
                $message .= ' ' . strval( $context );
            }
        }
        error_log( $prefix . $message );
        do_action( 'hhgfotr_log', $message, $context );
    }
}

