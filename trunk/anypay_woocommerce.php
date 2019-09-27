<?php
/**
 * @package Anypay_WooCommerce
 * @version 1.7.2
 */
/*
Plugin Name: Anypay WooCommerce
Plugin URI: http://wordpress.org/plugins/anypay-woocommerce/
Description: Bitcoin Checkout for WooCommerce Stores
Author: Anypay
Version: 1.7.2
Author URI: https://anypayinc.com 
*/
/*
The accompanying files under various copyrights.

Copyright (c) 2017, 2018, 2019 Anypay Inc.

Permission to use, copy, modify, and distribute this software for any
purpose with or without fee is hereby granted, provided that the above
copyright notice and this permission notice appear in all copies.

THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.

The accompanying files incorporate work covered by the following copyright
and previous license notice:

Copyright (c) 2016 Steven Zeiler

 */
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

add_filter( 'woocommerce_payment_gateways', 'add_anypay_gateway_class' );


function add_anypay_gateway_class($methods) {
    $methods[] = 'WC_Gateway_Anypay';
    return $methods;
} 

function write_log ( $log )  {
  if ( is_array( $log ) || is_object( $log ) ) {
    error_log( print_r( $log, true ) );
  } else {
    error_log( $log );
  }
}

global $anypay_db_version;
$anypay_db_version = '1.0.0';

function anypay_install()
{

    write_log('anypay install');

    global $wpdb;
    global $anypay_db_version;
    
    $invoice_table = $wpdb->prefix . 'woocommerce_anypay_invoices';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql .= "CREATE TABLE $invoice_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        order_id mediumint(9) NOT NULL,
        uid text NOT NULL,
        account_id mediumint(9) NOT NULL,
        amount float NOT NULL,
        currency text NOT NULL,
        status text NOT NULL,
        hash text,
        output_hash text,
        amount_paid float,
        denomination text NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    write_log('create table');
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    add_option('anypay_db_version', $anypay_db_version);
}

register_activation_hook(__FILE__, 'anypay_install');

add_action( 'plugins_loaded', 'init_anypay_gateway_class' );

