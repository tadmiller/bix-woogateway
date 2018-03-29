<?php

class bix_gateway extends WC_Payment_Gateway {

	function __construct() {

		// global ID
		$this -> id = "bix_woogateway";

		// Show Title
		$this -> method_title = __( "BIXCoin WooCommerce Gateway", 'bix-woogateway' );

		// Show Description
		$this -> method_description = __( "BIXCoin Payment System for WooCommerce", 'bix-woogateway' );

		// vertical tab title
		$this -> title = __( "BIXCoin Gateway", 'bix-woogateway' );
		$this -> icon = null;
		$this -> has_fields = true;

		// This makes the form options changable.
		$this -> init_form_fields();

		// load time variable setting
		$this -> init_settings();
		
		// Turn these settings into variables we can use
		foreach ( $this -> settings as $setting_key => $value ) {
			$this -> $setting_key = $value;
		}
		
		// further check of SSL if you want
		add_action( 'admin_notices', array( $this,	'do_ssl_check' ) );
		
		// Save settings
		if ( is_admin() ) {
			add_action( 'woocommerce_update_options_payment_gateways_' . $this -> id, array( $this, 'process_admin_options' ) );
		}		
	}

	public function payment_fields() {
		$description = "BIXCoin amount is equal to (total amount / 10 ) * 0.01";
		if ($description) {
			echo wpautop(wptexturize($description)); // @codingStandardsIgnoreLine.
		}

		$this -> bix_payment_form();
	}

	public function bix_payment_form( $args = array(), $fields = array() ) {
		$cc_form             = new WC_Payment_Gateway_BIX();
		$cc_form -> id       = $this ->  id;
		$cc_form -> supports = $this ->  supports;
		$cc_form -> form();
	}

	function echo_log($msg)
	{
   		echo '<pre>'.print_r( $msg, true ).'</pre>';
	}

	// administration fields for specific Gateway
	public function init_form_fields() {
		$this -> form_fields = array(
			'enabled' => array(
				'title'		=> __( 'Enable / Disable', 'bix-woogateway' ),
				'label'		=> __( 'Enable this payment gateway', 'bix-woogateway' ),
				'type'		=> 'checkbox',
				'default'	=> 'no',
			),
			'title' => array(
				'title'		=> __( 'Title', 'bix-woogateway' ),
				'type'		=> 'text',
				'desc_tip'	=> __( 'Payment title of checkout process.', 'bix-woogateway' ),
				'default'	=> __( 'BIXCoin', 'bix-woogateway' ),
			),
			'bix_merchant_id' => array(
				'title'		=> __( 'BIX Merchant ID', 'bix-woogateway' ),
				'type'		=> 'text',
				'desc_tip'	=> __( 'This is your merchant ID for a BIX transfer.', 'bix-woogateway' ),
			),
		);		
	}
	
	// Response handled for payment gateway
	public function process_payment( $order_id ) {
		global $woocommerce;

		$customer_order = new WC_Order( $order_id );

		// checking for transiction
		$environment = ( $this -> environment == "yes" ) ? 'TRUE' : 'FALSE';

		$bix_payload = json_encode(array(
			"email"           	=> str_replace(array(' ', '-' ), '', $_POST['bix_woogateway-bix-id']),
			"passwd"           	=> md5(str_replace('\\\'', '\'', str_replace(array(' ', '-' ), '', $_POST['bix_woogateway-bix-pw']))),
		));

		$auth = wp_remote_post( "http://13.72.75.198:9000/v1/users/login", array(
			'method'    => 'POST',
			'headers'   => array('Content-Type' => 'application/json; charset=utf-8'),
			'body'      => $bix_payload,
			'timeout'   => 90,
			'sslverify' => false,
		));

		if (is_wp_error($auth))
			echo "!!! WP ERROR !!!";
		if (empty($auth['body']))
			echo "!!! RESPONSE EMPTY !!!";

		// Parse HTTP response body for BIX ID.
		$body = json_decode($auth['body']);

		// The code that is returned by our HTTP request will determine what the login status is.
		$loginStatus = $auth['response']['code'];

		// Get the consumer's BIX ID from the message body.
		$bixid = $body -> bixid;

		$this -> echo_log($auth);

		// If we don't get a 202 from the API, we need to back out of this process.
		if ($loginStatus != 200 || $body -> bixid == null)
			throw new Exception(__('Unable to authenticate to BIX system.', 'bix-woogateway'));

		echo "Success! Logged into the BIX system.";

		$bix_amount = ($customer_order -> order_total / 100);
		$bix_fee = 0.01 * $bix_amount;
		$bix_total = $bix_amount + $bix_fee;


		$transferPayload = json_encode(array(
			"payTo"			=> $this -> bix_merchant_id,
			"payFrom"		=> str_replace(array(' ', '-' ), '', $_POST['bix_woogateway-bix-id']),
			"transferType"	=> "TRANSFER",
			"status"		=> "NEW",
			"bxcAmount"		=> $bix_amount,
			"bixFee"		=> $bix_fee,
			"totalAmount"	=> $bix_total,
			"message"		=> "Payment to AOKTech"		
		));

		$transfer = wp_remote_post("http://13.72.75.198:9008/v1/transfers/add", array(
			'method'    => 'POST',
			'headers'   => array('Content-Type' => 'application/json; charset=utf-8'),
			'body'      => $transferPayload,
			'timeout'   => 90,
			'sslverify' => false,
		));

		$transferStatus = $transfer['response']['code'];

		// Handle all of the errors that could occur with a transfer.
		if ($transferStatus == 400)
			throw new Exception(__('Could not create new transfer.', 'bix-woogateway'));

		if ($transferStatus == 404)
			throw new Exception(__('Recipient account not found.', 'bix-woogateway'));

		//echo "!!! PW DETAILS \n\n";
		//echo md5(str_replace('\\\'', '\'', str_replace(array(' ', '-' ), '', $_POST['bix_woogateway-bix-pw'])));
		//echo str_replace('\\\'', '\'', str_replace(array(' ', '-' ), '', $_POST['bix_woogateway-bix-pw']));
		// If we DON'T receive a 202, then there's something wrong with the BIX API.
		if ($transferStatus != 202)
			throw new Exception(__('Unknown BIX system error.', 'bix-woogateway'));

		$customer_order -> add_order_note( __( 'Successfully transfered ' . $bix_total . " BIX to ". $this -> bix_merchant_id, 'bix-woogateway' ) );
											 
		// Order marked as paid.
		$customer_order -> payment_complete();

		// Empty customer cart.
		$woocommerce -> cart -> empty_cart();

		// Redirect to success page.
		return array(
			'result'   => 'success',
			'redirect' => $this -> get_return_url($customer_order),
		);
	}
	
