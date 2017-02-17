<?php
/*
 * Plugin Name: Klarna Order Management for WooCommerce
 * Plugin URI: https://krokedil.se/
 * Description: Provides order management for Klarna Payments plugins.
 * Author: Krokedil
 * Author URI: https://krokedil.se/
 * Version: 1.0
 * Text Domain: klarna-order-management-for-woocommerce
 * Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Required minimums and constants
 */
define( 'WC_KLARNA_ORDER_MANAGEMENT_VERSION', '1.0' );
define( 'WC_KLARNA_ORDER_MANAGEMENT_MIN_PHP_VER', '5.3.0' );
define( 'WC_KLARNA_ORDER_MANAGEMENT_MIN_WC_VER', '2.5.0' );
define( 'WC_KLARNA_ORDER_MANAGEMENT_PLUGIN_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );

if ( ! class_exists( 'WC_Klarna_Order_Management' ) ) {

	/**
	 * Class WC_Klarna_Order_Management
	 */
	class WC_Klarna_Order_Management {

		/**
		 * What this class needs to do:
		 *
		 *    Pre-delivery
		 *    ============
		 * 1. Update order amount
		 *    - Updated 'order_amount' must not be negative
		 *    - Updated 'order_amount' must not be less than current 'captured_amount'
		 * 2. (DONE) Cancel an authorized order
		 * 3. (DONE) Retrieve an order
		 * 4. (NOT NOW) Update billing and/or shipping address
		 *    - Fields can be updated independently. To clear a field, set its value to "" (empty string), mandatory fields can not be cleared
		 * 5. (NOT NOW) Update merchant references (update order ID, probably not needed)
		 *    - Update one or both merchant references. To clear a reference, set its value to "" (empty string)
		 *
		 *    Delivery
		 *    ========
		 * 1. (DONE) Capture full amount (and store capture ID as order meta field)
		 *    - 'captured_amount' must be equal to or less than the order's 'remaining_authorized_amount'
		 *    - Shipping address (for the capture) is inherited from the order
		 * 2. (NO) Capture part of the order amount (can't do this in WooCommerce)
		 *
		 *    Post delivery
		 *    =============
		 * 1. (NO) Retrieve a capture
		 * 2. (NO) Add new shipping information ('shipping_info', not address) to a capture (can't do this with WooCommerce alone)
		 * 3. (NOT NOW) Update billing address for a capture (do this when updating WC order after the capture)
		 *    - Fields can be updated independently. To clear a field, set its value to "" (empty string), mandatory fields can not be cleared
		 * 4. (NO) Trigger a new send out of customer communication (no need to do this right away)
		 * 5. (DONE) Refund an amount of a captured order
		 *    - The refunded amount must not be higher than 'captured_amount'
		 *    - The refunded amount can optionally be accompanied by a descriptive text and order lines
		 * 6. (NO) Release the remaining authorization for an order (can't do this, because there's no partial captures)
		 *
		 *    Pending orders
		 *    ==============
		 *  - notification_url is used for callbacks
		 *  - Orders should be set to on hold during checkout if fraud_status: PENDING
		 */


		/**
		 * *Singleton* instance of this class
		 *
		 * @var $instance
		 */
		private static $instance;

		/**
		 * Reference to logging class.
		 *
		 * @var @log
		 *
		 * @TODO: Add logging.
		 */
		private static $log;

		/**
		 * Returns the *Singleton* instance of this class.
		 *
		 * @return self The *Singleton* instance.
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Private clone method to prevent cloning of the instance of the
		 * *Singleton* instance.
		 *
		 * @return void
		 */
		private function __clone() {}

		/**
		 * Private unserialize method to prevent unserializing of the *Singleton*
		 * instance.
		 *
		 * @return void
		 */
		private function __wakeup() {}

		/**
		 * Protected constructor to prevent creating a new instance of the
		 * *Singleton* via the `new` operator from outside of this class.
		 */
		protected function __construct() {
			add_action( 'plugins_loaded', array( $this, 'init' ) );
		}

		/**
		 * Init the plugin at plugins_loaded.
		 */
		public function init() {
			// Do nothing if Klarna Payments plugin is not active.
			if ( ! class_exists( 'WC_Klarna_Payments' ) ) {
				return;
			}

			include_once( WC_KLARNA_ORDER_MANAGEMENT_PLUGIN_PATH . '/includes/wc-klarna-order-management-request.php' );
			include_once( WC_KLARNA_ORDER_MANAGEMENT_PLUGIN_PATH . '/includes/wc-klarna-order-management-order-lines.php' );
			include_once( WC_KLARNA_ORDER_MANAGEMENT_PLUGIN_PATH . '/includes/wc-klarna-pending-orders.php' );

			// Add refunds support to Klarna Payments gateway.
			add_action( 'wc_klarna_payments_supports', array( $this, 'add_gateway_support' ) );

			// Cancel order.
			add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_klarna_order' ) );

			// Capture an order.
			add_action( 'woocommerce_order_status_completed', array( $this, 'capture_klarna_order' ) );

			// Update an order.
			add_action( 'woocommerce_saved_order_items', array( $this, 'update_klarna_order_items' ), 10, 2 );

			// Refund an order.
			add_filter( 'wc_klarna_payments_process_refund', array( $this, 'refund_klarna_order' ), 10, 4 );

			// Pending orders.
			add_action( 'wc_klarna_notification_listener', array( 'WC_Klarna_Pending_Orders', 'notification_listener' ) );
		}

		/**
		 * Add refunds support to Klarna Payments gateway.
		 *
		 * @param array $features Supported features.
		 *
		 * @return array $features Supported features.
		 */
		public function add_gateway_support( $features ) {
			$features[] = 'refunds';

			return $features;
		}

		/**
		 * Instantiate WC_Logger class.
		 *
		 * @param string $message Log message.
		 */
		public static function log( $message ) {
			if ( empty( self::$log ) ) {
				self::$log = new WC_Logger();
			}
			self::$log->add( 'klarna-order-management-for-woocommerce', $message );
		}

		/**
		 * Cancels a Klarna order.
		 *
		 * @param int $order_id Order ID.
		 */
		public function cancel_klarna_order( $order_id ) {
			$order = wc_get_order( $order_id );

			// Not going to do this for non-KP orders.
			if ( 'klarna_payments' !== $order->payment_method ) {
				return;
			}

			// Don't do this if the order is being rejected in pending flow.
			if ( get_post_meta( $order_id, '_wc_klarna_pending_to_cancelled', true ) ) {
				return;
			}

			// Retrieve Klarna order first.
			$request = new WC_Klarna_Order_Management_Request( array(
				'request' => 'retrieve',
				'order_id' => $order_id,
			) );
			$klarna_order = $request->response();

			if ( ! in_array( $klarna_order->status, array( 'CAPTURED', 'PART_CAPTURED', 'CANCELLED' ), true ) ) {
				$request = new WC_Klarna_Order_Management_Request( array(
					'request' => 'cancel',
					'order_id' => $order_id,
					'klarna_order' => $klarna_order,
				) );
				$response = $request->response();

				if ( ! is_wp_error( $response ) ) {
					$order->add_order_note( 'Klarna order cancelled.' );
					add_post_meta( $order_id, '_wc_klarna_cancelled', 'yes', true );
				} else {
					$order->add_order_note( 'Could not cancel Klarna order. ' . $response->get_error_message() . '.' );
				}
			}
		}

		/**
		 * Updates Klarna order items.
		 *
		 * @param int   $order_id Order ID.
		 * @param array $items Order items.
		 */
		public function update_klarna_order_items( $order_id, $items ) {
			if ( ! is_ajax() ) {
				return;
			}

			$order = wc_get_order( $order_id );

			// Not going to do this for non-KP orders.
			if ( 'klarna_payments' !== $order->payment_method ) {
				return;
			}

			// Changes only possible if order is set to On Hold.
			if ( 'on-hold' !== $order->get_status() ) {
				return;
			}

			// Retrieve Klarna order first.
			$request = new WC_Klarna_Order_Management_Request( array(
				'request' => 'retrieve',
				'order_id' => $order_id,
			) );
			$klarna_order = $request->response();

			if ( ! in_array( $klarna_order->status, array( 'CANCELLED', 'CAPTURED', 'PART_CAPTURED' ), true ) ) {
				$request = new WC_Klarna_Order_Management_Request( array(
					'request' => 'update_order_lines',
					'order_id' => $order_id,
					'klarna_order' => $klarna_order,
				) );
				$response = $request->response();

				if ( ! is_wp_error( $response ) ) {
					$order->add_order_note( 'Klarna order updated.' );
				} else {
					$order_note = 'Could not update Klarna order lines.';
					if ( '' !== $response->get_error_message() ) {
						$order_note .= ' ' . $response->get_error_message() . '.';
					}
					$order->add_order_note( $order_note );
				}
			}
		}

		/**
		 * Captures a Klarna order.
		 *
		 * @param int $order_id Order ID.
		 */
		public function capture_klarna_order( $order_id ) {
			$order = wc_get_order( $order_id );

			// Not going to do this for non-KP orders.
			if ( 'klarna_payments' !== $order->payment_method ) {
				return;
			}

			// Do nothing if Klarna order was already captured.
			if ( get_post_meta( $order_id, '_wc_klarna_capture_id', true ) ) {
				return;
			}

			// Do nothing if we don't have Klarna order ID.
			if ( ! get_post_meta( $order_id, '_wc_klarna_order_id', true ) ) {
				return;
			}

			// Retrieve Klarna order first.
			$request = new WC_Klarna_Order_Management_Request( array(
				'request' => 'retrieve',
				'order_id' => $order_id,
			) );
			$klarna_order = $request->response();

			if ( ! in_array( $klarna_order->status, array( 'CAPTURED', 'PART_CAPTURED', 'CANCELLED' ), true ) && 'ACCEPTED' === $klarna_order->fraud_status ) {
				$request = new WC_Klarna_Order_Management_Request( array(
					'request' => 'capture',
					'order_id' => $order_id,
					'klarna_order' => $klarna_order,
				) );
				$response = $request->response();

				if ( ! is_wp_error( $response ) ) {
					$order->add_order_note( 'Klarna order captured. Capture ID: ' . $response );
					add_post_meta( $order_id, '_wc_klarna_capture_id', $response, true );
				} else {
					$order->add_order_note( 'Could not capture Klarna order. ' . $response->get_error_message() . '.' );
				}
			}
		}

		/**
		 * Refund a Klarna order.
		 *
		 * @param bool        $result   Refund attempt result.
		 * @param int         $order_id WooCommerce order ID.
		 * @param null|string $amount   Refund amount, full order amount if null.
		 * @param string      $reason   Refund reason.
		 *
		 * @return bool $result Refund attempt result.
		 */
		public function refund_klarna_order( $result, $order_id, $amount = null, $reason = '' ) {
			$order = wc_get_order( $order_id );

			// Not going to do this for non-KP orders.
			if ( 'klarna_payments' !== $order->payment_method ) {
				return false;
			}

			// Do nothing if Klarna order was already captured.
			if ( ! get_post_meta( $order_id, '_wc_klarna_capture_id', true ) ) {
				return false;
			}

			// Retrieve Klarna order first.
			$request = new WC_Klarna_Order_Management_Request( array(
				'request' => 'retrieve',
				'order_id' => $order_id,
			) );
			$klarna_order = $request->response();

			if ( in_array( $klarna_order->status, array( 'CAPTURED', 'PART_CAPTURED' ), true ) ) {
				$request = new WC_Klarna_Order_Management_Request( array(
					'request'       => 'refund',
					'order_id'      => $order_id,
					'klarna_order'  => $klarna_order,
					'refund_amount' => $amount,
					'refund_reason' => $reason,
				) );
				$response = $request->response();

				if ( ! is_wp_error( $response ) ) {
					$order->add_order_note( wc_price( $amount, array( 'currency' => get_post_meta( $order_id, '_order_currency', true ) ) ) . ' refunded via Klarna.' );
					add_post_meta( $order_id, '_wc_klarna_capture_id', $response, true );

					return true;
				} else {
					$order->add_order_note( 'Could not capture Klarna order. ' . $response->get_error_message() . '.' );
				}
			}

			return false;
		}

	}

	WC_Klarna_Order_Management::get_instance();

}