<?php 

class OsPaymentsCcavenueHelper {
  public static $processor_name = 'ccavenue';
  public static $default_currency_iso_code = 'INR';

  public static function get_currency_iso_code(){
    return OsSettingsHelper::get_settings_value('ccavenue_currency_iso_code', self::$default_currency_iso_code);
  }

  public static function get_merchant_id(){
    return OsSettingsHelper::get_settings_value('ccavenue_merchant_id', '');
  }

  public static function get_access_code(){
    return OsSettingsHelper::get_settings_value('ccavenue_access_code', '');
  }

  public static function get_working_key(){
      return OsSettingsHelper::get_settings_value('ccavenue_working_key', '');
  }

  public static function is_test_mode(){
      return OsSettingsHelper::is_on('ccavenue_test_mode');
  }

  public static function get_transaction_url(){
      return self::is_test_mode() ? 'https://test.ccavenue.com/transaction/transaction.do?command=initiateTransaction' : 'https://secure.ccavenue.com/transaction/transaction.do?command=initiateTransaction';
  }


  public static function get_payment_request_params( $amount, $customer, $order_id, $notes = [] ){
      $merchant_id = self::get_merchant_id();
      $currency = self::get_currency_iso_code();
      $redirect_url = OsRouterHelper::build_admin_post_link(['payments_ccavenue', 'callback'], ['latepoint_order_id' => $order_id] ); 
      $cancel_url = $redirect_url; // For now simplify to same handler

      $merchant_data = '';
      $merchant_data .= 'merchant_id='.urlencode($merchant_id);
      $merchant_data .= '&order_id='.urlencode($order_id);
      $merchant_data .= '&amount='.urlencode($amount);
      $merchant_data .= '&currency='.urlencode($currency);
      $merchant_data .= '&redirect_url='.urlencode($redirect_url);
      $merchant_data .= '&cancel_url='.urlencode($cancel_url);
      $merchant_data .= '&language=EN';
      
      // Billing Info
      $merchant_data .= '&billing_name='.urlencode($customer->full_name);
      $merchant_data .= '&billing_email='.urlencode($customer->email);
      $merchant_data .= '&billing_tel='.urlencode($customer->phone);

      // Notes (Pass intent key as merchant_param1 for tracking)
      if(isset($notes['order_intent_key'])){
          $merchant_data .= '&merchant_param1='.urlencode($notes['order_intent_key']);
          $merchant_data .= '&merchant_param2='.urlencode('order_intent');
      } elseif(isset($notes['transaction_intent_key'])){
          $merchant_data .= '&merchant_param1='.urlencode($notes['transaction_intent_key']);
          $merchant_data .= '&merchant_param2='.urlencode('transaction_intent');
      }

      if(isset($notes['redirect_url'])){
          $merchant_data .= '&merchant_param3='.urlencode($notes['redirect_url']);
      }

      return $merchant_data;
  }
 

  public static function zero_decimal_currencies_list(){
    return array();
  }


	public static function convert_charge_amount( $charge_amount ) {
		$iso_code = self::get_currency_iso_code();
		if ( in_array( $iso_code, self::zero_decimal_currencies_list() ) ) {
			return round( $charge_amount );
		} else {
			$number_of_decimals = OsSettingsHelper::get_settings_value( 'number_of_decimals', '2' );
			return number_format( (float) $charge_amount, $number_of_decimals, '.', '' );
		}
	}

  public static function load_currencies_list(){
    return ["INR" => "Indian rupee", "USD" => "United States dollar", "AED" => "United Arab Emirates Dirham"];
  }

	/**
	 * Process Payment for Order Intent and Transaction Intent
	 *
	 * @param array $result
	 * @param OsOrderIntentModel | OsTransactionIntentModel $intent_model
	 *
	 * @return array
	 */
	public static function process_payment(array $result, $intent_model ): array {
        // This is called when verifying payment status locally before confirming step
        // For CCAvenue redirection, the actual verification happens in callback controller
        // This method might be checking if we already have a successful transaction stored
        
		switch ( $intent_model->get_payment_data_value( 'method' ) ) {
			case 'ccavenue_checkout':
                $status = $intent_model->get_payment_data_value( 'status' );
                $tracking_id = $intent_model->get_payment_data_value( 'tracking_id' );

				if ( $status == 'Success' && $tracking_id ) {
                    $result['status']    = LATEPOINT_STATUS_SUCCESS;
                    $result['charge_id'] = $tracking_id;
                    $result['processor'] = self::$processor_name;
                    $result['kind']      = LATEPOINT_TRANSACTION_KIND_CAPTURE;
				} else {
					$result['status']  = LATEPOINT_STATUS_ERROR;
					$result['message'] = esc_html__( 'Payment not verified', 'latepoint-ccavenue' );
                    if($status == 'Failure'){
					    $intent_model->add_error( 'payment_error', esc_html__( 'Payment Failed', 'latepoint-ccavenue' ) );
                    } else {
					    $intent_model->add_error( 'payment_error', esc_html__( 'Payment pending or invalid', 'latepoint-ccavenue' ) );
                    }
				}
				break;
		}
		return $result;
	}

}
