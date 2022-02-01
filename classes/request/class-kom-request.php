<?php
/**
 * Main request class
 *
 * @package WC_Klarna_Order_Management/Classes/Requests
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base class for all request classes.
 */
abstract class KOM_Request {
	/**
	 * The request method.
	 *
	 * @var string
	 */
	protected $method;

	/**
	 * The request loggable title.
	 *
	 * @var string
	 */
	protected $log_title;

	/**
	 * The Klarna order id.
	 *
	 * @var string
	 */
	protected $klarna_order_id;

	/**
	 * The WC order id.
	 *
	 * @var string
	 */
	protected $order_id;

	/**
	 * The request arguments.
	 *
	 * @var array
	 */
	protected $arguments;

	/**
	 * Class constructor.
	 *
	 * @param array $arguments The request args.
	 */
	public function __construct( $arguments = array() ) {
		$this->arguments = $arguments;
		$this->order_id  = $arguments['order_id'];
	}

	/**
	 * Get which klarna plugin is relevant for this request. Returns false if no Klarna variant seems relevant.
	 *
	 * @return bool|string
	 */
	protected function get_klarna_variant() {
		$order = wc_get_order( $this->order_id );

		if ( ! $order ) {
			return false;
		}

		$payment_method = $order->get_payment_method();
		switch ( $payment_method ) {
			case 'klarna_payments':
			case 'kco':
				return $payment_method;
		}

		return false;

	}

	/**
	 * Gets API region code for Klarna
	 *
	 * @return string
	 */
	protected function get_klarna_api_region() {
		$country = $this->get_klarna_country();
		switch ( $country ) {
			case 'CA':
			case 'US':
				return '-na';
			case 'AU':
			case 'NZ':
				return '-oc';
			default:
				return '';
		}
	}

	/**
	 * Get the country code for the underlaying order.
	 *
	 * @return mixed
	 */
	protected function get_klarna_country() {
		$country = '';
		if ( get_post_meta( $this->order_id, '_wc_klarna_country', true ) ) {
			$country = get_post_meta( $this->order_id, '_wc_klarna_country', true );
		}
		return $country;
	}

	/**
	 * Get the API base URL.
	 *
	 * @return string
	 */
	protected function get_api_url_base() {
		$region     = $this->get_klarna_api_region();
		$playground = $this->use_playground() ? '.playground' : '';
		return "https://api${region}${playground}.klarna.com/";
	}

	/**
	 * Get the full request URL.
	 *
	 * @return string
	 */
	abstract protected function get_request_url();

	/**
	 * Get the arguments for this request.
	 *
	 * @return array
	 */
	abstract protected function get_request_args();

	/**
	 * Make the request.
	 *
	 * @return object|WP_Error
	 */
	public function request() {
		$url      = $this->get_request_url();
		$args     = $this->get_request_args();
		$response = wp_remote_request( $url, $args );
		return $this->process_response( $response, $args, $url );
	}

	/**
	 * Get if this order should use the Klarna Playground or not.
	 *
	 * @return bool
	 */
	protected function use_playground() {
		$playground = true;
		$variant    = $this->get_klarna_variant();
		if ( $variant ) {
			$payment_method_settings = get_option( "woocommerce_${variant}_settings" );
			if ( ! $payment_method_settings || 'yes' == $payment_method_settings['testmode'] ) {
				$playground = false;
			}
		}
		return $playground;
	}

	/**
	 * Calculate basic auth for the request.
	 *
	 * @return string|WP_Error
	 */
	protected function calculate_auth() {
		$variant = $this->get_klarna_variant();
		if ( ! $variant ) {
			return new WP_Error( 'wrong_gateway', 'This order was not create via Klarna Payments or Klarna Checkout for WooCommerce.' );
		}
		$gateway_title = 'kco' === $variant ? 'Klarna Checkout' : 'Klarna Payments';

		$merchant_id   = $this->get_auth_component( 'merchant_id' );
		$shared_secret = $this->get_auth_component( 'shared_secret' );
		if ( '' === $merchant_id || '' === $shared_secret ) {
			return new WP_Error( 'missing_credentials', "${gateway_title} credentials are missing" );
		}
		return 'Basic ' . base64_encode( $merchant_id . ':' . htmlspecialchars_decode( $shared_secret ) );
	}

	/**
	 * Gets the Merchant ID for this request.
	 *
	 * @param string $component_name What auth component to get from settings.
	 * @return string
	 */
	protected function get_auth_component( $component_name ) {
		$component = get_post_meta( $this->order_id, "_wc_klarna_${component_name}", true );
		if ( ! empty( $component ) ) {
			return utf8_encode( $component );
		}

		$variant = $this->get_klarna_variant();
		if ( empty( $variant ) ) {
			return '';
		}
		$options = get_option( "woocommerce_${variant}_settings" );
		if ( ! $options ) {
			return '';
		}

		$prefix  = $this->use_playground() ? 'test_' : '';
		$country = $this->get_klarna_country();
		if ( 'klarna_payments' === $variant ) {
			$country_string = strtolower( $country );
		} else {
			if ( 'US' === $country ) {
				$country_string = 'us';
			} else {
				$country_string = 'eu';
			}
		}

		$key = "${prefix}${component}_${country_string}";

		if ( key_exists( $key, $options ) ) {
			return utf8_encode( $options[ $key ] );
		}
		return '';
	}

	/**
	 * Gets the settings relevant for this request. Returns boolean false if this is not a KCO or KP order.
	 *
	 * @return object|bool
	 */
	protected function get_klarna_settings() {
		$variant = $this->get_klarna_variant();
		if ( ! $variant ) {
			return false;
		}
		return get_option( "woocommerce_${variant}_settings" );
	}

	/**
	 * Processes the response checking for errors.
	 *
	 * @param object|WP_Error $response The response from the request.
	 * @param array           $request_args The request args.
	 * @param string          $request_url The request url.
	 * @return array|WP_Error
	 */
	protected function process_response( $response, $request_args, $request_url ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $response_code < 200 || $response_code >= 300 ) { // Anything not in the 200 range is an error.
			$data          = "URL: ${request_url} - " . wp_json_encode( $request_args );
			$error_message = "API Error ${response_code}";

			if ( null !== $body && property_exists( $body, 'error_messages' ) ) {
				$error_message = join( ' ', $body->error_messages );
			}
			$processed_response = new WP_Error( $response_code, $error_message, $data );
		} else { // Response is *not* an error!
			$processed_response = $body;
		}

		$this->log_response( $response, $request_args, $request_url );
		return $processed_response;
	}

	/**
	 * FIXME: Stub function. Please do the thing!
	 *
	 * @return void
	 */
	protected function log_response() {}
}
