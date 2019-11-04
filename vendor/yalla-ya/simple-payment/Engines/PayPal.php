<?php
namespace SimplePayment\Engines;

use SimplePayment\SimplePayment;

use PayPal\Api\Amount;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;

if (!defined("ABSPATH")) {
  exit; // Exit if accessed directly
}
/*
buttons
<script
    src="https://www.paypal.com/sdk/js?client-id=SB_CLIENT_ID">
  </script>

  <div id="paypal-button-container"></div>

  <script>
    paypal.Buttons().render('#paypal-button-container');
  </script>
  */
class PayPal extends Engine {

  public $name = 'PayPal';
  public $api = [
      'post' => 'https://www.paypal.com/cgi-bin/webscr',
      'sandbox' => [
          'post' => 'https://www.sandbox.paypal.com/cgi-bin/webscr'
      ]
  ];
  public $context;
  public $clientId;
  public $password;

  public function __construct($params = null, $handler = null, $sandbox = true) {
    parent::__construct($params, $handler, $sandbox);
    $this->clientId = $this->param('client_id');
    $this->password = $this->param('client_secret');
    if ($this->clientId && $this->password) $this->context = $this->getApiContext($this->clientId, $this->password);
  }

  public function post_process($params) {
    //&paymentId=PAYID-LWNTCSI09697453H2945450G&token=EC-8A964303H1112514L&PayerID=DA5RML92QXCAE

    $this->transaction = $_REQUEST['paymentId'];
    //$token = $_REQUEST['token'];
    //$payer = $_REQUEST['PayerID'];
    $this->save([
      'transaction_id' => $this->transaction,
      'url' => '',
      'status' => '',
      'description' => isset($_REQUEST['token']) ? $_REQUEST['token'] : null,
      'request' => json_encode($params),
      'response' => json_encode($_REQUEST)
    ]);
    return(true);
  }

  public function process($params) {
    if ($this->context) {
      header("Location: ".$params['url']);
      return(true);
    }
    $post = $params;
    // Process the transaction, for example
    // - Call payment gateway API
    // - Redirect to the payment gateway url with params
    // Return FALSE if transaction failed
    echo '<form id="frm" action="'.($this->sandbox ? $this->api['sandbox']['post'] : $this->api['post']).'" method="post">';
    foreach ($post as $key => $value) {
        echo '<input type="hidden" name="'.htmlentities($key).'" value="'.htmlentities($value).'">';
    }
    echo '</form><script>document.getElementById("frm").submit();</script>';
    die;
    return(true);
  }

  public function pre_process($params) {
    // no_shipping, lc, image_url

    // TODO : add additional known fields if known
    $this->transaction = $this->uuid();
    $amount = $params['amount'];
    $currency = $this->param('currency');
    $concept = $params['concept'];
    if ($this->context) {
      $payer = new Payer();
      $payer->setPaymentMethod("paypal"); // $this->param('paypal_method')
      $item = new Item();
      $item->setName($concept)->setCurrency($currency)->setQuantity(1)->setPrice($amount);
      $list = new ItemList();
      $list->setItems(array($item));
      $amnt = new Amount();
      $amnt->setCurrency($currency)->setTotal($amount);
      $transaction = new Transaction();
      $transaction->setAmount($amnt)
          ->setItemList($list)
          ->setDescription($concept)
          ->setInvoiceNumber($this->transaction);
      $redirectUrls = new RedirectUrls();
      $redirectUrls->setReturnUrl($this->url(SimplePayment::OPERATION_SUCCESS))
        ->setCancelUrl($this->url(SimplePayment::OPERATION_CANCEL));
        // TODO add status usrl
      $payment = new Payment();
      $payment->setIntent("sale")
          ->setPayer($payer)
          ->setRedirectUrls($redirectUrls)
          ->setTransactions(array($transaction));
      try {
        $payment->create($this->context);
      } catch (Exception $e) {
        
      }
      $this->transaction = $payment->getId();
      $params['url'] = $payment->getApprovalLink();
    } 

    // for Express Checkout tradiaionl form post
    $post = [];
    $post['cmd'] = '_xclick';
    $post['hosted_button_id'] = $this->param('hosted_button_id');;
    $post['item_name'] = $concept;
    $post['currency_code'] = $currency;
    $post['business'] = $this->param('business');
    $post['amount'] = $amount;
    $post['return'] = $this->url(SimplePayment::OPERATION_SUCCESS);
    $post['cancel_return'] = $this->url(SimplePayment::OPERATION_CANCEL);
    $post['notify_url'] = $this->url(SimplePayment::OPERATION_STATUS);
    $post['rm'] = '2';
    $post['url'] = $this->sandbox ? $this->api['sandbox']['post'] : $this->api['post'];
    $params['post'] = $post;
    $this->save([
      'transaction_id' => $this->transaction,
      'url' => $post['url'],
      'status' => null,
      'description' => 'Express Checkout',
      'request' => json_encode($post),
      'response' => null
    ]);
    return($params);
  }

  public function setCallback($url) {
    $this->callback = $url;
  }

  protected function save($params) {
      return(parent::save($params));
  }

  function getApiContext($clientId, $clientSecret) {
    $apiContext = new ApiContext(new OAuthTokenCredential($clientId, $clientSecret));
    $apiContext->setConfig([
      'mode' => $this->sandbox ? 'sandbox' : 'live',
      //'log.LogEnabled' => true,
      //'log.FileName' => 'PayPal.log',
      //'log.LogLevel' => 'DEBUG', // PLEASE USE `INFO` LEVEL FOR LOGGING IN LIVE ENVIRONMENTS
      //'cache.enabled' => true,
      //'cache.FileName' => '/PaypalCache' // for determining paypal cache directory
      //'http.CURLOPT_CONNECTTIMEOUT' => 30
      //'http.headers.PayPal-Partner-Attribution-Id' => '123123123'
      //'log.AdapterFactory' => '\PayPal\Log\DefaultLogFactory' // Factory class implementing \PayPal\Log\PayPalLogFactory
      ]);
    return($apiContext);
  }

}
