<?php

use \Firebase\JWT;

// Exit if accessed directly
defined('WPINC') || die;


/**
 * @file
 * REST API Endpoint that handles lightning invoice creation and verification
 */
class LNP_InvoicesController extends \WP_REST_Controller
{

    public function register_routes()
    {

        $this->namespace = 'lnp-alby/v1';

        register_rest_route(
            $this->namespace,
            "invoices",
            array(
                array(
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'process_create_invoice_request'),
                    'permission_callback' => '__return_true',
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            'invoices/verify',
            array(
                array(
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'process_verify_invoice_request'),
                    'permission_callback' => '__return_true',
                ),
            )
        );
    }



    /**
     * Process create lightning invoice request
     *
     * @param  object $request WP_REST_Request
     * @return array           Invoice data or error message
     */
    public function process_create_invoice_request( $request )
    {
        $plugin = $this->get_plugin();
        $post_id = intval($request->get_param('post_id'));
        $amount = intval($request->get_param('amount'));
        $currency = $request->get_param('currency');
        if (empty($currency)) {
            $currency = 'btc';
        }
        $currency = strtolower($currency);

        // get sats amount
        if ($currency != 'btc') {
            $exchange_rate = $plugin->get_current_exchange_rate($currency);
            $amount = $plugin->convert_to_sats($amount, $currency, $exchange_rate);
        } else {
            $exchange_rate = 1;
        }

        $comment = intval($request->get_param('comment'));
        $memo = $request->get_param('memo');

        if (!empty($post_id) && empty($memo)) {
            $memo = get_bloginfo('name') . ' - ' . get_the_title($post_id);
        }
        if (empty($post_id) && empty($memo)) {
            $memo = get_bloginfo('name');
        }

        $memo = substr($memo, 0, 64);
        $memo = preg_replace('/[^\w_ ]/', '', $memo);

        $invoice_params = [
            'memo' => $memo,
            'value' => $amount, // in sats
            'expiry' => 1800,
            'private' => true
        ];
        $invoice = $plugin->getLightningClient($post_id)->addInvoice($invoice_params);

        $plugin->getDatabaseHandler()->store_invoice(
            [
            "post_id" => $post_id,
            "payment_hash" => $invoice['r_hash'],
            "payment_request" => $invoice['payment_request'],
            "comment" => '',
            "amount_in_satoshi" => $amount,
            "exchange_currency" => $currency,
            "exchange_rate" => $exchange_rate
            ]
        );

        $response_data = ['post_id' => $post_id, 'amount' => $amount];

        // if the invoice includes a custom invoice_id we use that
        // otherwise we default to the r_hash
        // this is currently mainly used in the lightning address
        $invoice_id = empty($invoice['id']) ? $invoice['r_hash'] : $invoice['id'];
        $jwt_data = array_merge($response_data, ['invoice_id' => $invoice_id, 'r_hash' => $invoice['r_hash'], 'exp' => time() + 60 * 10]);
        $jwt = JWT\JWT::encode($jwt_data, BLN_PUBLISHER_PAYWALL_JWT_KEY,  BLN_PUBLISHER_PAYWALL_JWT_ALGORITHM);

        $response = array_merge($response_data, ['token' => $jwt, 'payment_request' => $invoice['payment_request']]);

        return rest_ensure_response($response);
    }



    /**
     * Verify if an invoice (payment hash) is paid
     *
     * @param  object $request WP_REST_Request
     * @return array           Invoice data or error message
     */
    public function process_verify_invoice_request( $request )
    {
        $plugin = $this->get_plugin();
        $token    = $request->get_param('token');
        $preimage = $request->get_param('preimage');

        if (empty($token)) {
            return wp_send_json(['settled' => false], 404);
        }
        try {
            $jwt = JWT\JWT::decode($token, new JWT\Key(BLN_PUBLISHER_PAYWALL_JWT_KEY, BLN_PUBLISHER_PAYWALL_JWT_ALGORITHM));
        } catch (\Exception $e) {
            return wp_send_json(['settled' => false], 404);
        }

        // if we get a preimage we can check if the preimage matches the payment hash and accept it.
        if (!empty($preimage) && hash('sha256', hex2bin($preimage), false) == $jwt->{"r_hash"}) {
            $invoice = ['settled' => true];
            // if ew do not have a preimage we must check with the LN node if the invoice was paid.
        } else {
            $post_id = $jwt->{'post_id'};
            $invoice_id = $jwt->{'invoice_id'};
            $invoice = $plugin->getLightningClient($post_id)->getInvoice($invoice_id);
        }

        // TODO check amount?
        if ($invoice && $invoice['settled']) { // && (int)$invoice['value'] == (int)$jwt->{'amount'}) {
            $plugin->getDatabaseHandler()->update_invoice_state($jwt->{'r_hash'}, 'settled');

            wp_send_json(['settled' => true], 200);
        } else {
            wp_send_json(['settled' => false], 402);
        }
    }

    /**
     * Main plugin instance
     *
     * @return object
     */
    private function get_plugin()
    {
        return $this->plugin;
    }


    /**
     * Main plugin instance
     * This will provide access to LND Client
     *
     * @param object $plugin
     */
    public function set_plugin_instance( &$plugin )
    {
        $this->plugin = $plugin;
    }


    /**
     * Attributes
     */
    public function get_endpoint_args_for_item_schema( $method = \WP_REST_Server::CREATABLE )
    {

        $params = array();

        $params['post_id'] = array(
            'default'           => 0,
            'description'       => __('ID of the post that is requested for payment', 'lnp-alby'),
            'type'              => 'integer',
            'sanitize_callback' => 'intval',
            'validate_callback' => 'rest_validate_request_arg',
        );

        $params['amount'] = array(
            'default'           => 0,
            'description'       => __('Invoice amount', 'lnp-alby'),
            'type'              => 'integer',
            'sanitize_callback' => 'intval',
            'validate_callback' => 'rest_validate_request_arg',
        );

        return $params;
    }
}
