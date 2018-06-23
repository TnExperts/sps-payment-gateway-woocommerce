<?php

/**
 * Plugin Name: WooCommerce SPS Payment Gateway
 * Description: SPS Payment gateway for woocommerce
 * Version: 1.0
 * Author: Mohamed Ali Charfeddine
 */

add_action('plugins_loaded', 'woocommerce_sps_init', 0);

function woocommerce_sps_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Sps extends WC_Payment_Gateway
    {
        public static $log;

        public $merchant_id;
        public $redirect_page_id;
        public $medthod_title;
        public $notify_url;

        /**
         * WC_Sps constructor.
         */
        public function __construct()
        {
            $this->id = 'sps';
            $this->has_fields = false;
            $this->medthod_title = __( 'SPS Payment', 'Sps' );
            $this->order_button_text  = __( 'Proceed to payment', 'Sps' );
            $this->method_description = __('Pay securely by Credit or Debit card or internet banking through Tunisia SPS Secure Servers.', 'Sps');

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables.
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->merchant_id = $this->get_option('merchant_id');
            $this->redirect_page_id = $this->get_option('redirect_page_id');
            $this->notify_url = WC()->api_request_url('WC_Gateway_Sps');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_api_sps', [$this, 'check_response']);
        }

        /**
         *
         */
        function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => __('Enable/Disable', 'Sps'),
                    'type' => 'checkbox',
                    'label' => __('Enable SPS Payment Module.', 'Sps'),
                    'default' => 'no'
                ],
                'payment_url' => [
                    'title' => __('Payment Url', 'Sps'),
                    'type' => 'text',
                    'label' => __('Payment URL of SPS server', 'Sps'),
                    'default' => 'https://clictopay.monetiquetunisie.com/clicktopay/'
                ],
                'transaction_url' => [
                    'title' => __('Transaction Url', 'Sps'),
                    'type' => 'text',
                    'label' => __('Transaction URL of SPS server', 'Sps'),
                    'default' => 'https://clictopay.monetiquetunisie.com/clicktopay/'
                ],
                'title' => [
                    'title' => __('Title', 'Sps'),
                    'type' => 'text',
                    'description' => __('Title shown on checkout page.', 'Sps'),
                    'default' => __('SPS', 'Sps')
                ],
                'description' => [
                    'title' => __('Description', 'Sps'),
                    'type' => 'textarea',
                    'description' => __('Payment method description shown on checkout.', 'Sps'),
                    'default' => __('Pay securely by Credit or Debit card or internet banking through Tunisia SPS Secure Servers.', 'Sps')
                ],
                'merchant_id' => [
                    'title' => __('Merchant ID', 'Sps'),
                    'type' => 'text',
                    'description' => __('The SPS merchant id (affiliate id).', 'Sps')
                ]
            ];
        }

        /**
         *
         */
        public function admin_options()
        {
            echo '<h3>' . __('SPS Payment Gateway', 'sps') . '</h3>';
            echo '<p>' . __('SPS payment gateway for SMT Tunisia', 'sps') . '</p>';
            echo '<table class="form-table">';
            $this->generate_settings_html();
            echo '</table>';
        }

        /**
         *  There are no payment fields for sps, but we want to show the description if set.
         */
        function payment_fields()
        {
            if ($this->description) echo wpautop(wptexturize($this->description));
        }

        /**
         * Get the transaction URL.
         * @param  WC_Order $order
         * @return string
         */
        public function get_transaction_url($order)
        {
            $this->view_transaction_url = $this->get_option('transaction_url');
            return parent::get_transaction_url($order);
        }

        /**
         * Get the Sps request URL for an order.
         * @param  WC_Order $order
         * @return string
         */
        public function get_request_url($order) {
            $args = http_build_query($this->get_args($order), '', '&');
            return $this->get_option('payment_url') . '?' .  $args;
        }

        /**
         * Get PayPal Args for passing to PP.
         * @param  WC_Order $order
         * @return array
         */
        protected function get_args($order) {
            return [
                'Reference' => $order->id,
                'Montant' => number_format($order->get_total(), 3, '.', ''),
                'Devise' => get_woocommerce_currency(),
                'sid' => $order->order_key,
                'affilie' => $this->get_option('merchant_id'),
            ];
        }

        /**
         * Process the payment and return the result.
         * @param  int $order_id
         * @return array
         */
        public function process_payment( $order_id ) {
            $order = wc_get_order($order_id);
            return array(
                'result'   => 'success',
                'redirect' => $this->get_request_url($order)
            );
        }


        /**
         * Check for valid sps server callback
         */
        function check_response()
        {
            $reference = $_GET['Reference'];
            $action = $_GET['Action'];
            $transaction_id = $_GET['Param'];

            $response = [
                'Reference' => $reference,
                'Action' => $action,
                'Reponse' => 'OK'
            ];
            $order = wc_get_order($reference);

            switch (strtoupper($action)) {
                case 'DETAIL':
                    // return order amount to SPS
                    $response['Reponse'] = number_format($order->get_total(), 3, '.', '');
                    break;
                case 'ACCORD':
                    // validate order and save the transaction id
                    $order->add_order_note(__('Credit Card Transaction Approved', 'Sps'), 1);
                    $order->payment_complete($transaction_id);
                    break;
                case 'ERREUR':
                    // cancel order and add notice
                    $order->update_status('failed', __('Transaction error', 'Sps'));
                    $order->reduce_order_stock();
                    break;
                case 'REFUS':
                    // cancel order and add notice
                    $order->cancel_order(__('Transaction refused', 'Sps'));
                    break;
                case 'ANNULATION':
                    // cancel order and add notice
                    $order->cancel_order(__('Transaction canceled', 'Sps'));
                    break;
                default:
                    $response = [];
                    break;
            };

            echo http_build_query($response);
            exit;
        }

        /**
         * Logging method.
         * @param string $message
         */
        public static function log( $message )
        {
            if (empty(self::$log)) {
                self::$log = new WC_Logger();
            }
            self::$log->add('sps', $message);
        }
    }

    /**
     * Add the Gateway to WooCommerce
     * @param $methods
     * @return array
     */
    function woocommerce_add_sps_gateway($methods)
    {
        $onlyAdmin = false;
        if ($settings = get_option('woocommerce_sps_settings')) {
            $onlyAdmin = ($settings['test_mode'] == 'yes');
        }
        if (!$onlyAdmin || $onlyAdmin && current_user_can('administrator')) {
            $methods[] = 'WC_Gateway_Sps';
        }

        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_sps_gateway');

    /**
     *
     */
    function language_sps_gateway() {
        $plugin_dir = basename(dirname(__FILE__)).'/languages/';
        load_plugin_textdomain('Sps', false, $plugin_dir );
    }
    add_action('plugins_loaded', 'language_sps_gateway');
}
/*
url control https://velvet-cafe.com?wc-api=WC_Gateway_Sps