<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}
require_once 'class-sf-resource.php';

class SF_Order extends SF_Resource
{
    protected $_categories = array();

    public function __construct()
    {
    }

    /**
     * Get all orders
     *
     * @since    2.1
     * @param int    $page
     * @param int    $num_per_page
     * @param string $order_status
     * @return array
     */
    public function get_orders( $page = null, $num_per_page = 1000, $order_status = 'completed' ) {

        $args['status'] = $order_status;

        // set base query arguments
        $query_args = array(
            'fields'      => 'ids',
            'post_type'   => 'shop_order',
            'post_status' => array_keys( wc_get_order_statuses() )
        );

        // add status argument
        if ( ! empty( $args['status'] ) ) {

            $statuses                  = 'wc-' . str_replace( ',', ',wc-', $args['status'] );
            $statuses                  = explode( ',', $statuses );
            $query_args['post_status'] = $statuses;

            unset( $args['status'] );

        }

        //$query_args['is_paged'] = true;
        $args['page'] = $page;
        $args['limit'] = $num_per_page;
        if ( ! empty( $status ) )
            $args['status'] = $status;


        $query_args = $this->merge_query_args( $query_args, $args );

        $query = new WP_Query( $query_args );

        $orders = array();

        foreach( $query->posts as $order_id ) {

            $orders[] = $this->get_order( $order_id );
        }

        return $orders;
    }


