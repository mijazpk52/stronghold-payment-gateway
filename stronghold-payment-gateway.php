<?php
/*
Plugin Name: Stronghold Payment Gateway for Woocommerce
Plugin URI: https://maxenius.agency/
Description: Stronghold Payment Gateway for Woocommerce.
Version: 1.0
Author: M.Ijaz
Author URI: https://maxenius.agency/
*/

add_action( 'plugins_loaded', 'WC_Stronghold_Payment_Gateway' );
function WC_Stronghold_Payment_Gateway(){
	add_filter('woocommerce_payment_gateways', 'add_custom_gateway_class');

	function add_custom_gateway_class($gateways){
		$gateways[] = 'WC_Stronghold_Payment_Gateway'; 
		return $gateways;
	}

	if ( ! class_exists( 'WC_Payment_Gateway' ) ){
		return;
	}
	
	class WC_Stronghold_Payment_Gateway extends WC_Payment_Gateway {
		//Server response code constants
		const SERVER_ERROR 			= 500;
		const SERVER_RESPONSE_OK 		= 200;
		const SERVER_UNAUTHORIZED 	= 401;
		const SERVER_PAYMENT_REQUIRED 	= 402;
		
		//Response status code constants
		const PAYMENT_ISBLOCKED 	= 1;
		const PAYMENT_SUCCESS 	= 0;
		const PAYMENT_DISHONOUR 	= 5;
		const PAYMENT_ERROR 		= 6;
		const MOCK_URL 			= 'https://stoplight.io/mocks/strongholdpay/stronghold-pay/19822275/';
		const LIVE_URL 			= 'https://api.strongholdpay.com/';
		
		public function __construct(){
			$this->id = 'WC_Stronghold_Payment_Gateway';
			$this->icon = ''; 
			$this->has_fields = true; 
			$this->method_title = 'Stronghold Payment';
			$this->method_description = 'Payment via Stronghold.';
			$this->init_form_fields();
			$this->init_settings();
			$this->title = $this->get_option('title');
			$this->description = $this->get_option('description');
			$this->transaction_fee = $this->get_option('transaction_fee');
			$this -> apitype = $this -> settings['mode'];
			$this -> apikey = ($this -> settings['mode']=='sandbox'? $this -> settings['sandboxapi'] : $this -> settings['liveapi'] );
			$this -> apiurl = ($this -> settings['mode']=='sandbox'?  self::MOCK_URL: self::LIVE_URL );
			/* $this->supports = array(
				'products',
				'refunds'
			);  */
			
			if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>='))
			{
				add_action('woocommerce_update_options_payment_gateways_' . $this -> id, array(&$this,'process_admin_options'));
			} else
			{
				add_action('woocommerce_update_options_payment_gateways', array(&$this,'process_admin_options'));
			}
		}		
		
		 // Initialize form fields
		public function init_form_fields(){
			$this->form_fields = array(
				'enabled' => array(
					 'title' => 'Enable/Disable',
					 'type' => 'checkbox',
					 'label' => 'Enable',
					 'default' => 'yes'
				 ),
				 'mode' => array(
					 'title' => 'Api Mode',
					 'type' => 'select',
					 'label' => 'Select Api mode',
					 'options' => array(
						'live' => 'Live',
						'sandbox' => 'Sandbox'
					 )
				 ),
				 'liveapi' => array(
					 'title' => 'Live Api Key',
					 'type' => 'text',
					 'description' => 'Live api key for stronghold payment gateway.',
					 'default' => '',
					 'desc_tip' => true,
				 ),			 
				 'sandboxapi' => array(
					 'title' => 'Sandbox Api Key',
					 'type' => 'text',
					 'description' => 'Sandbox api key for stronghold payment gateway.',
					 'default' => '',
					 'desc_tip' => true,
				 ),
				 'transaction_fee' => array(
					 'title' => 'Transaction Fee',
					 'type' => 'number',
					 'description' => 'stronghold payment gateway transaction fee 2.25.',
					 'default' => '2.25',
					 'desc_tip' => true,
				 ),
				 'title' => array(
					'title' => 'Title',
					'type' => 'text',
					'description' => 'Stronghold payment gateway.',
					'default' => 'Stronghold payment gateway',
				),
				 'description' => array(
					 'title' => 'Description',
					 'type' => 'textarea',
					 'description' => 'This controls the description which the user sees during checkout.',
					 'default' => 'Pay using our Stronghold payment gateway.',
				 ),
			 );
		}
		
		//Woocommerce Version Number Check
		public function stronghold_getWoocommerceVersionNumber(){
			// If get_plugins() isn't available, require it
			if ( ! function_exists( 'get_plugins' ) )
				require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
			
			// Create the plugins folder and file variables
			$plugin_folder = get_plugins( '/' . 'woocommerce' );
			$plugin_file = 'woocommerce.php';
			
			// If the plugin version number is set, return it 
			if ( isset( $plugin_folder[$plugin_file]['Version'] ) ) {
				return $plugin_folder[$plugin_file]['Version'];	
			} else {
				return NULL;
			}
		}
			
		//Helper function to display error for different version of Woocommerce
		public function stronghold_displayErrorMessage($message){
			global $woocommerce;
			 
			if ($this->stronghold_getWoocommerceVersionNumber() >= 2.1)
			{
				return wc_add_notice(__($message, 'stronghold'),'error');
			}
			
			return $woocommerce->add_error(__($message, 'stronghold'));
		}
		
		//Function to retrieve error count for different version of Woocommerce
		public function stronghold_getWoocommerceErrorCount(){
			global $woocommerce;
			 
			if ($this->stronghold_getWoocommerceVersionNumber() >= 2.1)
			{
				 return wc_notice_count('error');
			}
			
			return $woocommerce->error_count();
		}
		
		// Process the payment
		public function process_payment($order_id) {
			
			global $woocommerce;
			$userResponseData = '';
			$order 	= wc_get_order($order_id);
			$amount 	= $order->get_total();	
			
			if(isset($_POST['payment_source']) && $_POST['payment_source'] != ''){
				$payment_source = $_POST['payment_source'];
				$this->transaction_reference = $this->stronghold_generateTransactionReference();
				$findResult = $this->stronghold_findCustomer($email);
				$userResponseData = $findResult->result->items[0];
			}else{
				$message = 'Payment Source not selected or missing. Please try again later';
				$this->stronghold_displayErrorMessage($message);
				return;
			}
			
			$createCharge 	= $this->stronghold_createCharge($userResponseData,$payment_source,$amount);
			
			if($createCharge->status_code == 201){
				$charge_id  		= $createCharge->result->id;
				$authorizeCharge = $this->stronghold_authorizeCharge($charge_id);
				$captureCharge 	 = $this->stronghold_CaptureCharge($charge_id,$amount);
				$responsess 		 = 0;
			}else if($createCharge->status_code == 400){
				$message = $createCharge->error->message;
				$this->stronghold_displayErrorMessage($message);
				return;
			}
			
			switch ($responsess){
				case self::PAYMENT_SUCCESS:
					$order->add_order_note( __('Payment completed', 'stronghold') . ' (Transaction reference: ' . $this->transaction_reference . ')' );
					$woocommerce->cart->empty_cart();
					$order->payment_complete();
					update_post_meta($order->get_id(),'_stronghold_payment_data',$createCharge);					
					return array(
						'result' => 'success',
						'redirect' => $this->get_return_url( $this->order )
					); 					
				case self::PAYMENT_DISHONOUR:
					$this->order->add_order_note( __('Payment dishonoured', 'stronghold') . ' (Transaction reference: ' . $this->transaction_reference . ')' );
					$message = 'Your transaction has been declined. Please try again later';
					$this->stronghold_displayErrorMessage($message);
					return;
				case self::PAYMENT_ERROR:
					$message = 'There has been an error processing your payment. '.(isset($this->exception_message)?$this->exception_message:'');
					$this->stronghold_displayErrorMessage($message);
					return;
				default:
					$message = 'There has been an error processing your payment. '.(isset($this->exception_message)?$this->exception_message:'');
					$this->stronghold_displayErrorMessage($message);
					return;
			}
		
		}
		
		 public function payment_fields(){
			 
			global $woocommerce;
			$email = '';
			
			$session_customer = WC()->session->get('customer'); 
			
			if(isset($session_customer['email']) ){
				$email = $session_customer['email']; 
			}
			
			$button = '<input type="button" id="get_stronghold_email" value="Check Payment Sources">';
			echo '<div id="payment_sources_list">';
			
			if($email){				
				get_stronghold_payment_sources($email);
			}else{
					echo '<p>Please enter your billing email address before clicking the button</p>';
				echo $button;
			}
			
			echo '</div>';
			?>			
			<script>
				jQuery(document).ready(function($){
					var clickcount = 1;
					$( document.body ).off().on('click','#get_stronghold_email',function(ev){
						var billing_email = $('body').find('#billing_email').val();
						var ajaxscript ='<?php echo admin_url('admin-ajax.php');?>';
						
						if(billing_email == ''){
							if(clickcount == 1){
								$('#billing_email').after('<p class="billing_email_error" style="color:red">Please enter Email Address</p>').focus();
							}
							if(clickcount > 1){
								$('#billing_email').focus();
							}
							clickcount++;
						}else{
							$( '#order_methods, #order_review' ).block({ message: null, overlayCSS: { background: '#fff url() no-repeat center', backgroundSize:'16px 16px', opacity: 0.6 } });

							$('.billing_email_error').remove();
							$(this).attr('value','Wait...');
							jQuery.ajax({
								type : "post",
								url : ajaxscript,
								data : {action: "get_stronghold_payment_sources", billing_email : billing_email,nonce : '<?php echo wp_create_nonce( "process_reservation_nonce" );?>'},
								success: function(response) {
									$( '.blockOverlay,.blockUI' ).remove();
									jQuery("#payment_sources_list").html(response);								
								}
							});
						}
					});
				});
			</script>
			<?php
		}

		// Process the refund
		public function process_refund( $order_id, $amount = null, $reason = ''  ) {
			// Do your refund here. Refund $amount for the order with ID $order_id
			$getchargeData = get_post_meta($order_id,'_stronghold_payment_data');			
			$charge_id  		= $getchargeData[0]->result->id;
			$data = $this->stronghold_refundCharge($charge_id);
			//update_post_meta($order_id,'_stronghold_payment_data','');
			return true;
		}
		
		// Initialize the curl request
		private function initCurl($data,$endpoint,$method,$preferCode = ''){
			$Content_Type = '';
			
			$curlArray = array(
				CURLOPT_URL => $this -> apiurl.$endpoint,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => "",
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 30,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => $method,
				CURLOPT_HTTPHEADER => array(
					"Accept: application/json",
					"SH-SECRET-KEY: ".$this -> apikey,
				),
			);
			if($method == 'POST'){
				if(!empty($data)){
					$data = json_encode($data);
				}else{
					$data = '';
				}
				
				$curlArray[CURLOPT_POSTFIELDS] = $data;
				$curlArray[CURLOPT_HTTPHEADER][] = "Content-Type: application/json";
			}
			if($preferCode != ''){
				$curlArray[CURLOPT_HTTPHEADER][] = $preferCode;
			}
			
			$curl = curl_init();
			curl_setopt_array($curl, $curlArray);
			
			$response 	= curl_exec($curl);
			$err 		= curl_error($curl);
			curl_close($curl);
			
			if ($err) {
				throw new stronghold_StrongholdException("Stronghold returned an error. " . $err. ". Please try again");
			 }
			 
			return json_decode($response);
		}
		
		//Search customer before creating.
		public function stronghold_findCustomer($email){
			$billing_email		= urlencode(sanitize_email($email));
			return  $this->initCurl("","v2/customers?email=".$billing_email,"GET");			
		}
		
		//Send Request to create new customer
		private function stronghold_sendCustomer(){
			$requestDataCustomer = array();

			$billing_company	= sanitize_text_field($_POST['billing_company']);
			$billing_first_name	= sanitize_text_field($_POST['billing_first_name']);
			$billing_last_name	= sanitize_text_field($_POST['billing_last_name']);
			$billing_email		= sanitize_email($_POST['billing_email']);
			$billing_phone		= sanitize_text_field($_POST['billing_phone']);
			$billing_address_1	= sanitize_text_field($_POST['billing_address_1']);
			$billing_address_2	= sanitize_text_field($_POST['billing_address_2']);
			$billing_city		= sanitize_text_field($_POST['billing_city']);
			$billing_state		= sanitize_text_field($_POST['billing_state']);
			$billing_postcode	= sanitize_text_field($_POST['billing_postcode']);
			
			$data = array(
					'individual' => array(
						'first_name' => $billing_first_name,
						'last_name' => $billing_last_name,
						'date_of_birth' => '',
						'email' => $billing_email,
						'mobile' => $billing_phone
					),
					'business' => array(
						'business_name' => $billing_company,
						'doing_business_as_name' => $billing_company,
						'contact_name' => $billing_company,
						'email' => $billing_email,
						'website' => site_url(),
						'address' => array(
								'street1' => $billing_address_1,
								'street2' => $billing_address_2,
								'street3' => '',
								'city' => $billing_city,
								'postcode' => $billing_postcode
						)
					),
					'country' => 'US',
					'state' => $billing_state,
					'external_id' => $this->transaction_reference
				);
				
			return  $this->initCurl($data,"v2/customers","POST");
			
		}
		
		//Generates a unique transaction reference number
		public function stronghold_generateTransactionReference(){
			$datetime = date("ymdHis");
			return $datetime."-".uniqid();  
		}
		
		//Make Payent Charge Request
		private function stronghold_createCharge($data,$payment_source,$amount){
			$customerId 			= $data->id;			
			$payment_source_id 	= $payment_source;			
			$external_id 		= $data->external_id;	
			$amount = $amount * 100;
			$chargeArr = array(
					'type' 				=> 'bank_debit',
					'amount' 			=> $amount,
					'currency' 			=> 'usd',
					'customer_id' 		=> $customerId,
					'payment_source_id' 	=> $payment_source_id,
					'source_id' 			=> $payment_source_id,
					'external_id' 		=> $external_id,
					'terminal_id' 		=> '',
					'convenience_fee' 	=> 225
				);
			return  $this->initCurl($chargeArr,"v2/charges","POST","Prefer: code=201");			
		}
		
		//Make Authorization request after Payent Charge.
		private function stronghold_authorizeCharge($charge_id){
			return  $this->initCurl(array(),"v2/charges/".$charge_id."/authorize","POST");			
		}
		
		//Make Capture request after Authorization.
		private function stronghold_CaptureCharge($charge_id,$amount){
			return  $this->initCurl(array('amount' => $amount*100),"v2/charges/".$charge_id."/capture","POST");			
		}
		
		//Make Refund request.
		private function stronghold_refundCharge($charge_id){
			return  $this->initCurl(array(),"v2/charges/".$charge_id."/refund","POST","Prefer: ");			
		}
				
	}

	//Built in stronghold exception class
	class stronghold_StrongholdException extends Exception
	{
	}
}
add_action("wp_ajax_get_stronghold_payment_sources", "get_stronghold_payment_sources");
add_action("wp_ajax_nopriv_get_stronghold_payment_sources", "get_stronghold_payment_sources");
			
