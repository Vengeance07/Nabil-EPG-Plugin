<?php 

/**
	* Plugin Name: Nabil EPG Payment for WooCommerce
	* Plugin URI:
	* Author Name: Vengeance
	* Author URI:
	* Description: This plugin allows for payment through Nabil bank's EPG
	* Version: 1.0
	* text-domain: nabil-epg-pay-woo
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

add_filter('woocommerce_payment_gateways','add_to_woo_nabil_epg_payment_gateway');
function add_to_woo_nabil_epg_payment_gateway($gateways){
	$gateways[] = 'WC_Nabil_EPG_Pay_Gateway';
	return $gateways;
}

function add_payment_gateway_response_endpoint() {
    add_rewrite_rule('^payment-response/?', 'index.php?payment_response=1', 'top');
    flush_rewrite_rules();
}
add_action('init', 'add_payment_gateway_response_endpoint');

function payment_gateway_query_vars($vars) {
    $vars[] = 'payment_response';
    return $vars;
}
add_filter('query_vars', 'payment_gateway_query_vars');


add_action('plugins_loaded', 'nabil_epg_payment_init');
function nabil_epg_payment_init(){
	if ( class_exists( 'WC_Payment_Gateway' )){
		class WC_Nabil_EPG_Pay_Gateway extends WC_Payment_Gateway {
			private static $instance = null;
    		public $merchantId;
    		public $cert_file;
    		public $key_file;
			
			public function __construct(){
				$this->id = 'nabil_epg_payment';
				$this->icon = apply_filters('woocommerce_offline_icon', plugin_dir_url( __DIR__ ) . 'NabilEPG-payment-for-woocommerce/images/logo.png');;
				$this->has_fields = true;
				$this->method_title = 'Nabil EPG Payment';
				$this->method_description = 'Nabil EPG WooCommerce Plugin';
				
				$this->init_form_fields();
				$this->init_settings();
				$this->title = $this->get_option('title');
				$this->description = $this->get_option('description');
				$this->enabled = $this->get_option('enabled');
				$this->merchantId = $this->get_option('merchantId');
				$this->cert_file = $this->get_option('cert_file');
				$this->key_file = $this->get_option('key_file');
				$this->decrypt_key = $this->get_option('decrypt_key');

				// This action hook saves the settings
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			}

			public function init_form_fields(){
				$this->form_fields = array(
					'enabled' => array(
						'title'       => 'Enable/Disable',
						'label'       => 'Enable Nabil EPG Payment Gateway',
						'type'        => 'checkbox',
						'description' => '',
						'default'     => 'no'
					),
					'title' => array(
						'title'       => 'Title',
						'type'        => 'text',
						'description' => 'This controls the title which the user sees during checkout.',
						'default'     => 'Card Payment',
						'desc_tip'    => true,
					),
					'description' => array(
						'title'       => 'Description',
						'type'        => 'textarea',
						'description' => 'This controls the description which the user sees during checkout.',
						'default'     => 'Pay with your Debit/ Credit card.',
					),
					'merchantId' => array(
						'title'       => 'Merchant ID',
						'type'        => 'text',
						'description' => 'This is the test merchant id value.',
            			'default'     => 'NABIL106698'
					),
					'cert_file' => array(
						'title'       => 'Certificate File',
						'type'        => 'text',
						'description' => 'Enter the absolute path of where the certificate file is uploaded.',
            			'default'     => ''
					),
					'key_file' => array(
						'title'       => 'Key File',
						'type'        => 'text',
						'description' => 'Enter the absolute path of where the key file is uploaded.',
            			'default'     => ''
					),
					'decrypt_key' => array(
						'title'       => 'Decryption Key',
						'type'        => 'text',
						'description' => 'Enter the decryption key',
            			'default'     => ''
					)
				);
			}

			public static function getInstance() {
	        	if (self::$instance === null) {
	            	self::$instance = new self();
	        	}
	        	return self::$instance;
    		}	

			public function process_payment($order_id){
				$order = wc_get_order($order_id);
				$total_amount = $order->get_total() * 100; 
				$orderID = $order->get_id();
    			$merchant_id = $this->merchantId;

			    $xml = '<?xml version="1.0" encoding="UTF-8"?>
					<TKKPG>
					    <Request>
					        <Operation>CreateOrder</Operation>
					        <Language>EN</Language>
						    <Order>
						        <OrderType>Purchase</OrderType>
				 	            <Merchant>' . $merchant_id . '</Merchant>
					            <Amount>' . intval($total_amount) . '</Amount>
					            <Currency>524</Currency>
					            <Description>' . $orderID . '</Description>
					            <ApproveURL>' . esc_url($this->get_return_url($order) . '&payment_response=1&status=approved&order_id=' . $order->get_id()) . '</ApproveURL>
					            <CancelURL>' . esc_url(add_query_arg('payment_response', '1', $order->get_cancel_order_url())) . '</CancelURL>
						        <DeclineURL>' . esc_url(add_query_arg(['status' => 'declined', 'payment_response' => '1', 'order_id' => $order->get_id()], get_permalink(get_option('woocommerce_checkout_page_id')))) . '</DeclineURL>
						        <Fee>0</Fee>
						    </Order>
					    </Request>
					</TKKPG>';

			    $headers = [
			        "Content-type: text/xml",
			        "Content-length: " . strlen($xml),
			        "Connection: close",
			    ];


				$cert_file = $this->cert_file;
				$key_file = $this->key_file;

				log_to_wc_system_status('------------------------------------------------------');
				log_to_wc_system_status(' createorder Request: ' . $xml);

				if (file_exists($key_file) && file_exists($cert_file)) {
				    log_to_wc_system_status('Key File & Certificate File Found.');
				} else {
					log_to_wc_system_status('Key File or Certificate File Not Found.');
				}

			    $ch = curl_init();
			    curl_setopt($ch, CURLOPT_URL, "https://nabiltest.compassplus.com:8444/Exec");
			    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			    curl_setopt($ch, CURLOPT_POST, true);
			    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
			    curl_setopt($ch, CURLOPT_SSLCERT, $cert_file);
			    curl_setopt($ch, CURLOPT_SSLKEY, $key_file);
			    $data = curl_exec($ch);
			    curl_close($ch);

			    log_to_wc_system_status(' createorder Response: ' . $data);
			    // Handle the response from the payment gateway
			    if ($data) {

			        // Assume the payment was successful
			        // $order->payment_complete();
			        // wc_reduce_stock_levels($order_id);
			        // echo ' -------- ';
			        // echo htmlentities($data);

			    	/**
					*
					* Redirect to Payment Page
					*
					*/
			    	$xmlData = simplexml_load_string($data);
			    	$orderID = (string) $xmlData->Response->Order->OrderID;
			    	$sessionID = (string) $xmlData->Response->Order->SessionID;

			    	// Construct the redirect URL with parameters
					$redirectURL = (string) $xmlData->Response->Order->URL . '?ORDERID=' . urlencode($orderID) . '&SESSIONID=' . urlencode($sessionID);
			        log_to_wc_system_status('Nabil Generated Payment URL: ' . $redirectURL);


			        /**
					*
					* Get Order Status
					*
					*/

			        // Extract encrypted order ID and session ID from the response
    				$apidata = explode("@", $data);
    				$encryptedOrderId = substr($apidata[3], 0, 16); 
    				$encryptedSessionId = substr($apidata[6], 0, 80); 

    				// Decryption key
    				$keyValue = $this->decrypt_key;

    				// Decrypt the encrypted order ID and session ID using OpenSSL
    				$decryptedOrderId = openssl_decrypt(hex2bin($encryptedOrderId), 'des-ecb', hex2bin($keyValue), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, '');
    				$decryptedSessionId = openssl_decrypt(hex2bin($encryptedSessionId), 'des-ecb', hex2bin($keyValue), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, '');

    				$decryptedOrderId = ltrim($decryptedOrderId, ' ');
    				$decryptedSessionId = ltrim($decryptedSessionId, ' ');

    				$xmlData = '<?xml version="1.0" encoding="UTF-8"?>
							<TKKPG>
					            <Request>
					                <Operation>GetOrderStatus</Operation>
							        <Language>EN</Language>
							        <Order>
							            <Merchant>' . esc_html($merchant_id) . '</Merchant>
					                    <OrderID>' . $decryptedOrderId . '</OrderID>
					                </Order>
					                <SessionID>' . $decryptedSessionId . '</SessionID>
					            </Request>
					        </TKKPG>';			

			    	$cu = curl_init();
				    curl_setopt($cu, CURLOPT_URL, "https://nabiltest.compassplus.com:8444/Exec");
				    curl_setopt($cu, CURLOPT_RETURNTRANSFER, 1);
				    curl_setopt($cu, CURLOPT_TIMEOUT, 10);
				    curl_setopt($cu, CURLOPT_POST, 1);
				    curl_setopt($cu, CURLOPT_POSTFIELDS, $xmlData);
				    curl_setopt($cu, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));
				    curl_setopt($cu, CURLOPT_SSLCERT, $cert_file);
			    	curl_setopt($cu, CURLOPT_SSLKEY, $key_file);
				 
				    $newData = curl_exec($cu);
				    if (curl_errno($cu)) {
       				 	// Handle error
				        error_log('cURL error: ' . curl_error($cu));
				        echo curl_error($cu);
				        return false;
				    }
				    curl_close($cu);
				    
			        return [
			            'result'   => 'success',
			            'redirect' => $redirectURL
			        ];

			    } else {
			        // Payment failed
			        wc_add_notice('Payment error: ' . curl_error($ch), 'error');
			        return [
			            'result'   => 'fail',	
			            'redirect' => ''
			        ];
			    }
			}
		}
	}
}

