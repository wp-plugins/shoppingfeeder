<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class SF_Authentication {
    /**
     * @var string
     */
    public $version = '1.0.0';

    const RANDOM_STRING = 'The mice all ate cheese together.';
    const MAX_TIMEOUT = 300; //5 minutes

    public static function auth( array $headers, $incoming_scheme, $incoming_method ) {
        //check the API key
        $incoming_api_key = '';
        $incoming_auth_timestamp = strtotime( '1970-00-00' );
        $incoming_signature = '';
        foreach ( $headers as $key => $value ) {
            if ( strtolower( 'X-SFApiKey' ) == strtolower( $key )) {
                $incoming_api_key = $value;
            }
            if ( strtolower( 'X-SFTimestamp' ) == strtolower( $key )) {
                $incoming_auth_timestamp = $value;
            }
            if ( strtolower( 'X-SFSignature' ) == strtolower( $key )) {
                $incoming_signature = $value;
            }
        }

        //check the timestamp
        if ( time() - $incoming_auth_timestamp <= self::MAX_TIMEOUT )
        {
            $local_api_key = get_option( 'shoppingfeeder_api_key' );
            if ( $local_api_key == $incoming_api_key ) {
                $local_api_secret = get_option( 'shoppingfeeder_api_secret' );

                $string_to_sign = $incoming_method . "\n" .
                    $incoming_auth_timestamp . "\n" .
                    self::RANDOM_STRING;

                $signature = bin2hex( mhash( MHASH_SHA256, $string_to_sign, $local_api_secret ));

                if ( $incoming_signature == $signature ) {
                    return true;
                } else {
                    return 'Authentication failed due to invalid credentials.';
                }
            } else {
                return 'Authentication failed due to invalid API key.';
            }
        } else {
            return 'Authentication failed due to timeout being exceeded.';
        }
    }

    public static function get_api_keys() {
        $local_api_key = get_option( 'shoppingfeeder_api_key' );
        $local_api_secret = get_option( 'shoppingfeeder_api_secret' );

        return array(
            'api_key' => $local_api_key,
            'api_secret' => $local_api_secret
        );
    }

}