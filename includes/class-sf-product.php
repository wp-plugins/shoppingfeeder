<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}
require_once 'class-sf-resource.php';

class SF_Product extends SF_Resource
{
    protected $_categories = array();

    public function __construct()
    {
        //load all categories to lookup later
        $product_categories = array();

        $terms = get_terms( 'product_cat', array( 'hide_empty' => false, 'fields' => 'ids' ) );

        foreach ( $terms as $term_id ) {

            $product_categories[$term_id] = current( $this->get_product_category( $term_id ) );
        }

        $this->_categories = $product_categories;
    }

    public function get_full_category_path( $category_id ) {
        $category = $this->_categories[$category_id];
        $parent_id = $category['parent'];
        $path = $category['name'];
        while ( $parent_id != 0 )
        {
            $category = $this->_categories[$parent_id];
            $path = $category['name'] . ' > ' . $path;
            $parent_id = $category['parent'];
        }
        return $path;
    }

    /**
     * Get the product category for the given ID
     *
     * @since 2.2
     * @param string $id product category term ID
     * @param string|null $fields fields to limit response to
     * @return array
     */
    public function get_product_category( $id ) {

        $id = absint( $id );

        $term = get_term( $id, 'product_cat' );

        $product_category = array(
            'id'          => intval( $term->term_id ),
            'name'        => $term->name,
            'slug'        => $term->slug,
            'parent'      => $term->parent,
            'description' => $term->description,
            'count'       => intval( $term->count ),
        );

        return array( $product_category, $id, $term, $this );
    }

    public function get_products( $page = null, $num_per_page = 1000, $allow_variants = true )
    {
        // set base query arguments
        $query_args = array(
            'fields'      => 'ids',
            'post_type'   => 'product',
            'post_status' => 'publish',
            'meta_query'  => array(),
        );

        if ( ! empty( $args['type'] ) ) {

            $types = explode( ',', $args['type'] );

            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_type',
                    'field'    => 'slug',
                    'terms'    => $types,
                ),
            );