function log_to_wc_system_status($message) {
    if (class_exists('WC_Logger')) {
        $logger = wc_get_logger();
        $logger->info($message, array('source' => 'nabil_epg_plugin'));
    }
}

function handle_payment_gateway_response() {
		$res = get_query_var('payment_response');
            	if ($res === '1') {
			        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['xmlmsg'])) {
			            if ($xmlmsg !== false) {
			            	$xmlmsg = stripslashes($_POST['xmlmsg']);
			        		log_to_wc_system_status('Payment Response xmlout: ' . $xmlmsg);

			        		try {
						        $xml = new SimpleXMLElement($xmlmsg);
						        // Now you can access elements within the XML
						        $orderStatus = $xml->OrderStatus;
						        $orderId = $xml->OrderID;
						        $sessionId = $xml->SessionID;
						        $orderIdDesc = $xml->OrderDescription;

						        $dOrderStatus = $xml->Message->OrderStatus;
						        $dOrderId = $xml->Message->OrderID;
						        $dSessionId = $xml->Message->SessionID;
						        $dOrderIdDesc = $xml->Message->OrderDescription;

						        $gateway = WC_Nabil_EPG_Pay_Gateway::getInstance();
							    $merchantId = $gateway->merchantId;
							    $cert_file = $gateway->cert_file;
							    $key_file = $gateway->key_file;

							    if (!empty($orderId) && !empty($sessionId)){
							    	log_to_wc_system_status('orderId and sessionId found');
							    	/**
									*
									* Get Order Status
									*
									*/
									$xmlData = '<?xml version="1.0" encoding="UTF-8"?>
											<TKKPG>
									            <Request>
									                <Operation>GetOrderStatus</Operation>
											        <Language>EN</Language>
											        <Order>
											            <Merchant>' . $merchantId . '</Merchant>
									                    <OrderID>' . $orderId . '</OrderID>
									                </Order>
									                <SessionID>' . $sessionId . '</SessionID>
									            </Request>
									        </TKKPG>';
		
									log_to_wc_system_status(' Getorderstatus Request: ' . $xmlData);			

							    	$cu = curl_init();
								    curl_setopt($cu, CURLOPT_URL, "https://nabiltest.compassplus.com:8444/Exec");
								    curl_setopt($cu, CURLOPT_RETURNTRANSFER, 1);
								    curl_setopt($cu, CURLOPT_TIMEOUT, 10);
								    curl_setopt($cu, CURLOPT_POST, 1);
								    curl_setopt($cu, CURLOPT_POSTFIELDS, $xmlData);
								    curl_setopt($cu, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));
								    curl_setopt($cu, CURLOPT_SSLCERT, $cert_file);
							    	curl_setopt($cu, CURLOPT_SSLKEY, $key_file);
								 
								    $newData = curl_exec($cu);
								    if (curl_errno($cu)) {
				       				 	// Handle error
								        error_log('cURL error: ' . curl_error($cu));
								        echo curl_error($cu);
								        return false;
								    }
								    curl_close($cu);

								    log_to_wc_system_status(' Getorderstatus Response: ' . $newData);
							    }
							    if (!empty($dOrderId) && !empty($dSessionId)){
							    	log_to_wc_system_status('dOrderId and dSessionId found');
							    	 /**
									*
									* Get Order Status
									*
									*/
									$xmlData = '<?xml version="1.0" encoding="UTF-8"?>
											<TKKPG>
									            <Request>
									                <Operation>GetOrderStatus</Operation>
											        <Language>EN</Language>
											        <Order>
											            <Merchant>' . $merchantId . '</Merchant>
									                    <OrderID>' . $dOrderId . '</OrderID>
									                </Order>
									                <SessionID>' . $dSessionId . '</SessionID>
									            </Request>
									        </TKKPG>';
		
									log_to_wc_system_status(' Getorderstatus Request: ' . $xmlData);			

							    	$cu = curl_init();
								    curl_setopt($cu, CURLOPT_URL, "https://nabiltest.compassplus.com:8444/Exec");
								    curl_setopt($cu, CURLOPT_RETURNTRANSFER, 1);
								    curl_setopt($cu, CURLOPT_TIMEOUT, 10);
								    curl_setopt($cu, CURLOPT_POST, 1);
								    curl_setopt($cu, CURLOPT_POSTFIELDS, $xmlData);
								    curl_setopt($cu, CURLOPT_HTTPHEADER, array('Content-Type: application/xml'));
								    curl_setopt($cu, CURLOPT_SSLCERT, $cert_file);
							    	curl_setopt($cu, CURLOPT_SSLKEY, $key_file);
								 
								    $newData = curl_exec($cu);
								    if (curl_errno($cu)) {
				       				 	// Handle error
								        error_log('cURL error: ' . curl_error($cu));
								        echo curl_error($cu);
								        return false;
								    }
								    curl_close($cu);

								    log_to_wc_system_status(' Getorderstatus Response: ' . $newData);
								    log_to_wc_system_status('This is OrderStatus:'.$dOrderStatus);
								    if (trim($dOrderStatus) === 'DECLINED') {
								    	log_to_wc_system_status('Declined matched');

									    // Display custom message on the checkout page
									    wc_add_notice('Sorry, your payment was declined. Please try again or use a different payment method.', 'error');
									    
								    }
								    if (trim($dOrderStatus) === 'APPROVED') {
								    	log_to_wc_system_status('Approved matched');
								    	// Things to do after the payment has been approved

								    	$orderIdTrim = trim($dOrderIdDesc);
									    $order = wc_get_order($orderIdTrim);
								    	$order->payment_complete();
								    	wc_reduce_stock_levels($orderIdTrim);
								    }
								    
							    }

						    } catch (Exception $e) {
						        log_to_wc_system_status('Error: Failed to parse XML. ' . $e->getMessage());

						    }	
			            }
			        }
			        else {
			        	exit;
			        }
			        
			    }
        	}
        	add_action('template_redirect', 'handle_payment_gateway_response');

// Add custom action links
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'nabil_epg_payment_gateway_action_links' );
function nabil_epg_payment_gateway_action_links( $links ) {
	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout' ) . '">' . __( 'Settings', 'nabil_epg_payment' ) . '</a>',
	);

	// Merge our new link with the default ones
	return array_merge( $plugin_links, $links );	
}