    /**
     * Get the order for the given ID
     *
     * @since 2.1
     * @param int $id the order ID
     * @param array $fields
     * @param array $filter
     * @return array
     */
    public function get_order( $id, $fields = null, $filter = array() ) {

        $order = wc_get_order( $id );

        $order_post = get_post( $id );

        $order_data = array(
            'id'                        => $order->id,
            'order_number'              => $order->get_order_number(),
            'created_at'                => ShoppingFeeder::format_datetime($order_post->post_date_gmt),
            'updated_at'                => ShoppingFeeder::format_datetime($order_post->post_modified_gmt),
            'completed_at'              => ShoppingFeeder::format_datetime($order->completed_date, true),
            'status'                    => $order->get_status(),
            'currency'                  => $order->get_order_currency(),
            'total'                     => wc_format_decimal( $order->get_total(), 2 ),
            'subtotal'                  => wc_format_decimal( $order->get_subtotal( $order ), 2 ),
            'total_line_items_quantity' => $order->get_item_count(),
            'total_tax'                 => wc_format_decimal( $order->get_total_tax(), 2 ),
            'total_shipping'            => wc_format_decimal( $order->get_total_shipping(), 2 ),
            'cart_tax'                  => wc_format_decimal( $order->get_cart_tax(), 2 ),
            'shipping_tax'              => wc_format_decimal( $order->get_shipping_tax(), 2 ),
            'total_discount'            => wc_format_decimal( $order->get_total_discount(), 2 ),
            'cart_discount'             => wc_format_decimal( $order->get_cart_discount(), 2 ),
            'order_discount'            => wc_format_decimal( $order->get_order_discount(), 2 ),
            'shipping_methods'          => $order->get_shipping_method(),
            'payment_details' => array(
                'method_id'    => $order->payment_method,
                'method_title' => $order->payment_method_title,
                'paid'         => isset( $order->paid_date ),
            ),
            'billing_address' => array(
                'first_name' => $order->billing_first_name,
                'last_name'  => $order->billing_last_name,
                'company'    => $order->billing_company,
                'address_1'  => $order->billing_address_1,
                'address_2'  => $order->billing_address_2,
                'city'       => $order->billing_city,
                'state'      => $order->billing_state,
                'postcode'   => $order->billing_postcode,
                'country'    => $order->billing_country,
                'email'      => $order->billing_email,
                'phone'      => $order->billing_phone,
            ),
            'shipping_address' => array(
                'first_name' => $order->shipping_first_name,
                'last_name'  => $order->shipping_last_name,
                'company'    => $order->shipping_company,
                'address_1'  => $order->shipping_address_1,
                'address_2'  => $order->shipping_address_2,
                'city'       => $order->shipping_city,
                'state'      => $order->shipping_state,
                'postcode'   => $order->shipping_postcode,
                'country'    => $order->shipping_country,
            ),
            'note'                      => $order->customer_note,
            'customer_ip'               => $order->customer_ip_address,
            'customer_user_agent'       => $order->customer_user_agent,
            'customer_id'               => $order->customer_user,
            'view_order_url'            => $order->get_view_order_url(),
            'line_items'                => array(),
            'shipping_lines'            => array(),
            'tax_lines'                 => array(),
            'fee_lines'                 => array(),
            'coupon_lines'              => array(),
        );

        // add line items
        foreach( $order->get_items() as $item_id => $item ) {

            $product = $order->get_product_from_item( $item );

            $meta = new WC_Order_Item_Meta( $item['item_meta'], $product );

            $item_meta = array();

            $hideprefix = ( isset( $filter['all_item_meta'] ) && $filter['all_item_meta'] === 'true' ) ? null : '_';

            foreach ( $meta->get_formatted( $hideprefix ) as $meta_key => $formatted_meta ) {
                $item_meta[] = array(
                    'key' => $meta_key,
                    'label' => $formatted_meta['label'],
                    'value' => $formatted_meta['value'],
                );
            }

            $order_data['line_items'][] = array(
                'id'           => $item_id,
                'subtotal'     => wc_format_decimal( $order->get_line_subtotal( $item ), 2 ),
                'subtotal_tax' => wc_format_decimal( $item['line_subtotal_tax'], 2 ),
                'total'        => wc_format_decimal( $order->get_line_total( $item ), 2 ),
                'total_tax'    => wc_format_decimal( $order->get_line_tax( $item ), 2 ),
                'price'        => wc_format_decimal( $order->get_item_total( $item ), 2 ),
                'quantity'     => (int) $item['qty'],
                'tax_class'    => ( ! empty( $item['tax_class'] ) ) ? $item['tax_class'] : null,
                'name'         => $item['name'],
                'product_id'   => $product->id,
                'variant_id'   => ( isset( $product->variation_id ) ) ? $product->variation_id : null,
                'sku'          => is_object( $product ) ? $product->get_sku() : null,
                'meta'         => $item_meta,
            );
        }

        // add shipping
        foreach ( $order->get_shipping_methods() as $shipping_item_id => $shipping_item ) {

            $order_data['shipping_lines'][] = array(
                'id'           => $shipping_item_id,
                'method_id'    => $shipping_item['method_id'],
                'method_title' => $shipping_item['name'],
                'total'        => wc_format_decimal( $shipping_item['cost'], 2 ),
            );
        }

        // add taxes
        foreach ( $order->get_tax_totals() as $tax_code => $tax ) {

            $order_data['tax_lines'][] = array(
                'id'       => $tax->id,
                'rate_id'  => $tax->rate_id,
                'code'     => $tax_code,
                'title'    => $tax->label,
                'total'    => wc_format_decimal( $tax->amount, 2 ),
                'compound' => (bool) $tax->is_compound,
            );
        }

        // add fees
        foreach ( $order->get_fees() as $fee_item_id => $fee_item ) {

            $order_data['fee_lines'][] = array(
                'id'        => $fee_item_id,
                'title'     => $fee_item['name'],
                'tax_class' => ( ! empty( $fee_item['tax_class'] ) ) ? $fee_item['tax_class'] : null,
                'total'     => wc_format_decimal( $order->get_line_total( $fee_item ), 2 ),
                'total_tax' => wc_format_decimal( $order->get_line_tax( $fee_item ), 2 ),
            );
        }

        // add coupons
        foreach ( $order->get_items( 'coupon' ) as $coupon_item_id => $coupon_item ) {

            $order_data['coupon_lines'][] = array(
                'id'     => $coupon_item_id,
                'code'   => $coupon_item['name'],
                'amount' => wc_format_decimal( $coupon_item['discount_amount'], 2 ),
            );
        }

        return $order_data;
    }
}