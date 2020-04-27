<?php

namespace NOLEAM\SHOP;

/***
 * Plugin Name: WooCommerce Cheques Gateway
 * Description: WooCommerce Gateway for multi cheques payment.
 * Version: 0.1
 * Text Domain: noleam
 * Domain Path: /languages/
 * Author: Christian Denat
 * Author URI: https://www.noleam.fr
 *
 * Copyright 2020 Christian Denat - Noleam.fr
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
	return;
}

use DateTimeZone;
use WC_DateTime;
use WC_Order;
use WC_Payment_Gateway;
use function apply_filters;

add_action( 'plugins_loaded', function () {

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	if ( ! defined( 'NOLEAM_CHEQUES_ID' ) ) {
		define( 'NOLEAM_CHEQUES_ID', 'ncheques' );
	}
	if ( ! defined( 'NOLEAM_CHEQUES_DEFAULTS_PAYMENTS' ) ) {
		define( 'NOLEAM_CHEQUES_DEFAULTS_PAYMENTS', [ 1, 2, 3, 4, 10 ] );
	}

	/**
	 * 3 Cheques Payment Gateway.
	 *
	 *  From WC_Gateway_Cheque
	 *
	 */
	class WC_Gateway_cheques extends WC_Payment_Gateway {

		private string $instructions;
		private string $store_address;
		public string $available_payments;
		public string $available_thresholds;

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
			$this->id                 = NOLEAM_CHEQUES_ID;
			$this->icon               = apply_filters( 'woocommerce_cheque_icon', '' );
			$this->has_fields         = true;
			$this->method_title       = __( 'Cheques', 'noleam' );
			$this->method_description = __( 'Depending on cart total, customers will be able to pay by one or more cheques', 'noleam' );

			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables.
			$this->title        = $this->get_option( 'title' );
			$this->instructions = $this->get_option( 'instructions' );

			// Store_address
			$this->store_address = $this->get_option( 'store-address' );
			// Possible number of payments
			$this->available_payments = $this->get_option( 'available-payments' );
			// Thresholds
			$this->available_thresholds = $this->get_option( 'available-thresholds' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
				$this,
				'process_admin_options'
			) );

			// Load style.
			add_action( 'wp_enqueue_scripts', [ $this, 'cheques_style' ] );

			// Save metas
			add_action( 'woocommerce_checkout_create_order', [ $this, 'prepare_and_save_data' ], 10, 2 );

			// Add instructions in the checkout page
			add_action( 'woocommerce_thankyou_' . NOLEAM_CHEQUES_ID, [ $this, 'thankyou_page' ] );

			// Add instructions view order page
			add_action( 'woocommerce_view_order', [ $this, 'thankyou_page' ] );

			// And in Email
			add_action( 'woocommerce_email_before_order_table', [ $this, 'email_instructions' ], 10, 1 );

		}

		/**
		 * style the gateway rendering
		 */
		public function cheques_style(): void {
			wp_enqueue_style( 'cheques-style', plugin_dir_url( __FILE__ ) . 'assets/css/style.css', false );
		}

		/**
		 * Initialise Gateway Settings Form Fields.
		 *
		 * @since 1.0
		 *
		 */

		public function init_form_fields(): void {

			$this->form_fields = [
				'enabled'              => [
					'title'   => __( 'Enable/Disable', 'woocommerce' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable cheques payment', 'noleam' ),
					'default' => 'no',
				],
				'title'                => [
					'title'       => __( 'Title', 'woocommerce' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
					'default'     => _x( 'Cheque payment', 'Cheques payment', 'woocommerce' ),
					'desc_tip'    => true,
				],
				'description'          => [
					'title'       => __( 'Description', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
					'default'     => __( 'You can pay by cheques', 'noleam' ),
					'desc_tip'    => true,
				],
				'instructions'         => [
					'title'       => __( 'Instructions', 'woocommerce' ),
					'type'        => 'textarea',
					'description' => __( 'Additionals instructions.', 'woocommerce' ),
					'default'     => __( 'Check(s) should be made payable to ', 'noleam' ) . WC()->countries->get_base_address() . '.',
					'desc_tip'    => true,
				],
				'store-address'        => [
					'title'       => __( 'Store address', 'noleam' ),
					'type'        => 'textarea',
					'description' => __( 'Enter/modify the store address.', 'noleam' ),
					'default'     => $this->default_store_address(),
					'desc_tip'    => true,
				],
				'available-payments'   => [
					'title'       => __( 'Available payments by cheque', 'noleam' ),
					'type'        => 'text',
					'description' => __( 'Enter available payments separated by comma .', 'noleam' ),
					'default'     => implode( ',', NOLEAM_CHEQUES_DEFAULTS_PAYMENTS ),
					'desc_tip'    => true,
				],
				'available-thresholds' => [
					'title'       => __( 'Available thresholds for payments', 'noleam' ),
					'type'        => 'text',
					'description' => __( 'For each (or less) payments listed above, enter threshold that will trigger this payment (separated by commas)', 'noleam' ),
					'default'     => '',
					'desc_tip'    => true,
				],
			];
		}

		/**
		 * Display the gateway form in the Cart page
		 *
		 * @since 1.0
		 */

		public function payment_fields(): void {
			$payments = explode( ',', $this->available_payments );
			?>

            <fieldset id="<?php echo esc_attr( $this->id ); ?>-cc-form" class="wc-payment-form">
                <p class="form-row form-row-first">
					<?php if ( count( $payments ) === 1 ) { ?>
						<?= sprintf( __( 'The payment will be done in %s.', 'noleam' ), "&nbsp;<strong>$payments[0]</strong>&nbsp;" . _n( 'cheque', 'cheques', $payments[0], 'noleam' ) ) ?>
					<?php } else { ?>
                        <label for="<?= esc_attr( $this->id ) ?>-number-payments">
							<?= esc_html__( 'Number of cheques :', 'noleam' ) ?>&nbsp;
                        </label>
                        <select id="<?= esc_attr( $this->id ) ?>-number-payments"
                                name="<?= esc_attr( $this->id ) ?>-number-payments">
							<?php foreach ( $payments as $payment ) { ?>
                                <option><?= $payment ?></option>
							<?php } ?>
                        </select>
					<?php } ?>
                </p>
                <div class="clear"></div>
            </fieldset>

			<?php
		}

		/**
		 * Output for the order received page.
		 *
		 * @param int $order_id
		 *
		 * @since 1.0
		 */
		public function thankyou_page( $order_id ): void {
			$order = wc_get_order( $order_id );

			$gateway = wc_get_payment_gateway_by_order( $order_id );
			if ( NOLEAM_CHEQUES_ID === $order->get_payment_method() ) {
				echo wp_kses_post( wpautop( wptexturize( $this->instruction_for_payments( $order ) ) ) );
			}
		}

		public function email_instructions( WC_Order $order ): void {
			$this->thankyou_page( $order->get_id() );
		}

		/**
		 * Process the payment and return the result.
		 *
		 * @param int $order_id Order ID.
		 *
		 * @return array
		 */
		public function process_payment( $order_id ): array {

			$order = wc_get_order( $order_id );

			if ( $order->get_total() > 0 ) {
				// Mark as on-hold (we're awaiting the cheque).
				$order->update_status( apply_filters( 'woocommerce_cheques_process_payment_order_status', 'on-hold', $order ), _x( 'Awaiting checks payment', 'Checks payment method', 'noleam' ) );
			} else {
				$order->payment_complete();
			}

			// Remove cart.
			WC()->cart->empty_cart();

			// Return thankyou redirect.
			return [
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			];
		}


		/**
		 * Calculate the amount of each cheque.
		 *
		 * @param float $total : Total amount of order
		 * @param int $payments : number of cheques
		 *
		 * @return array
		 *              ['first'] : amount of 1st cheque
		 *              ['next'] : following amount (all equals)
		 *
		 * @since 1.0
		 *
		 */

		private function cheques_amount( float $total, int $payments ): array {
			// percentage of last payments.
			$last_payments  = [
				1  => 1,
				2  => 0.5,
				3  => 0.33,
				4  => 0.25,
				10 => 0.1
			];
			$amount         = [];
			$amount['next'] = 0;
			if ( $payments > 1 ) {
				// The last payments are rounded percentage of the amount
				$amount['next'] = round( $total * $last_payments[ $payments ] );
			}

			// First is then the rest or total if payments = 1
			$amount['first'] = $total - ( $payments - 1 ) * $amount['next'];

			return $amount;
		}

		/**
		 * Calculate the date of each bank deposit  (1st is ASAP)
		 *
		 * @param int $payments : number of cheques
		 *
		 * @return array : dates for Bank deposits (@WC_DateTime)
		 *
		 * @since 1.0
		 *
		 */

		private function cheque_deposits_date( int $payments ): array {

			$dates[0] = '';
			for ( $p = 1; $p < $payments; $p ++ ) {
				$timestamp   = strtotime( "+$p month" );
				$dates[ $p ] = new WC_DateTime( "@{$timestamp}", new DateTimeZone( 'UTC' ) );
				$dates[ $p ]->setTimezone( new DateTimeZone( wc_timezone_string() ) );
			}

			return $dates;
		}

		/**
		 * Get payments, compute amounts and dates then save them in meta
		 *
		 * @param $order
		 * @param $data of the checkout form
		 */
		public function prepare_and_save_data( WC_Order $order, array $data ): void {
			// Only for this gateway
			if ( isset( $_POST['payment_method'] ) && NOLEAM_CHEQUES_ID === $_POST['payment_method'] ) {
				$meta     = NOLEAM_CHEQUES_ID . '-number-payments';
				$payments = (int) $_POST[ $meta ];
				$order->update_meta_data( '_' . $meta, $payments );

				//Amount of cheques
				$order->update_meta_data( '_' . NOLEAM_CHEQUES_ID . '-amounts-payments', $this->cheques_amount( $order->get_total(), $payments ) );
				// Date of cheques
				$order->update_meta_data( '_' . NOLEAM_CHEQUES_ID . '-dates-payments', $this->cheque_deposits_date( $payments ) );
			}
		}

		/**
		 * Build the instruction section the customer will see in email and checkouts
		 *
		 * @param WC_Order $order : the current order
		 * @param int $payments : number of payments
		 *
		 * @return string : the text to display
		 *
		 * @since 1.0
		 *
		 */

		public function instruction_for_payments( WC_Order $order ): string {

			// number of payments
			$payments = (int) $order->get_meta( '_' . NOLEAM_CHEQUES_ID . '-number-payments' );
			//Amount of cheques
			$cheques = $order->get_meta( '_' . NOLEAM_CHEQUES_ID . '-amounts-payments' );
			// Date of cheques
			$dates = $order->get_meta( '_' . NOLEAM_CHEQUES_ID . '-dates-payments', );

			ob_start(); ?>
            <section class="woocommerce cheques-payment-instructions">
                <h2><?= __( 'Instructions regarding your payments', 'noleam' ) ?></h2>
                <div class="cheques-payment-instructions-content">
                    <p><?= sprintf( _n( 'You have chosen to pay by cheque.', 'You have chosen to pay with <strong>%s cheques</strong>', $payments, 'noleam' ), $payments ) ?>
                        .&nbsp;
						<?= $this->instructions ?>
                    </p>
                    <ul>
                        <li class="cheques-payment-address">
                            <p><?= sprintf( _n( 'Please send your cheque at', 'Please send all the cheques at', $payments, 'noleam' ), $payments ) ?></p>
                            <address><?= wp_kses_post( wpautop( wptexturize( ( $this->store_address ) ?: $this->default_store_address() ) ) ) ?></address>
                        </li>
                        <li class="cheques-amount-date">
							<?php if ( $payments === 1 ) { ?>
                                <p><?= sprintf( __( 'Your cheque for %s will be debited once received.', 'noleam' ), wc_price( $cheques['first'] ) ) ?></p>
							<?php } else { ?>
                                <p><?= sprintf( __( 'One cheque for %s (debited once received).', 'noleam' ), wc_price( $cheques['first'] ) ) ?></p>
								<?php if ( $payments === 2 ) { ?>
                                    <p class="cheques-amount-unic">
										<?= sprintf( __( 'The next cheque (%s) will be debited on ', 'noleam' ), wc_price( $cheques['next'] ) ) ?>
                                        <span><?= wc_format_datetime( $dates[1] ) ?></span>
                                    </p>
								<?php } else { ?>
                                    <p>
										<?= sprintf( __( '%s cheques for %s each.', 'noleam' ), $payments - 1, wc_price( $cheques['next'] ) ) ?>
                                    <ul class="cheques-amount">
										<?php for ( $p = 1; $p < $payments; $p ++ ) { ?>
                                            <li><?= sprintf( __( '%s debited on <strong>%s</strong>', 'noleam' ), wc_price( $cheques['next'] ), wc_format_datetime( $dates[ $p ] ) ) ?></li>
										<?php } ?>
                                    </ul>
                                    </p>
								<?php } ?>

							<?php } ?>
                        </li>
                    </ul>
                    <p><?= sprintf( _n( 'Thank you. Your order will be validated once we\'ll receive the cheque.', 'Thank you. The order will be validated once we\'ll receive the %s cheques.', $payments, 'noleam' ), $payments ) ?></p>
                </div>
            </section>
			<?php
			return ob_get_clean();
		}

		/**
		 * Display the default store address (ie based on Wocommerce settings)
		 *
		 * @since 1.0
		 *
		 */
		private function default_store_address() {
			ob_start() ?>
			<?= ( WC()->countries->get_base_address() ) ?><?= WC()->countries->get_base_address() ? PHP_EOL : '' ?>
			<?= WC()->countries->get_base_address_2() ?><?= WC()->countries->get_base_address_2() ? PHP_EOL : '' ?>
			<?= WC()->countries->get_base_city() ?><?= WC()->countries->get_base_city() ? PHP_EOL : '' ?>
			<?= WC()->countries->get_base_postcode() ?><?= WC()->countries->get_base_postcode() ? PHP_EOL : '' ?>
			<?= WC()->countries->get_base_state() ?><?= WC()->countries->get_base_state() ? PHP_EOL : '' ?>
			<?= WC()->countries->countries[ WC()->countries->get_base_country() ] ?><?= WC()->countries->countries[ WC()->countries->get_base_country() ] ? PHP_EOL : '' ?>
			<?php return preg_replace( '/[ \t]{2,}/', '', ob_get_clean() );
		}
	}

	// It's time to add the Gateway to woocommerce
	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'NOLEAM\SHOP\WC_Gateway_cheques';

		return $gateways;
	} );

	// and to perform some internationalisation
	load_plugin_textdomain( 'noleam', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

	/**
	 * This filter is triggered by the checkout page.
	 *
	 * Depending on the price, payments number and threshold, we coalculate the avalable payments.
	 * If there is no payment, we remove the gateway from the cart list
	 *
	 * @param array : the available gateways
	 *
	 * @since 1.0
	 *
	 */

	add_filter( 'woocommerce_available_payment_gateways', function ( $gateways ) {
		global $woocommerce;
		// Cart total
		$total = $woocommerce->cart->total;
		// Checks all enabled gateways
		foreach ( $gateways as $gateway ) {
			// If there is our

			if ( NOLEAM_CHEQUES_ID === $gateway->id ) {
				// bail early if no thresholds
				if ( '' === $gateway->available_thresholds ) {
					return $gateways;
				}
				// Get payments and threshold
				$payments = explode( ',', $gateway->available_payments );
				$p_len    = count( $payments );

				$available_payments = array_fill( 0, $p_len - 1, false );
				$thresholds         = explode( ',', $gateway->available_thresholds );
				$t_len              = count( $thresholds );


				// Compare total to the different threshold and set a value for
				// the corresponding payment if it is ok

				for ( $t = 0; $t < $t_len; $t ++ ) {
					// nominal case
					if ( ( isset( $thresholds[ $t ] ) && isset( $payments[ $t ] ) ) && (float) $total >= (float) $thresholds[ $t ] ) {
						$available_payments[ $t ] = $payments[ $t ];
					}
				}
				// in case there are more payments than thresholds,
				for ( $p = $t; $p < $p_len; $p ++ ) {
					//  we force them to the last false or keep the right value
					$available_payments[ $p ] = ! $available_payments[ $t - 1 ] ? false : $payments[ $p ];
				}

				// We suppress all the 'false' payments
				$payments = array_filter( $available_payments, function ( $e ) {
					return $e !== false;
				} );

				if ( count( $payments ) > 0 ) {
					// We update payments array if there are some
					$gateways[ NOLEAM_CHEQUES_ID ]->available_payments = implode( ',', $payments );
				} else {
					// otherwise, we remove the gateway from the list
					unset( $gateways[ NOLEAM_CHEQUES_ID ] );
				}

				// and exit
				return $gateways;
			}
		}

		// Nothing happens, bye
		return $gateways;

	}, 9999, 1 );


} );