            unset( $args['type'] );
        }

        //$query_args['is_paged'] = true;
        $args['page'] = $page;
        $args['limit'] = $num_per_page;

        $query_args = $this->merge_query_args( $query_args, $args );

        $query = new WP_Query( $query_args );

        $products = array();

        foreach ( $query->posts as $product_id ) {

            list( $product_data, $variations) = $this->get_product( $product_id, null );
            $products[] = $product_data;

            if ( $allow_variants ) {
                if ( !is_null( $variations ) ) {
                    foreach ( $variations as $variation ) {
                        $base_product = $product_data;

                        //remove the extra images as they don't apply to the variant
                        unset( $base_product['extra_images'] );

                        //bring in the variant data
                        $variant = array_merge( $base_product, $variation );

                        //create the title from attributes
                        if ( !empty( $variant['attributes'] ) ) {
                            foreach ( $variant['attributes'] as $attribute ) {
                                if ( !empty( $attribute['option'] ) ) {
                                    $variant['title'] .= ' '.$attribute['option'];
                                }
                                if ( isset( $variant['image'][0]['image_url'] ) ) {
                                    $variant['image_url'] = $variant['image'][0]['image_url'];
                                    unset( $variant['image'] );
                                }
                            }
                        }
                        $products[] = $variant;
                    }
                }
            }
        }

        return $products;

    }

    /**
     * Get the product for the given ID
     *
     * @since 2.1
     * @param int $id the product ID
     * @param string $fields
     * @return array
     */
    public function get_product( $id, $fields = null, $allow_variants = true ) {

        $product = wc_get_product( $id );

        // add data that applies to every product type
        $product_data = $this->get_product_data( $product );
        if ($product->is_type( 'variable' ))
        {
            $product_data['sale_price'] = $product->min_variation_price;
            $product_data['price'] = $product->get_variation_regular_price( 'min', false );
        }

        $variations = null;

        if ( $allow_variants ) {
            // add variations to variable products
            if ( $product->is_type( 'variable' ) && $product->has_child() ) {

                $variations = $this->get_variation_data( $product );
            }

            // add the parent product data to an individual variation
            if ( $product->is_type( 'variation' ) ) {

                //$product_data['parent'] = $this->get_product_data( $product->parent );
            }
        }

        return array( $product_data, $variations );
    }

    /**
     * Get standard product data that applies to every product type
     *
     * @since 2.1
     * @param WC_Product $product
     * @return WC_Product
     */
    private function get_product_data( $product )
    {
        $brand = $manufacturer = $mpn = $gtin = '';
        $attributes = $this->get_attributes( $product );
        $useful_attributes = array();
        foreach ( $attributes as $attribute )
        {
            $attribute_label = $attribute['name'];
            $value = current( $attribute['options'] );

            $useful_attributes[$attribute_label] = $value;

            if ( preg_match('/^manufacturer$/i', $attribute_label ) ) {
                $manufacturer = $value;
            }

            if ( preg_match('/^brand$/i', $attribute_label ) ) {
                $brand = $value;
            }

            if ( preg_match('/^(mpn|model|model number)$/i', $attribute_label ) ) {
                $mpn = $value;
            }

            if ( preg_match( '/^(gtin|ean|upc)$/i', $attribute_label ) ) {
                $gtin = $value;
            }
        }

        $title = $product->get_title();
        $image_url = wp_get_attachment_url( get_post_thumbnail_id( $product->is_type( 'variation' ) ? $product->variation_id : $product->id ) );

        return array(
            'internal_id'        => (int) $product->is_type( 'variation' ) ? $product->get_variation_id() : $product->id,
            'category'           => $this->get_full_category_path( current( wp_get_post_terms( $product->id, 'product_cat', array( 'fields' => 'ids' ) ) ) ),
            'title'              => $title,
            'brand'              => !empty( $brand ) ? $brand : ( !empty( $manufacturer ) ? $manufacturer : '' ),
            'manufacturer'       => !empty( $manufacturer ) ? $manufacturer : ( !empty( $brand ) ? $brand : '' ),
            'mpn'                => !empty( $mpn ) ? $mpn : $product->get_sku(),
            'description'        => wpautop( do_shortcode( $product->get_post_data()->post_content ) ),
            'short_description'  => apply_filters( 'woocommerce_short_description', $product->get_post_data()->post_excerpt ),
            'weight'             => $product->get_weight() ? wc_format_decimal( $product->get_weight(), 2 ) : null,
            'sku'                => $product->get_sku(),
            'gtin'               => $gtin,
            'price'              => wc_format_decimal( $product->get_regular_price(), 2 ),
            'sale_price'         => ( $product->is_on_sale() ) ? ( $product->get_sale_price() ? wc_format_decimal( $product->get_sale_price(), 2 ) : 0.00 ) : 0.00,
            'sale_price_effective_date'         => '',



            'delivery_cost'      => '',
            'tax'                => '',

            'url'                => $product->get_permalink(),
            'image'              => $this->get_images( $product ),
            'image_url'          => $image_url,
            'extra_images'       => $this->get_images( $product ),
            'availability'       => $product->is_in_stock() ? 'in stock' : 'out of stock',
//            'availability_date'  => $product->is_in_stock() ? 'in stock' : 'out of stock',
            'quantity'           => (int) $product->get_stock_quantity(),
            'condition'          => 'new',
            'attributes'         => $useful_attributes,
            'dimensions'         => array(
                'length' => $product->length,
                'width'  => $product->width,
                'height' => $product->height,
                'unit'   => get_option( 'woocommerce_dimension_unit' ),
            ),


            'internal_update_time'         => ShoppingFeeder::format_datetime( $product->get_post_data()->post_modified_gmt ),


//            'created_at'         => ShoppingFeeder::format_datetime( $product->get_post_data()->post_date_gmt ),
//            'type'               => $product->product_type,
//            'status'             => $product->get_post_data()->post_status,
//            'downloadable'       => $product->is_downloadable(),
//            'virtual'            => $product->is_virtual(),
//            'price_html'         => $product->get_price_html(),
//            'taxable'            => $product->is_taxable(),
//            'tax_status'         => $product->get_tax_status(),
//            'tax_class'          => $product->get_tax_class(),
//            'managing_stock'     => $product->managing_stock(),
//            'backorders_allowed' => $product->backorders_allowed(),
//            'backordered'        => $product->is_on_backorder(),
//            'sold_individually'  => $product->is_sold_individually(),
//            'purchaseable'       => $product->is_purchasable(),
//            'featured'           => $product->is_featured(),
//            'visible'            => $product->is_visible(),
//            'catalog_visibility' => $product->visibility,
//            'shipping_required'  => $product->needs_shipping(),
//            'shipping_taxable'   => $product->is_shipping_taxable(),
//            'shipping_class'     => $product->get_shipping_class(),
//            'shipping_class_id'  => ( 0 !== $product->get_shipping_class_id() ) ? $product->get_shipping_class_id() : null,
//            'categories'         => wp_get_post_terms( $product->id, 'product_cat', array( 'fields' => 'names' ) ),
//            'tags'               => wp_get_post_terms( $product->id, 'product_tag', array( 'fields' => 'names' ) ),
//            'variations'         => array(),
//            'parent'             => array(),
//            'attributes'         => $attributes,
        );
    }

    /**
     * Get an individual variation's data
     *
     * @since 2.1
     * @param WC_Product $product
     * @return array
     */
    private function get_variation_data( $product ) {

        $variations = array();

        foreach ( $product->get_children() as $child_id ) {

            /** @var WC_Product_Variation $variation */
            $variation = $product->get_child( $child_id );

            if ( ! $variation->exists() ) {
                continue;
            }

            $variations[] = array(
                'internal_variant_id'   => $variation->get_variation_id(),


//                'created_at'        => ShoppingFeeder::format_datetime( $variation->get_post_data()->post_date_gmt ),
                'internal_update_time'        => ShoppingFeeder::format_datetime( $variation->get_post_data()->post_modified_gmt ),
//                'downloadable'      => $variation->is_downloadable(),
//                'virtual'           => $variation->is_virtual(),
                'title'             => $variation->get_title(),
                'url'               => $variation->get_permalink(),
                'sku'               => $variation->get_sku(),
                'price'             => wc_format_decimal( $variation->get_price(), 2 ),
                'regular_price'     => wc_format_decimal( $variation->get_regular_price(), 2 ),
                'sale_price'        => $variation->get_sale_price() ? wc_format_decimal( $variation->get_sale_price(), 2 ) : null,
                'taxable'           => $variation->is_taxable(),
                'tax_status'        => $variation->get_tax_status(),
                'tax_class'         => $variation->get_tax_class(),
                'stock_quantity'    => (int ) $variation->get_stock_quantity(),
                'in_stock'          => $variation->is_in_stock(),
//                'backordered'       => $variation->is_on_backorder(),
//                'purchaseable'      => $variation->is_purchasable(),
                'visible'           => $variation->variation_is_visible(),
                'on_sale'           => $variation->is_on_sale(),
                'weight'            => $variation->get_weight() ? wc_format_decimal( $variation->get_weight(), 2 ) : null,
                'dimensions'        => array(
                    'length' => $variation->length,
                    'width'  => $variation->width,
                    'height' => $variation->height,
                    'unit'   => get_option( 'woocommerce_dimension_unit' ),
                ),
                'shipping_class'    => $variation->get_shipping_class(),
                'shipping_class_id' => ( 0 !== $variation->get_shipping_class_id() ) ? $variation->get_shipping_class_id() : null,
                'image'             => $this->get_images( $variation ),
                'attributes'        => $this->get_attributes( $variation ),
            );
        }
        //print_r($variations);

        return $variations;
    }

    /*
    * Get the images for a product or product variation
    *
    * @since 2.1
    * @param WC_Product|WC_Product_Variation $product
    * @return array
    */
    private function get_images( $product ) {

        $images = $attachment_ids = array();

        if ( $product->is_type( 'variation' ) ) {

            if ( has_post_thumbnail( $product->get_variation_id() ) ) {

                // add variation image if set
                $attachment_ids[] = get_post_thumbnail_id( $product->get_variation_id() );

            } elseif ( has_post_thumbnail( $product->id ) ) {

                // otherwise use the parent product featured image if set
                $attachment_ids[] = get_post_thumbnail_id( $product->id );
            }

        } else {

            // add featured image
            if ( has_post_thumbnail( $product->id ) ) {
                $attachment_ids[] = get_post_thumbnail_id( $product->id );
            }

            // add gallery images
            $attachment_ids = array_merge( $attachment_ids, $product->get_gallery_attachment_ids() );
        }

        // build image data
        foreach ( $attachment_ids as $position => $attachment_id ) {

            $attachment_post = get_post( $attachment_id );

            if ( is_null( $attachment_post ) ) {
                continue;
            }

            $attachment = wp_get_attachment_image_src( $attachment_id, 'full' );

            if ( ! is_array( $attachment ) ) {
                continue;
            }

            $images[] = array(
                'image_modified_time' => ShoppingFeeder::format_datetime( $attachment_post->post_modified_gmt ),
                'image_url'        => current( $attachment )
            );
        }

        // set a placeholder image if the product has no images set
        if ( empty( $images ) ) {

            $images[] = array(
                'image_modified_time' => ShoppingFeeder::format_datetime( time() ),
                'image_url'        => wc_placeholder_img_src()
            );
        }

        return $images;
    }

    /**
     * Get the attributes for a product or product variation
     *
     * @since 2.1
     * @param WC_Product|WC_Product_Variation $product
     * @return array
     */
    private function get_attributes( $product ) {

        $attributes = array();

        if ( $product->is_type( 'variation' ) ) {

            // variation attributes
            foreach ( $product->get_variation_attributes() as $attribute_name => $attribute ) {

                // taxonomy-based attributes are prefixed with `pa_`, otherwise simply `attribute_`
                $attributes[] = array(
                    'name'   => ucwords( str_replace( 'attribute_', '', str_replace( 'pa_', '', $attribute_name ) ) ),
                    'option' => $attribute,
                );
            }

        } else {

            foreach ( $product->get_attributes() as $attribute ) {

                // taxonomy-based attributes are comma-separated, others are pipe (|) separated
                if ( $attribute['is_taxonomy'] ) {
                    $options = explode( ',', $product->get_attribute( $attribute['name'] ) );
                } else {
                    $options = explode( '|', $product->get_attribute( $attribute['name'] ) );
                }

                $attributes[] = array(
                    'name'      => ucwords( str_replace( 'pa_', '', $attribute['name'] ) ),
                    'position'  => $attribute['position'],
                    'visible'   => (bool) $attribute['is_visible'],
                    'variation' => (bool) $attribute['is_variation'],
                    'options'   => array_map( 'trim', $options ),
                );
            }
        }

        return $attributes;
    }
}