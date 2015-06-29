<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}
require_once 'class-sf-resource.php';

class SF_Attribute extends SF_Resource
{
    public function __construct()
    {
    }

    /**
     * Get all the attributes in the system
     *
     */
    public function get_attributes() {

        //get system attricutes
        $wc_product_attributes = array();

        if ( $attribute_taxonomies = wc_get_attribute_taxonomies() ) {
            foreach ( $attribute_taxonomies as $tax ) {
                if ( $name = wc_attribute_taxonomy_name( $tax->attribute_name ) ) {
                    $wc_product_attributes[ $name ] = $tax->attribute_name;
                }
            }
        }

        //get all product attributes
        global $wpdb;

        $product_attribute_rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . $wpdb->prefix . "postmeta WHERE meta_key = %s", '_product_attributes' ) );

        $product_attributes = array();
        if ( $product_attribute_rows && !empty( $product_attribute_rows ) )
        {
            foreach ( $product_attribute_rows as $product_attribute_row )
            {
                $unserialized = unserialize( $product_attribute_row->meta_value );
                foreach ( $unserialized as $attribute_code => $attribute )
                {
                    $product_attributes[ $attribute_code ] = $attribute[ 'name' ];
                }
            }

        }

        return array_merge( $wc_product_attributes, $product_attributes );
    }
}