	// Validate fields
	public function validate_fields() {
		return true;
	}

	public function do_ssl_check() {
		if( $this -> enabled == "yes" ) {
			if( get_option( 'woocommerce_force_ssl_checkout' ) == "no" ) {
				echo "<div class=\"error\"><p>". sprintf( __( "<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>" ), $this -> method_title, admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) ."</p></div>";	
			}
		}		
	}

}

class WC_Payment_Gateway_BIX extends WC_Payment_Gateway {

	/**
	 * Builds our payment fields area - including tokenization fields for logged
	 * in users, and the actual payment fields.
	 *
	 * @since 2.6.0
	 */
	public function payment_fields() {
		if ( $this -> supports( 'tokenization' ) && is_checkout() ) {
			$this -> tokenization_script();
			$this -> saved_payment_methods();
			$this -> form();
			$this -> save_payment_method_checkbox();
		} else {
			$this -> form();
		}
	}

	/**
	 * Output field name HTML
	 *
	 * Gateways which support tokenization do not require names - we don't want the data to post to the server.
	 *
	 * @since  2.6.0
	 * @param  string $name Field name.
	 * @return string
	 */
	public function field_name( $name ) {
		return $this -> supports( 'tokenization' ) ? '' : ' name="' . esc_attr( $this -> id . '-' . $name ) . '" ';
	}

	/**
	 * Outputs fields for entering credit card information.
	 *
	 * @since 2.6.0
	 */
	public function form() {
		wp_enqueue_script( 'wc-credit-card-form' );

		$fields = array();

		$default_fields = array(
			// BIX email
			'bix-id-field' => '<p class="form-row form-row-wide">
				<label for="' . esc_attr( $this -> id ) . '-bix-id">' . esc_html__( 'Email', 'woocommerce' ) . ' <span class="required">*</span></label>
				<input id="' . esc_attr( $this -> id ) . '-bix-id" class="input-text" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" placeholder="enter email" ' . $this -> field_name( 'bix-id' ) . ' />
			</p>',

			// BIX password
			'bix-pw-field' => '<p class="form-row form-row-wide">
				<label for="' . esc_attr( $this -> id ) . '-bix-pw">' . esc_html__( 'Password', 'woocommerce' ) . ' <span class="required">*</span></label>
				<input id="' . esc_attr( $this -> id ) . '-bix-pw" class="input-text" autocorrect="no" autocapitalize="no" spellcheck="no" type="password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" ' . $this -> field_name( 'bix-pw' ) . ' />
			</p>',
		);

		$fields = wp_parse_args( $fields, apply_filters( 'woocommerce_bix_form_fields', $default_fields, $this -> id ) );

		?>

		<fieldset id="wc-<?php echo esc_attr( $this -> id ); ?>-cc-form" class='wc-credit-card-form wc-payment-form'>
			<?php do_action( 'woocommerce_credit_card_form_start', $this -> id ); ?>
			<?php
			foreach ( $fields as $field ) {
				echo $field; // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
			}
			?>
			<?php do_action( 'woocommerce_credit_card_form_end', $this -> id ); ?>
			<div class="clear"></div>
		</fieldset>
		<?php
	}
}


?>