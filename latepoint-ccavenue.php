<?php
/**
 * Plugin Name: LatePoint Addon - Payments CCAvenue
 * Plugin URI:  https://latepoint.com/
 * Description: LatePoint addon for payments via CCAvenue
 * Version:     1.0.0
 * Author:      LatePoint
 * Author URI:  https://latepoint.com/
 * Text Domain: latepoint-ccavenue
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// If no LatePoint class exists - exit, because LatePoint plugin is required for this addon

if ( ! class_exists( 'LatePointPaymentsCcavenue' ) ) :

	/**
	 * Main Addon Class.
	 *
	 */

	class LatePointPaymentsCcavenue {

		/**
		 * Addon version.
		 *
		 */
		public $version = '1.0.2';
		public $db_version = '1.0.0';
		public $addon_name = 'latepoint-ccavenue';

		public $processor_code = 'ccavenue';


		/**
		 * LatePoint Constructor.
		 */
		public function __construct() {
			$this->define_constants();
			$this->init_hooks();
		}

		/**
		 * Define LatePoint Constants.
		 */
		public function define_constants() {
		}


		public static function public_stylesheets() {
			return plugin_dir_url( __FILE__ ) . 'public/stylesheets/';
		}

		public static function public_javascripts() {
			return plugin_dir_url( __FILE__ ) . 'public/javascripts/';
		}

		public static function images_url() {
			return plugin_dir_url( __FILE__ ) . 'public/images/';
		}

		/**
		 * Define constant if not already set.
		 *
		 */
		public function define( $name, $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}

		/**
		 * Include required core files used in admin and on the frontend.
		 */
		public function includes() {

			// CONTROLLERS
			include_once( dirname( __FILE__ ) . '/lib/controllers/payments_ccavenue_controller.php' );

			// HELPERS
			include_once( dirname( __FILE__ ) . '/lib/helpers/ccavenue_crypto_helper.php' );
			include_once( dirname( __FILE__ ) . '/lib/helpers/payments_ccavenue_helper.php' );

		}


		public function init_hooks() {


			add_action( 'latepoint_init', [ $this, 'latepoint_init' ] );
			add_action( 'latepoint_includes', [ $this, 'includes' ] );
			add_action( 'latepoint_wp_enqueue_scripts', [ $this, 'load_front_scripts_and_styles' ] );
			add_action( 'latepoint_admin_enqueue_scripts', [ $this, 'load_admin_scripts_and_styles' ] );
			add_filter( 'latepoint_localized_vars_front', [ $this, 'localized_vars_for_front' ] );
			add_filter( 'latepoint_localized_vars_admin', [ $this, 'localized_vars_for_admin' ] );

			add_action( 'latepoint_payment_processor_settings', [ $this, 'add_settings_fields' ], 10 );

			add_filter( 'latepoint_payment_processors', [ $this, 'register_payment_processor' ] );
			add_filter( 'latepoint_installed_addons', [ $this, 'register_addon' ] );


			add_filter( 'latepoint_convert_charge_amount_to_requirements', [ $this, 'convert_charge_amount_to_requirements' ], 10, 2 );
			add_filter( 'latepoint_process_payment_for_order_intent', [ $this, 'process_payment_for_order_intent' ], 10, 2 );

			add_filter( 'latepoint_transaction_intent_specs_charge_amount', [$this, 'convert_transaction_intent_charge_amount_to_specs'], 10, 2 );
			add_filter( 'latepoint_process_payment_for_transaction_intent', [ $this, 'process_payment_for_transaction_intent' ], 10, 2 );

			add_filter( 'latepoint_get_all_payment_times', [ $this, 'add_all_payment_methods_to_payment_times' ] );
			add_filter( 'latepoint_get_enabled_payment_times', [ $this, 'add_enabled_payment_methods_to_payment_times' ] );

			add_filter( 'latepoint_encrypted_settings', [ $this, 'add_encrypted_settings' ] );

            // TODO: Implement Refund logic if API supports it
			// add_filter( 'latepoint_transaction_is_refund_available', [$this, 'transaction_is_refund_available'], 10, 2 );
			// add_filter( 'latepoint_process_refund', 'OsPaymentsCcavenueHelper::process_refund', 10, 3 );

			/* Scripts & Styles for clean layout */
			add_filter( 'latepoint_clean_layout_js_files', [$this, 'add_scripts_to_clean_layout'], 10 );

			// addon specific filters

			add_action( 'init', array( $this, 'init' ), 0 );

			register_activation_hook( __FILE__, [ $this, 'on_activate' ] );
			register_deactivation_hook( __FILE__, [ $this, 'on_deactivate' ] );
		}


		public function add_encrypted_settings( $encrypted_settings ) {
			$encrypted_settings[] = 'ccavenue_working_key';
			$encrypted_settings[] = 'ccavenue_access_code';
			return $encrypted_settings;
		}


		public function add_all_payment_methods_to_payment_times( array $payment_times ): array {
			$payment_methods = $this->get_supported_payment_methods();
			foreach ( $payment_methods as $payment_method_code => $payment_method_info ) {
				$payment_times[ LATEPOINT_PAYMENT_TIME_NOW ][ $payment_method_code ][ $this->processor_code ] = $payment_method_info;
			}

			return $payment_times;
		}

		public function add_enabled_payment_methods_to_payment_times( array $payment_times ): array {
			if ( OsPaymentsHelper::is_payment_processor_enabled( $this->processor_code ) ) {
				$payment_times = $this->add_all_payment_methods_to_payment_times( $payment_times );
			}

			return $payment_times;
		}


		public function process_payment_for_order_intent( array $result, OsOrderIntentModel $order_intent ): array {
			if ( OsPaymentsHelper::should_processor_handle_payment_for_order_intent( $this->processor_code, $order_intent ) ) {
                $result = OsPaymentsCcavenueHelper::process_payment( $result, $order_intent );
			}

			return $result;
		}

		public function process_payment_for_transaction_intent(array $result, OsTransactionIntentModel $transaction_intent): array {
			if ( OsPaymentsHelper::should_processor_handle_payment_for_transaction_intent( $this->processor_code, $transaction_intent ) ) {
				$result = OsPaymentsCcavenueHelper::process_payment($result, $transaction_intent);
			}

			return $result;
		}


		public function convert_charge_amount_to_requirements( $charge_amount, OsCartModel $cart ) {
			if ( OsPaymentsHelper::should_processor_handle_payment_for_cart( $this->processor_code, $cart ) ) {
				$charge_amount = OsPaymentsCcavenueHelper::convert_charge_amount( $charge_amount );
			}

			return $charge_amount;
		}

		public function convert_transaction_intent_charge_amount_to_specs( $charge_amount, OsTransactionIntentModel $transaction_intent) {
			if (OsPaymentsHelper::should_processor_handle_payment_for_transaction_intent($this->processor_code, $transaction_intent)) {
				$charge_amount = OsPaymentsCcavenueHelper::convert_charge_amount( $charge_amount );
			}

			return $charge_amount;
		}


		public function get_supported_payment_methods() {
			return [
				'ccavenue_checkout' => [
					'name'      => __( 'Checkout', 'latepoint-ccavenue' ),
					'label'     => __( 'Checkout', 'latepoint-ccavenue' ),
					'image_url' => LATEPOINT_IMAGES_URL . 'payment_cards.png',
				]
			];
		}

		public function register_payment_processor( $payment_processors ) {
			$payment_processors[ $this->processor_code ] = [
				'code'      => $this->processor_code,
				'name'      => __( 'CCAvenue', 'latepoint-ccavenue' ),
				'image_url' => $this->images_url() . 'processor-logo.png'
			];

			return $payment_processors;
		}

		public function add_settings_fields( $processor_code ) {
			if ( $processor_code != $this->processor_code ) {
				return false;
			} ?>

            <div class="sub-section-row">
                <div class="sub-section-label">
                    <h3><?php _e( 'CCAvenue Settings', 'latepoint-ccavenue' ); ?></h3>
                </div>
                <div class="sub-section-content">
                    <div class="os-row">
                        <div class="os-col-6">
							<?php echo OsFormHelper::text_field( 'settings[ccavenue_merchant_id]', __( 'Merchant ID', 'latepoint-ccavenue' ), OsSettingsHelper::get_settings_value( 'ccavenue_merchant_id' ), [ 'theme' => 'simple' ] ); ?>
                        </div>
                        <div class="os-col-6">
							<?php echo OsFormHelper::text_field( 'settings[ccavenue_access_code]', __( 'Access Code', 'latepoint-ccavenue' ), OsSettingsHelper::get_settings_value( 'ccavenue_access_code' ), [ 'theme' => 'simple' ] ); ?>
                        </div>
                    </div>
                    <div class="os-row">
                        <div class="os-col-12">
							<?php echo OsFormHelper::password_field( 'settings[ccavenue_working_key]', __( 'Working Key', 'latepoint-ccavenue' ), OsSettingsHelper::get_settings_value( 'ccavenue_working_key' ), [ 'theme' => 'simple' ] ); ?>
                        </div>
                    </div>
                     <div class="os-row">
                        <div class="os-col-6">
                            <?php echo OsFormHelper::select_field( 'settings[ccavenue_currency_iso_code]', __( 'Currency Code', 'latepoint-razorpay' ), OsPaymentsCcavenueHelper::load_currencies_list(), OsSettingsHelper::get_settings_value( 'ccavenue_currency_iso_code', 'INR' ) ); ?>
                        </div>
                    </div>
                    <div class="os-row">
                        <div class="os-col-12">
                            <?php echo OsFormHelper::checkbox_field( 'settings[ccavenue_test_mode]', __( 'Test Mode', 'latepoint-ccavenue' ), 'on', OsSettingsHelper::is_on( 'ccavenue_test_mode' ) ); ?>
                        </div>
                    </div>
                </div>
            </div>

			<?php
		}

		/**
		 * Init LatePoint when WordPress Initialises.
		 */
		public function init() {
			// Set up localisation.
			$this->load_plugin_textdomain();
		}

		public function latepoint_init() {
            // Restore session if returning from payment gateway with intent key
            $order_intent_key = isset( $_REQUEST['latepoint_order_intent_key'] ) ? $_REQUEST['latepoint_order_intent_key'] : false;
            $booking_id_passed = isset( $_REQUEST['latepoint_booking_id'] ) ? $_REQUEST['latepoint_booking_id'] : false;

            if ( $order_intent_key ) {
                $order_intent = OsOrderIntentHelper::get_order_intent_by_intent_key( $order_intent_key );
                if ( $order_intent && ! $order_intent->is_new_record() ) {
                    // If booking ID is passed, we are in confirmation mode.
                    // Just set the booking object so verification steps pass, but DO NOT restore the cart
                    // because restoring the cart triggers the "Booking in Progress" form flow.
                    // Smartly Determine State
                    // If Intent is converted to Order -> Show Confirmation
                    // If Intent is new/processing/failed -> Show Form (Restore Cart)
                    
                    if ( $order_intent->is_converted() ) {
                        // Success: Confirmation Mode
                        $booking = new OsBookingModel($order_intent->booking_id); 
                        if ( $booking->id ) {
                            OsStepsHelper::set_booking_object($booking);
                            // Also restore customer for context
                            $customer = new OsCustomerModel($booking->customer_id);
                            if($customer->id) OsStepsHelper::$customer_object = $customer;
                        }
                    } else {
                         // Pending/Failed State: Retry Mode
                         // Restore Cart to allow retry
                        $booking = new OsBookingModel($order_intent->booking_id);
                        if ( $booking->id ) {
                            OsStepsHelper::$cart_object = new OsCartModel();
                            OsStepsHelper::$cart_object->items = []; 
                            OsStepsHelper::$cart_object->add_item( $booking );
                            OsStepsHelper::$cart_object->set_payment_method( $this->processor_code );
                            
                            $customer = new OsCustomerModel($booking->customer_id);
                            if($customer->id) OsStepsHelper::$customer_object = $customer;
                            
                            // Check for error message passed from controller
                            if ( isset( $_REQUEST['message'] ) && ! empty( $_REQUEST['message'] ) ) {
                                $booking->add_error( 'payment_error', urldecode( $_REQUEST['message'] ) );
                            } else {
                                $booking->add_error( 'payment_error', __( 'Payment failed or was cancelled.', 'latepoint-ccavenue' ) );
                            }

                            OsStepsHelper::set_booking_object($booking);
                        }
                    }
                }
            }
		}


		public function load_plugin_textdomain() {
			load_plugin_textdomain( 'latepoint-ccavenue', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		}


		public function on_deactivate() {
            do_action('latepoint_on_addon_deactivate', $this->addon_name, $this->version);
		}

		public function on_activate() {
			do_action( 'latepoint_on_addon_activate', $this->addon_name, $this->version );
		}

		public function register_addon( $installed_addons ) {
			$installed_addons[] = [
				'name'       => $this->addon_name,
				'db_version' => $this->db_version,
				'version'    => $this->version
			];

			return $installed_addons;
		}


		public function load_front_scripts_and_styles() {
			if ( OsPaymentsHelper::is_payment_processor_enabled( $this->processor_code ) ) {
				// Stylesheets
				wp_enqueue_style( 'latepoint-payments-ccavenue-front', $this->public_stylesheets() . 'latepoint-payments-ccavenue-front.css', false, $this->version );

				// Javascripts
				wp_enqueue_script( 'latepoint-payments-ccavenue-front', $this->public_javascripts() . 'latepoint-payments-ccavenue-front.js', array(
					'jquery',
					'latepoint-main-front'
				), $this->version );
				
				// Bypass potentially broken localization by adding inline script directly
				$data_script = "window.latepoint_ccavenue_data = " . json_encode([
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'is_active' => true
				]) . ";";
				wp_add_inline_script( 'latepoint-payments-ccavenue-front', $data_script, 'before' );
			}

		}

		public function load_admin_scripts_and_styles() {
			if ( OsPaymentsHelper::is_payment_processor_enabled( $this->processor_code ) ) {
				// Stylesheets
				// wp_enqueue_style( 'latepoint-payments-ccavenue-back', $this->public_stylesheets() . 'latepoint-payments-ccavenue-back.css', false, $this->version );
			}
		}


		public function localized_vars_for_admin( $localized_vars ) {
			if ( ! is_array( $localized_vars ) ) {
				$localized_vars = [];
			}

			return $localized_vars;
		}

		public function localized_vars_for_front( $localized_vars ) {
            if( !is_array($localized_vars) ) $localized_vars = [];
			if ( OsPaymentsHelper::is_payment_processor_enabled( $this->processor_code ) ) {
				$localized_vars['is_ccavenue_active']             = true;
				$localized_vars['ccavenue_payment_options_route'] = OsRouterHelper::build_route_name( 'payments_ccavenue', 'get_payment_options' );
			} else {
				$localized_vars['is_ccavenue_active'] = false;
			}

			return $localized_vars;
		}

		public function add_scripts_to_clean_layout(array $js_files) : array{
			$js_files[] = 'latepoint-payments-ccavenue-front';
			return $js_files;
		}

	}

endif;

if ( in_array( 'latepoint/latepoint.php', get_option( 'active_plugins', array() ) ) || array_key_exists( 'latepoint/latepoint.php', get_site_option( 'active_sitewide_plugins', array() ) ) ) {
	$LATEPOINT_ADDON_PAYMENTS_CCAVENUE = new LatePointPaymentsCcavenue();
}