function init_anypay_gateway_class() {

  class WC_Gateway_Anypay extends WC_Payment_Gateway {

    public function __construct() {


      $this->id = 'wc_gateway_anypay';
      $this->has_fields = false;
      $this->description = 'Pay with Bitcoin via Anypay';
      $this->title = 'Anypay';
      $this->icon = 'https://i1.wp.com/anypayinc.com/wp-content/uploads/2019/02/anypayMark_256.png';
      $this->order_button_text = __('Pay with Bitcoin SV', 'woocommerce');
      $this->method_title = 'Anypay Gateway';
      $this->method_description = sprintf( 'The simplist and easiest way to accept Bitcoin at your Woocommerce store.  Read more <a href="%1$s" target="_blank">how does it work</a>.', 'https://anypayinc.com/earn-bitcoin-at-your-woocommerce-store' );;
      $this->supports = array('products');

     	// Load the settings.
	  $this->init_form_fields();
      $this->init_settings();

		// Define user set variables.
      $this->address        = $this->get_option( 'address' );
      $this->enabled        = $this->get_option( 'enabled' );

      add_action('woocommerce_receipt_' . $this->id, array(
        $this,
        'receipt_page'
      ));
      
      add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
      add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_anypay_settings' ) );
      add_action('woocommerce_api_'.strtolower(get_class($this)), array(&$this, 'handle_callback'));
      write_log( 'woocommerce_api_'.strtolower(get_class($this)) );
    }


    function admin_options() {

     ?>
       <h2><?php _e('Anypay Payment Gateway Settings','woocommerce'); ?></h2>
       <table class="form-table">
       <?php $this->generate_settings_html(); ?>
       </table> <?php

    }


    function handle_callback() {
          
      write_log('handle callback');
      $raw_post = file_get_contents( 'php://input' );
	  $decoded  = json_decode( $raw_post );
      write_log( $decoded );
      if($decoded->status === 'paid' || $decoded->status === 'overpaid'){

        $order = wc_get_order( $decoded->external_id  );
        $order->payment_complete($decoded->uid);
	   # $order->wc_reduce_stock_levels();

      }
      else if( $decoded->status === 'underpaid' ){

        $order->set_status('underpaid', $decoded);

      }

      $this->anypay_updateInvoice($decoded->uid, $decoded->hash, $decoded->status, $decoded->invoice_amount_paid, $decoded->output_hash);

      exit(); 

	}

     /**
	 * Processes and saves options.
	 * If there is an error thrown, will continue to save and validate fields, but will leave the erroring field out.
	 *
	 * @return bool was anything saved?
	 */
	function save_anypay_settings() {

      $anypay_settings = $this->get_option('woocommerce_anypay_settings');

      if( !$anypay_settings ){

        $url = 'https://api.anypay.global/anonymous-accounts';
        $result = wp_remote_post( $url );
        $token  = json_decode($result['body'])->access_token->uid;
        $anypay_settings = array();
        $anypay_settings['access_token'] = $token;
        write_log($anypay_settings);
        $this->update_option('woocommerce_anypay_settings', $anypay_settings);

      }

         //Sets Anypay Address
      $args = array(
        'method' => 'PUT',
        'body' => array(
          'address'    => $_POST['woocommerce_wc_gateway_anypay_address'],
          'currency' => 'BSV' 
         ),
        'headers' => array(
          'Authorization' => 'Basic ' . base64_encode( $anypay_settings['access_token'] . ':')
        )
      );

      $url = 'https://api.anypay.global/addresses/BSV';

      $result = wp_remote_request( $url, $args );

       //Set anypay denomination
      $denomination = get_woocommerce_currency();

      $args = array(
        'method' => 'PUT',
        'body' => array(
          'denomination' => $denomination,
          'business_name' => $_POST['woocommerce_wc_gateway_anypay_merchant'],
          'email' => $_POST['woocommerce_wc_gateway_anypay_email']
        ),
        'headers' => array(
          'Authorization' => 'Basic ' . base64_encode( $anypay_settings['access_token'] . ':')
        )
      );

      $result = wp_remote_request( 'https://api.anypay.global/account', $args );

    } 

    /**
	 * Initialise Gateway Settings Form Fields.
     */
	function init_form_fields() {

     $this->form_fields = array(
        'enabled' => array(
                    'title' => __('On/off', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('On', 'woocommerce'),
                    'default' => 'yes'
        ),
		'address' => array(
			'title'       => 'Enter BSV Address or HandCash handle',
            'description' => 'bitcoins are sent here',
            'type'        => 'text',
            'default'     => '',
		),
        'merchant' => array(
                    'title' => __('Store name', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('', 'woocommerce'),
                    'default' => ''
        ),
        'email' => array(
                    'title' => 'Email',
                    'type' => 'text',
                    'description' => 'Optional, Allow us to reach out! We want to help promote your website to BSV users and provide the best product possible',
                    'default' => '' 
        ),
	  );
     }

      public function payment_fields() {
            $total = WC()->cart->total;
            $currency = get_woocommerce_currency();
            try {
                $response = $this->anypay_convert($currency, $total);
                echo '<p class="pwe-eth-pricing-note"><strong>';
                printf( __( 'Payment of %s Bitcoin SV will be due.', 'anypay' ), $response );
                echo '</p></strong>';
            } catch ( \Exception $e ) {
            echo '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">';
            echo '<ul class="woocommerce-error">';
            echo '<li>';
            _e(
                'Unable to provide an order value in BSV at this time.',
                'anypay'
            );
            echo '</li>';
            echo '</ul>';
            echo '</div>';
      }
            
    }

    function anypay_is_valid_currency(){
      if (!in_array(get_option('woocommerce_currency'), array (
             'AED','AFN','AMD' ,'ANG' , 'AOA' , 'ARS', 'AUD', 'AWG', 'AZN' , 'BAM' , 'BBD' , 'BDT' , 'BGN' , 'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BRL', 'BSD', 'BWP', 'BYR', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CSK', 'CVE', 'CZK', 'DJF','DKK','DOP','DZD','EGP', 'ERN','ETB','EUR','FJD','FKP','GBP','GEL','GHS', 'GIP','GMD', 'GNF', 'GTQ','GWP','GYD','HKD','HNL','HRK','HTG','HUF', 'IDR', 'ILS','INR','IQD','ISK','JMD','JOD','JPY','KES','KGS','KHR','KMF','KRW','KWD','KYD','KZT','LAK','LBP','LKR','LRD','LSL','LTL','LVL','MAD','MDL','MGA','MKD','MMK','MNT','MOP','MRO','MUR','MVR','MWK','MXN','MYR','MZN', 'NAD', 'NGN', 'NIO','NOK', 'NPR', 'NZD','OMR','PAB','PEN','PGK','PHP','PKR','PLN','PYG','QAR','RON','RSD', 'RUB', 'RWF', 'SAR','SBD','SCR','SEK','SGD','SHP','SLL','SOS','SRD','SSP','STD','SYP','SZL','THB','TJS','TND','TOP','TRY','TTD','TWD','TZS','UAH','UGX','USD','UTU','UZS','VEF','VND','VUV','WST','XAF','XCD','XOF','XPF','YER','ZAR','ZMK','ZMW','ZWD')))
      {
        return false;
      }
     return true;
    }

    public function anypay_convert($currency, $value){

       $response = wp_remote_get("https://api.anypay.global/convert/{$value}-{$currency}/to-BSV"); 
          
       $price =  json_decode($response['body'])->conversion->output->value;

       return  $price;

    }

    function process_payment( $order_id ) {

       global $woocommerce;

       $anypay_settings = $this->get_option('woocommerce_anypay_settings');

       $order = new WC_Order( $order_id );
       // debug_to_console($this->get_home_url())
        $args = array(
          'body' => array(
            'amount' =>(float) $order->get_total(),
            'currency' => 'BSV',
            'redirect_url' => $this->get_return_url( $order ),
            'webhook_url' => home_url('/?wc-api=wc_gateway_anypay'), 
            'address'    => $this->address,
            'external_id' => $order->id,
          ),
          'headers' => array(
            'Authorization' => 'Basic ' . base64_encode( $anypay_settings['access_token'] . ':')
          )
        );
        $url = 'https://api.anypay.global/invoices';
        $result = wp_remote_post( $url, $args );

        if( $result['response']['code'] === 200){

          // Mark as on-hold (we're awaiting the cheque)t
          $order->update_status('pending-payment', __( 'Awaiting bitcoin payment', 'woocommerce' ));
          // Remove cart
          $woocommerce->cart->empty_cart();

          $invoice = json_decode($result['body']);

          $this->anypay_createInvoice( $order->id, $invoice->uid, $invoice->account_id, $invoice->amount, $invoice->currency, $invoice->status, $invoice->denomination_currency);

        return array(
          'result'   => 'success',
          'redirect' => 'https://pos.anypay.global/invoices/' . json_decode($result['body'])->uid
         );

        }

    }

    public function anypay_getInvoice($order_id){

      global $wpdb;

      $query = $wpdb->prepare(
        "SELECT *
        FROM {$wpdb->prefix}woocommerce_anypay_invoices
        WHERE order_id = %d",
        $order_id
      );
      $invoices = $wpdb->get_results($query);
      return $invoices;

    }

    public function anypay_createInvoice($order_id, $uid, $account_id, $amount, $currency, $status, $denomination){
   
     global $wpdb;
      
      $invoice_table = $wpdb->prefix . 'woocommerce_anypay_invoices';

      write_log($invoice_table);

      return $wpdb->insert($invoice_table, array(
                'uid' => $uid,
                'account_id' => $account_id,
                'status' => $status,
                'order_id' => $order_id,
                'amount' => $amount,
                'denomination' => $denomination,
                'currency' => $currency
            ));


    }

    public function anypay_updateInvoice($where_uid, $hash, $status, $amount_paid, $output_hash){

     global $wpdb;

     $invoices_table = $wpdb->prefix . 'woocommerce_anypay_invoices';
     if (!is_null($hash)) {
       $hash = esc_sql($hash);
     }

     if (!is_null($where_uid)) {
       $where_uid = esc_sql($where_uid);
     }

     if( !is_null($status)){
       $status = esc_sql($status);

     }

     if( !is_null($amount_paid)){
       $amount_paid = esc_sql($amount_paid);
     }

     if( !is_null($output_hash)){
       $output_hash = esc_sql($output_hash);
     }

     $update_query = array(
             'hash' => $hash,
             'status' => $status,
             'amount_paid' => $amount_paid,
             'output_hash' => $output_hash
     );

     $where = array(
             'uid' => $where_uid
     );

     return $wpdb->update($invoices_table, $update_query, $where);


         
   }

    /**
    * Receipt page
    */
    function receipt_page($order){
      echo $this->anypay_generate_form($order);
    }
  
  }
}