function get_stronghold_payment_sources($email = ''){
	if($email == ''){
		$email = $_POST['billing_email'];
	}
	$objclass = new WC_Stronghold_Payment_Gateway();
	$transaction_reference = $objclass->stronghold_generateTransactionReference();
	$findResult = $objclass->stronghold_findCustomer($email);
	$userResponseData = $findResult->result->items[0];
	$button = '<input type="button" id="get_stronghold_email" value="Check Payment Sources">';
						
	if(empty($userResponseData)){
		echo  '<p style="color:red">Stronghold account not registerd with given email address. Please try again with registerd email address.</p>';
		echo $button;
	}else 
	if($userResponseData->is_blocked == 1){
		echo  '<p style="color:red">Your account is blocked and transaction has been declined. Please try again later</p>';
		echo $button;
	}else
	if(empty($userResponseData->payment_sources)){
		echo '<p style="color:red">We have not found any payment source related to your account. Please try again.</p>';
		echo $button;
	}else{
		echo '<p>Please Select Payment Source</p>';
		foreach($userResponseData->payment_sources as $ps){
			?>
			<div class="stronghold_payment_sources">
				<label for="<?php echo $ps->id;?>">
					<input type="radio" name="payment_source" id="<?php echo $ps->id;?>" value="<?php echo $ps->id;?>">
					<?php echo $ps->provider_name;?>
				</label>
			</div>
			<?php
		}
	}
	if(isset($_POST['billing_email'])){
		exit;
	}
}
  
