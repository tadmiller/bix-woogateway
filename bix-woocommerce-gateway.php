<?php

/*
Plugin Name: BIXCoin WooCommerce Gateway
Plugin URI: https://github.com/tadmiller/bix-woogateway
Description: BIXCoin Payment System for WooCommerce
Version: 0.1
*/

add_action( 'plugins_loaded', 'bix_gateway_init', 0 );

function bix_gateway_init() {
	//if condition use to do nothin while WooCommerce is not installed
	if (!class_exists('WC_Payment_Gateway'))
		return;

	include_once( 'bix-woocommerce.php' );

	// class add it too WooCommerce
	add_filter( 'woocommerce_payment_gateways', 'add_bix_gateway' );

	function add_bix_gateway($methods) {
		$methods[] = 'bix_gateway';
		return $methods;
	}
}

// Add custom action links
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'bix_gateway_action_links' );
function bix_gateway_action_links( $links ) {
	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout' ) . '">' . __( 'Settings', 'bix-woogateway' ) . '</a>',
	);
	return array_merge( $plugin_links, $links );
}

?>