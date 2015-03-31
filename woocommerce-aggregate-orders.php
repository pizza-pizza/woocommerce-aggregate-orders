<?php
/*----------------------------------------------------------------------------------------------------------------------
Plugin Name: WooCommerce Aggregate Order Invoicing
Description: Generate single invoices for groups of orders. Creates a new 'invoiced' order type.
Version: 1.5.0
Author: New Order Studios
Author URI: http://neworderstudios.com
----------------------------------------------------------------------------------------------------------------------*/

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) {
    exit;
}

if ( is_admin() && !class_exists( 'wcAggregateOrders' ) ) {

	class wcAggregateOrders {

		public function __construct() {

			load_plugin_textdomain( 'woocommerce-aggregate-orders', false, basename( dirname(__FILE__) ) . '/i18n' );
			add_action( 'admin_footer', array( $this, 'add_merge_options' ) );
			add_action( 'load-edit.php', array( $this, 'merge_orders' ) );
			add_action( 'init', array( $this, 'register_invoice_order_statuses' ) );
			add_action( 'parse_query', array( $this, 'filter_orders' ) );
			add_action( 'admin_menu', array( $this, 'add_invoices_link' ) );
			add_action( 'manage_shop_order_posts_custom_column', array( $this, 'designate_merged_orders' ), 20 );

			add_filter( 'wc_order_statuses', array( $this, 'add_invoiced_to_order_statuses') );
			add_filter( 'woocommerce_screen_ids', array( $this, 'add_wc_screen_id') );

		}

		/**
		* Let's make a new status for invoiced orders
		*/
		public function register_invoice_order_statuses() {

		    register_post_status( 'wc-invoiced', array(
		        'label'                     => __( 'Invoiced', 'woocommerce-aggregate-orders' ),
		        'public'                    => false,
		        'exclude_from_search'       => false,
		        'show_in_admin_all_list'    => true,
		        'show_in_admin_status_list' => true,
		        'label_count'               => _n_noop( __( 'Invoiced', 'woocommerce-aggregate-orders' )
		        	. ' <span class="count">(%s)</span>', __( 'Invoiced', 'woocommerce-aggregate-orders' )
		        	. ' <span class="count">(%s)</span>' )
		    ) );

		}

		/**
		* @param array $order_statuses
		* We want WC to be aware of our "invoiced" status
		*/
		function add_invoiced_to_order_statuses( $order_statuses ) {

			$new_order_statuses = array();

			foreach ( $order_statuses as $key => $status ) {
				$new_order_statuses[$key] = $status;
				if ( 'wc-pending' === $key ) $new_order_statuses['wc-invoiced'] = 'Invoiced';
			}

			return $new_order_statuses;

		}

		/**
		 * Let's add some JS to append a merge option on the bulk actions list.
		 */
		public function add_merge_options() {

			global $post_type;

			if ( $post_type == 'shop_order' && @$_REQUEST['page'] != 'invoice-orders' ) {
				?>
				<script type="text/javascript">
				jQuery('document').ready(function($){
					$('<option>').val('merge_orders').text('<?php _e( 'Merge Orders for Invoicing', 'woocommerce-aggregate-orders' ) ?>').appendTo("select[name='action'],select[name='action2']");

					// While we're in here, let's give some color to our merged orders
					$('.merged-order').each(function(){
						$(this).parents('tr').find('td').css('color','#0000ff');
					});

					// We should also hide the PDF invoice links on the main orders screen
					$('.button img[src*="download.png"]').each(function(){
						$(this).parent().hide();
					});

					if(!($('input[value="aggregate"]').length && $('input[value="aggregate"]').parent().next().find('textarea').val() == 1)){
						$('#woo_pdf_metabox .inside').html('<?php _e( 'Invoices cannot be generated for unmerged orders.', 'woocommerce-aggregate-orders' ) ?>');
					}
				});
				</script>
				<?php
			}

		}

		/**
		 * Let's merge some selected orders.
		 */
		public function merge_orders() {

			$wp_list_table = _get_list_table( 'WP_Posts_List_Table' );
			$action = $wp_list_table->current_action();
			
			// Do we want to get involved?
			if ( strpos( $action, 'merge_orders' ) === false ) return;

			// Yes.
			$post_ids = array_map( 'absint', (array) $_REQUEST['post'] );
			if( count($post_ids) < 2 ) {
				add_action( 'admin_notices', array( $this, 'insufficient_posts' ) );
				return;
			}

			$merged = wc_create_order();
			$orders = array();
			$taxes = $ship = $bill = array();

			foreach ( $post_ids as $post_id ) {
				$order = wc_get_order( $post_id );
				update_post_meta( $order->id, 'merged', true );

				$ship = @$ship['address_1'] ? $ship : array(
					'first_name' => $order->shipping_first_name,
					'last_name'  => $order->shipping_last_name,
					'company'    => $order->shipping_company,
					'email'      => $order->shipping_email,
					'phone'      => $order->shipping_phone,
					'address_1'  => $order->shipping_address_1,
					'address_2'  => $order->shipping_address_2,
					'city'       => $order->shipping_city,
					'state'      => $order->shipping_state,
					'postcode'   => $order->shipping_postcode,
					'country'    => $order->shipping_country
		        );

				$bill = @$bill['address_1'] ? $bill : array(
					'first_name' => $order->billing_first_name,
					'last_name'  => $order->billing_last_name,
					'company'    => $order->billing_company,
					'email'      => $order->billing_email,
					'phone'      => $order->billing_phone,
					'address_1'  => $order->billing_address_1,
					'address_2'  => $order->billing_address_2,
					'city'       => $order->billing_city,
					'state'      => $order->billing_state,
					'postcode'   => $order->billing_postcode,
					'country'    => $order->billing_country
		        );

				// We'd like to aggregate taxes applied to each order.
				foreach ( $order->get_taxes() as $tax ) {
					$taxes[$tax['rate_id']] = @$taxes[$tax['rate_id']] ? $taxes[$tax['rate_id']] + $tax['tax_amount'] : $tax['tax_amount'];
				}

				// Let's make WC think that we're adding product line items.
				$item_id = wc_add_order_item( $merged->id, array(
					'order_item_name' => substr( $order->order_date, 0, 10 ) . ': #' . $post_id,
					'order_item_type' => 'line_item'
				) );

				wc_add_order_item_meta( $item_id, '_qty',			1 );
				wc_add_order_item_meta( $item_id, '_variation_id',	0 );
				wc_add_order_item_meta( $item_id, '_tax_class',		0 );
				wc_add_order_item_meta( $item_id, '_line_subtotal',	$order->get_total() );
				wc_add_order_item_meta( $item_id, '_line_total',	$order->get_total() );
				wc_add_order_item_meta( $item_id, '_line_tax',		0 );

			}

			$merged->set_address( $bill, 'billing' );
	        $merged->set_address( $ship, 'shipping' );

	        foreach ( $taxes as $rate_id => $amount ) $merged->add_tax( $rate_id, $amount );

	        /*
	        TODO: let's add a note describing the date range of which this aggregate is comprised.
	        $merged->add_order_note( __( 'Merged from orders #', 'woocommerce-aggregate-orders' ) . implode( ', #', $post_ids ) );
	        */

	        // Crucially, we are not interested in calculating tax rates here-- we've already done this manually.
	        $merged->calculate_totals( false );
	        
	        update_post_meta( $merged->id, 'aggregate', true );
	        update_post_meta( $merged->id, '_customer_user', $order->get_user_id() );

		}

		/**
		* Filter orders on aggregate meta
		* @param WP_Query $wp
		*/
		public function filter_orders( $wp ) {

			global $pagenow, $wpdb;

			if ( 'edit.php' != $pagenow || $wp->query_vars['post_type'] != 'shop_order' ) return;
			
			if ( @$_REQUEST['aggregate'] ) {
				$wp->query_vars['meta_key'] = 'aggregate';
				$wp->query_vars['meta_value'] = true;
				$wp->query_vars['meta_compare'] = '=';
			} else {
				$wp->query_vars['meta_key'] = 'aggregate';
				$wp->query_vars['meta_value'] = '';
				$wp->query_vars['meta_compare'] = 'NOT EXISTS';
			}

		}

		/**
		* We want to make sure that the WC styles & scripts are registered on our new invoice page
		* @param array $screens
		*/
		public function add_wc_screen_id( $screens ) {

			$screens[] = 'woocommerce_page_invoice-orders';
			return $screens;

		}

		/**
		* Let's add a menu item for our aggregate orders page 
		*/
		public function add_invoices_link() {

			add_submenu_page( 'woocommerce', __( 'Invoice Orders', 'woocommerce-aggregate-orders' ), __( 'Invoice Orders', 'woocommerce-aggregate-orders' ), 'edit_posts', 'invoice-orders', array( $this, 'show_aggregate_orders' ) );

		}

		/**
		* Let's build our aggregate orders page by convincing WP to render a normal order list containing the aggregates
		*/
		public function show_aggregate_orders() {

			global $wp_query, $wpdb;
			set_current_screen( 'edit-shop_order' );

			$args = array(
				'numberposts' => -1,
				'post_type' => 'shop_order',
				'post_status' => array_keys( wc_get_order_statuses() ),
				'meta_key' => 'aggregate',
				'meta_value' => true

			);
			$wp_query = new WP_Query( $args );

			$orders = _get_list_table('WP_Posts_List_Table');
			$orders->prepare_items();
			?>
			<div class="wrap">
				<h2><?php _e( 'Invoice Orders', 'woocommerce-aggregate-orders' ); ?></h2>

				<?php $orders->display(); ?>

			</div>
			<?php

		}

		/**
		* If an order has been merged, let's give our friends a little hint.
		* @param string $column
		*/
		public function designate_merged_orders( $column ) {

			global $post, $woocommerce, $the_order;
			if ( $column == 'order_title' && get_post_meta( $the_order->id, 'merged', true ) ) echo '<span class="merged-order"></span>';

		}

		/**
		* Listen, you need to check some more boxes.
		*/
		public function insufficient_posts() {

		    ?>
		    <div class="warning">
		        <p><?php _e( 'You must select at least two orders to merge.', 'woocommerce-aggregate-orders' ); ?></p>
		    </div>
		    <?php

		}

	}

	new wcAggregateOrders();
}