add_action( 'woocommerce_cart_calculate_fees', 'stronghold_add_checkout_fee_for_gateway' );
  
function stronghold_add_checkout_fee_for_gateway() {
	$chosen_gateway = WC()->session->get( 'chosen_payment_method' );

	if ( $chosen_gateway == 'WC_Stronghold_Payment_Gateway' ) {
		$strongholdfee = new WC_Stronghold_Payment_Gateway();
		WC()->cart->add_fee( 'Stronghold Fee', $strongholdfee->transaction_fee );
	}
}
 
function stronghold_payment_method_checker(){
	if ( is_checkout() ) {
		wp_enqueue_script( 'jquery' ); ?>
		<script>
		jQuery(document).ready( function (e){
			var $ = jQuery;

			var updateTimer,dirtyInput = false,xhr;

			function update_shipping(billingstate) 
			{

				if ( xhr ) xhr.abort();

				$( '#order_methods, #order_review' ).block({ message: null, overlayCSS: { background: '#fff url() no-repeat center', backgroundSize:'16px 16px', opacity: 0.6 } });

					var data = {

					action: 'woocommerce_update_order_review',

					security: wc_checkout_params.update_order_review_nonce,

					payment_method: billingstate,

					post_data: $( 'form.checkout' ).serialize()

					};

					xhr = $.ajax({

					type: 'POST',

					url: '<?php echo admin_url('admin-ajax.php');?>',

					data: data,

					success: function( response ) {

					var order_output = $(response);

					$( '#order_review' ).html( response['fragments']['.woocommerce-checkout-review-order-table']+response['fragments']['.woocommerce-checkout-payment']);

					$('body').trigger('updated_checkout');

					},

					error: function(code){

					console.log('ERROR');

					}

					});

			}

			$( 'form.checkout' ).on( 'change', 'input[name^="payment_method"]', function() {

				update_shipping(jQuery(this).val());

			});
		});
		</script>	
    
	<?php 
	}

}

    add_action( 'wp_footer', 'stronghold_payment_method_checker', 50 );
?>