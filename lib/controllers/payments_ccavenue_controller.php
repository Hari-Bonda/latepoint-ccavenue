<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


if ( ! class_exists( 'OsPaymentsCcavenueController' ) ) :


	class OsPaymentsCcavenueController extends OsController {


		function __construct() {
			parent::__construct();

			$this->action_access['public']   = array_merge( $this->action_access['public'], [ 'callback', 'get_payment_options', 'get_order_payment_options' ] );
			$this->action_access['customer'] = array_merge( $this->action_access['customer'], [] );
			// $this->views_folder              = plugin_dir_path( __FILE__ ) . '../views/payments_ccavenue/';
		}


		// catches return from ccavenue
		public function callback() {
            // CCAvenue POSTs data to this URL
            $encResponse = isset($_POST["encResp"]) ? $_POST["encResp"] : '';
            $workingKey = OsPaymentsCcavenueHelper::get_working_key();
            
            if(empty($encResponse)){
                // Fallback for some scenarios or error 
                OsDebugHelper::log( 'CCAvenue Callback Error: Empty Response' );
                wp_die('CCAvenue Error: Invalid Response');
            }

            $rcvdString = CcavenueCryptoHelper::decrypt($encResponse, $workingKey);
            $decryptValues = array();
            parse_str($rcvdString, $decryptValues);
            
            $order_status = $decryptValues['order_status'] ?? '';
            $order_id = $decryptValues['order_id'] ?? '';
            
            $order_intent_key = $decryptValues['merchant_param1'] ?? '';
            $intent_type = $decryptValues['merchant_param2'] ?? 'order_intent'; // order_intent or transaction_intent
            $redirect_base_url = $decryptValues['merchant_param3'] ?? home_url('/');

            // Log for debugging
            OsDebugHelper::log('CCAvenue Callback Decrypted: ' . print_r($decryptValues, true));

            $intent_model = false;

            if($intent_type == 'order_intent'){
                if(!empty($order_intent_key)){
                    $intent_model = OsOrderIntentHelper::get_order_intent_by_intent_key($order_intent_key);
                }
            }else{
                 if(!empty($order_intent_key)){
                    $intent_model = OsTransactionIntentHelper::get_transaction_intent_by_intent_key($order_intent_key);
                }
            }

            if ( !$intent_model || $intent_model->is_new_record() ) {
                wp_die( 'Error: Intent not found.' );
            }

            // Save raw status
            $intent_model->set_payment_data_value('status', $order_status);
            $intent_model->set_payment_data_value('tracking_id', $decryptValues['tracking_id'] ?? '');

            if($order_status === 'Success'){
                // Verify amount
                $amount_paid = $decryptValues['amount'] ?? 0;
                // Ideally verify match with $intent_model amount 

                 if($intent_type == 'order_intent'){
                     // Convert to Order
                     $order_id = $intent_model->convert_to_order();
                     if($order_id){
                         // Find booking associated with this order
                         $booking_id = false;
                         $order_items = OsOrdersHelper::get_items_for_order_id($order_id);
                         if($order_items){
                             foreach($order_items as $order_item){
                                 $bookings = OsOrdersHelper::get_bookings_for_order_item($order_item->id);
                                 if($bookings){
                                     $booking_id = $bookings[0]->id;
                                     break;
                                 }
                             }
                         }

                         $url_params = [
                            'latepoint_payment_status' => 'success',
                            'booking_id' => $booking_id
                         ];
                         
                         $url = add_query_arg($url_params, $redirect_base_url);
                         wp_redirect($url);
                         exit;
                     }
                 } else {
                     // Transaction Intent (Payment for existing invoice/booking)
                     $transaction_id = $intent_model->convert_to_transaction();
                     if($transaction_id){
                         $url_params = [
                            'latepoint_payment_status' => 'success',
                            'transaction_id' => $transaction_id
                         ];
                         $url = add_query_arg($url_params, $redirect_base_url);
                         wp_redirect($url);
                         exit;
                     }
                 }

            } else {
                  // Failed
                  $url_params = [
                    'latepoint_payment_status' => 'failed',
                    'reason' => urlencode($decryptValues['failure_message'] ?? 'Unknown')
                  ];
                  $url = add_query_arg($url_params, $redirect_base_url);
                  wp_redirect($url);
                  exit;
            }
            
            exit;
		}


		/* Generates payment options for CCAvenue payment (Redirect Flow) */
		public function get_payment_options() {
			OsStepsHelper::set_required_objects( $this->params );

			$customer = OsStepsHelper::get_customer_object();
			$amount = OsStepsHelper::$cart_object->specs_calculate_amount_to_charge();

			try {
				if ( $amount > 0 ) {
				    
					$booking_form_page_url = $this->params['booking_form_page_url'] ?? wp_get_original_referer();
					$order_intent          = OsOrderIntentHelper::create_or_update_order_intent( OsStepsHelper::$cart_object, OsStepsHelper::$restrictions, OsStepsHelper::$presets, $booking_form_page_url, OsStepsHelper::get_customer_object_id() );

					if ( ! $order_intent->is_bookable() ) {
						throw new Exception( empty( $order_intent->get_error_messages() ) ? __( 'Booking slot is not available anymore.', 'latepoint-ccavenue' ) : implode( ', ', $order_intent->get_error_messages() ) );
					}
                    
                    // Prepare CCAvenue Request
                    $encrypted_data = OsPaymentsCcavenueHelper::get_payment_request_params($amount, $customer, $order_intent->id, [
                        'order_intent_key' => $order_intent->intent_key,
                        'redirect_url' => $booking_form_page_url
                    ]);
                    $working_key = OsPaymentsCcavenueHelper::get_working_key();
                    $access_code = OsPaymentsCcavenueHelper::get_access_code();
                    
                    $encrypted_payload = CcavenueCryptoHelper::encrypt($encrypted_data, $working_key);


					if ( $this->get_return_format() == 'json' ) {
						$this->send_json( array( 'status'            => LATEPOINT_STATUS_SUCCESS,
						                         'message'           => __( 'Redirecting to CCAvenue', 'latepoint-ccavenue' ),
						                         'action_url'        => OsPaymentsCcavenueHelper::get_transaction_url(),
						                         'access_code'       => $access_code,
						                         'encRequest'        => $encrypted_payload,
						                         'order_intent_key'  => $order_intent->intent_key,
						                         'amount'            => $amount
						) );
					}
				} else {
					// free booking
					if ( $this->get_return_format() == 'json' ) {
						$this->send_json( array( 'status'  => LATEPOINT_STATUS_SUCCESS,
						                         'message' => __( 'Nothing to pay', 'latepoint-ccavenue' ),
						                         'amount'  => $amount
						) );
					}
				}
			} catch ( Exception $e ) {
				error_log( $e->getMessage() );
				$this->send_json( array( 'status' => LATEPOINT_STATUS_ERROR, 'message' => $e->getMessage() ) );
			}
		}

		public function get_order_payment_options() {

			if ( ! filter_var( $this->params['invoice_id'], FILTER_VALIDATE_INT ) ) exit();

			$invoice = new OsInvoiceModel( $this->params['invoice_id'] );
			$transaction_intent = OsTransactionIntentHelper::create_or_update_transaction_intent( $invoice, $this->params );

			$amount = $transaction_intent->specs_charge_amount;

			try {
				if ( $amount > 0 ) {
					$customer = new OsCustomerModel($transaction_intent->customer_id);
					
                    // Pass current URL for redirection if available, or assume home/referer
                    $booking_form_page_url = wp_get_original_referer();

					// Prepare CCAvenue Request
                    $encrypted_data = OsPaymentsCcavenueHelper::get_payment_request_params($amount, $customer, $transaction_intent->id, [
                        'transaction_intent_key' => $transaction_intent->intent_key,
                        'redirect_url' => $booking_form_page_url
                    ]);
                    $working_key = OsPaymentsCcavenueHelper::get_working_key();
                    $access_code = OsPaymentsCcavenueHelper::get_access_code();
                    
                    $encrypted_payload = CcavenueCryptoHelper::encrypt($encrypted_data, $working_key);

					if ( $this->get_return_format() == 'json' ) {
						$this->send_json( array(
							'status'              => LATEPOINT_STATUS_SUCCESS,
							'message'             => esc_html__( 'Redirecting to CCAvenue', 'latepoint-ccavenue' ),
							'action_url'          => OsPaymentsCcavenueHelper::get_transaction_url(),
						    'access_code'         => $access_code,
						    'encRequest'          => $encrypted_payload,
						    'order_intent_key'    => $transaction_intent->intent_key,
							'amount'              => $amount
						) );
					}
				} else {
					// free booking
					if ( $this->get_return_format() == 'json' ) {
						$this->send_json( array(
							'status'  => LATEPOINT_STATUS_SUCCESS,
							'message' => esc_html__( 'Nothing to pay', 'latepoint-ccavenue' ),
							'amount'  => $amount
						) );
					}
				}
			} catch ( Exception $e ) {
				error_log( $e->getMessage() );
				$this->send_json( array( 'status' => LATEPOINT_STATUS_ERROR, 'message' => $e->getMessage() ) );
			}
		}
	}


endif;
