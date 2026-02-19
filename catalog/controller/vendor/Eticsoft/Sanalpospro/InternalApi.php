<?php

/**
 * Class InternalApi 
 * @package Eticsoft\Paythor\Sanalpospro
 * @description InternalApi class is used to handle the internal api requests 
 * from the module admin UI. 
 * @version 1.0
 * @since 1.0
 * @author EticSoft A.Ş.
 * @license MIT
 */

namespace Eticsoft\Paythor\Sanalpospro;

use Eticsoft\Paythor\Sanalpospro\EticConfig;
use Eticsoft\Paythor\Sanalpospro\EticTools;
use Eticsoft\Paythor\Sanalpospro\Payment;
use Eticsoft\Paythor\Sanalpospro\ApiClient;

use Eticsoft\Sanalpospro\Common\Models\Cart;
use Eticsoft\Sanalpospro\Common\Models\Payer;
use Eticsoft\Sanalpospro\Common\Models\Order;
use Eticsoft\Sanalpospro\Common\Models\Invoice;
use Eticsoft\Sanalpospro\Common\Models\Address;
use Eticsoft\Sanalpospro\Common\Models\Shipping;
use Eticsoft\Sanalpospro\Common\Models\PaymentRequest;
use Eticsoft\Sanalpospro\Common\Models\PaymentModel;
use Eticsoft\Sanalpospro\Common\Models\CartItem;



class InternalApi
{
    public ?string $action = '';
    public ?string $payload = '';
    public ?array $params = [];
    public ?array $response = [
        'status' => 'error',
        'message' => 'Internal error',
        'data' => [],
        'xfvv' => '',

    ];
    public ?string $xfvv = '';
    public function run(): self
    {
        $this->setAction()->setParams()->setXfvv()->call();
        return $this;
    }

    public static function getInstance(): self
    {
        return new self();
    }

    public function setXfvv(): self
    {
        $this->xfvv = EticTools::postVal('iapi_xfvv', false);
        return $this;
    }

    public function setAction(): self
    {
        $this->action = EticTools::postVal('iapi_action', false);
        return $this;
    }

    public function setController($controller): self
    {
        EticTools::setController($controller);
        return $this;
    }

    public function setParams(): self
    {
        $params = EticTools::postVal('iapi_params', '');
        if (empty($params)) {
            return $this;
        }
        $params = str_replace('&quot;', '"', $params);
        $this->params = json_decode($params, true);
        return $this;
    }

    public function setSettings($settings): self
    {
        EticConfig::setSettings($settings);
        return $this;
    }

    public function call(): self
    {
        if (!$this->action) {
            return $this->setResponse('error', 'Action not found. #' . $this->action);
        }
        //make action first letter uppercase
        $this->action = ucfirst($this->action);
        if (!method_exists($this, 'action' . $this->action)) {
            return $this->setResponse('error', 'Action func not found. #' . 'action' . $this->action);
        }
        if ($this->xfvv != EticConfig::get('xfvv')) {
            return $this->setResponse('error', 'XFVV not matched');
        }
        $f_name = 'action' . $this->action;
        return $this->$f_name();
    }

    public function setResponse(string $status = 'success', string $message = '', array $data = [], array $details = [], array $meta = []): self
    {
        $this->response = [
            'status' => $status,
            'message' => $message,
            'data' => $data,
            'details' => $details,
            'meta' => $meta
        ];

        if ($status != 'success') {
            unset($this->response['data']);
        }

        return $this;
    }

    private function actionSaveApiKeys(): self
    {
        try {
            $publicKey = $this->params['iapi_publicKey'];
            if ($publicKey) {
                EticConfig::set('public_key', $publicKey);
            }
            $secretKey = $this->params['iapi_secretKey'];
            if ($secretKey) {
                EticConfig::set('secret_key', $secretKey);
            }
            $this->setResponse('success', 'Api keys saved');
            return $this;
        } catch (\Exception $e) {
            $this->setResponse('error', $e->getMessage());
            return $this;
        }
    }

    private function actionCheckApiKeys(): self
    {
        try {
            if (!EticConfig::get('public_key') || !EticConfig::get('secret_key')) {
                $this->setResponse('error', 'Api keys not found');
                return $this;
            }
            $apiClient = ApiClient::getInstanse();
            $this->response = $apiClient->post('/check/accesstoken', [
                'accesstoken' => $this->params['iapi_accessToken']
            ]);

            return $this;
        } catch (\Exception $e) {
            $this->setResponse('error', $e->getMessage());
            return $this;
        }
    }

    private function actionSetInstallmentOptions(): self
    {
        try {
            $installmentOptions = $this->params['iapi_installmentOptions'];
            if (empty($installmentOptions)) {
                $this->setResponse('error', 'Invalid installment options');
                return $this;
            }
            EticConfig::set('installments', json_encode($installmentOptions));
            $this->setResponse('success', 'Installment options updated');
            return $this;
        } catch (\Exception $e) {
            $this->setResponse('error', $e->getMessage());
            return $this;
        }
    }

    private function actionCreatePaymentLink(): self
    {
        try {

            if (empty(EticTools::getSessionData('order_id'))) {
                EticTools::redirectToCart();
                return $this;
            }

            $order = EticTools::getOrder();
            $order_id = EticTools::getOrderId();

            if (empty($order)) {
                $this->setResponse('error', 'Order not found');
                return $this;
            }
            if ($order['payment_method']['code'] != 'sanalpospro.sanalpospro' && $order['payment_method']['code'] != 'sanalpospro') {
                $this->setResponse('error', 'Payment method not found');
                return $this;
            }
            // contexts
            $currency = EticTools::getCurrency();
            $cart = EticTools::getCart();
            $cart_items = $cart->getProducts();
            $cart_totals = EticTools::getCartTotals();
            $cart_total = EticTools::getAmountCurrencyFormated($cart_totals['total']);
            $discount = abs($cart_totals['coupon']);
            $shipping_cost = EticTools::getShippingCost();

            // Create Cart instance
            $cartModel = new Cart();

            // Add products to cart
            // discount vs de kontrol edilip eklenilecek
            foreach ($cart_items as $product) {
                $cartItem = new CartItem(
                    $product['product_id'],
                    $product['name'],
                    'product',
                    EticTools::getAmountCurrencyFormated($product['price']),
                    $product['quantity']
                );
                $cartModel->addItem($cartItem);
            }

            // Add discounts to cart
            if ($discount > 0) {
                $discountItem = new CartItem(
                    'DSC-' . EticTools::getSessionData('coupon'),
                    EticTools::getSessionData('coupon') . ' İndirim Kuponu',
                    'discount',
                    EticTools::getAmountCurrencyFormated($discount),
                    1
                );
                $cartModel->addItem($discountItem);
            }

            if ($shipping_cost > 0) {
                $shippingItem = new CartItem(
                    'SHP-1',
                    'Kargo Ücreti',
                    'shipping',
                    EticTools::getAmountCurrencyFormated($shipping_cost),
                    1
                );
                $cartModel->addItem($shippingItem);
            }


            foreach ($cart_totals['taxes'] as $key => $tax) {
                if ($tax['value'] == 0) {
                    continue;
                }
                $taxItem = new CartItem(
                    'TAX-' . $key,
                    $tax['title'],
                    'tax',
                    EticTools::getAmountCurrencyFormated($tax['value']),
                    1
                );
                $cartModel->addItem($taxItem);
            }

            $shippingAddress = EticTools::getSessionData('shipping_address');
            $paymentAddress = EticTools::getSessionData('payment_address');

            if (empty($shippingAddress) && empty($paymentAddress)) {
                $shippingAddress['address_1'] = 'test';
                $shippingAddress['city'] = 'test';
                $shippingAddress['zone'] = 'test';
                $shippingAddress['postcode'] = 'test';
                $shippingAddress['country'] = 'test';
                $shippingAddress['firstname'] = 'test';
                $shippingAddress['lastname'] = 'test';
                $shippingAddress['telephone'] = '5000000000';
                $paymentAddress = $shippingAddress;
            } else if (empty($shippingAddress)) {
                $shippingAddress = $paymentAddress;
            } else if (empty($paymentAddress)) {
                $paymentAddress = $shippingAddress;
            }

            $shippingAddress = (object) $shippingAddress;
            $paymentAddress = (object) $paymentAddress;

            $customer = (object) EticTools::getSessionData('customer');

            $payment = new PaymentModel();
            $payment->setAmount($cart_total);
            $payment->setCurrency($currency);
            $payment->setBuyerFee(0);
            $payment->setMethod('creditcard');
            $payment->setMerchantReference($order_id);
            /*             $baseUrl = EticTools::getLink('extension/sanalpospro/api/sanalposproiapi');
                        $params = [
                            'action' => 'orderConfirmation',
                            'nonce' => $this->xfvv
                        ];
                        $returnUrl = $baseUrl . '&' . http_build_query($params);
                        $payment->setReturnUrl($returnUrl); */

            $payerAddress = new Address();
            $payerAddress->setLine1($paymentAddress->address_1);
            $payerAddress->setCity($paymentAddress->city);
            $payerAddress->setState($paymentAddress->zone);
            $payerAddress->setPostalCode($paymentAddress->postcode);
            $payerAddress->setCountry($paymentAddress->country);


            $phone = !empty($customer->telephone) ? $customer->telephone : '5000000000';


            $payer = new Payer();
            $payer->setFirstName($customer->firstname);
            $payer->setLastName($customer->lastname);
            $payer->setEmail($customer->email);
            $payer->setPhone($phone);
            $payer->setAddress($payerAddress);
            $payer->setIp($_SERVER['REMOTE_ADDR']);


            $invoice = new Invoice();
            $invoice->setId($order_id);
            $invoice->setFirstName($customer->firstname);
            $invoice->setLastName($customer->lastname);
            $invoice->setPrice($cart_total);
            $invoice->setQuantity(1);

            $shipmentAddress = new Address();
            $shipmentAddress->setLine1($shippingAddress->address_1);
            $shipmentAddress->setCity($shippingAddress->city);
            $shipmentAddress->setState($shippingAddress->zone);
            $shipmentAddress->setPostalCode($shippingAddress->postcode);
            $shipmentAddress->setCountry($shippingAddress->country);

            $shipping = new Shipping();
            $shipping->setFirstName($shippingAddress->firstname);
            $shipping->setLastName($shippingAddress->lastname);
            $shipping->setPhone($phone);
            $shipping->setEmail($customer->email);
            $shipping->setAddress($shipmentAddress);

            $order = new Order();
            $order->setCart($cartModel->toArray()['items']);
            $order->setShipping($shipping);
            $order->setInvoice($invoice);


            $paymentRequest = new PaymentRequest();
            $paymentRequest->setPayment($payment);
            $paymentRequest->setPayer($payer);
            $paymentRequest->setOrder($order);

            $result = Payment::createPayment($paymentRequest->toArray());

            $this->response = $result;
            return $this;
        } catch (\Exception $e) {
            $this->setResponse('error', $e->getMessage());
            return $this;
        }
    }

    private function actionConfirmOrder(): self
    {
        try {
            $order = EticTools::getOrder();
            $process_token = $this->params['process_token'];
            $res = Payment::validatePayment($process_token);

            if ($res['status'] != 'success') {
                $redirect_url = EticTools::getLink('checkout/failure');
                $this->setResponse('error', 'Order confirmation failed', [], [
                    'redirect_url' => $redirect_url
                ]);
                return $this;
            }

            $processData = $res['data']['process'];
            $data = $res['data']['transaction'];

            if ($data['status'] == 'completed' && $processData['process_status'] == 'completed') {
                $transaction_amount = $processData['amount'];

                EticTools::addOrderHistory($processData['payment_token']);
                if (floatval($transaction_amount) > floatval(EticTools::getAmountCurrencyFormated($order['total']))) {
                    EticTools::addCommissionFeeToTotal(EticTools::getOrderId(), floatval($transaction_amount) - EticTools::getAmountCurrencyFormated($order['total']));
                }

                $redirect_url = EticTools::getLink('checkout/success');
                $this->setResponse('success', 'Order confirmed', [
                    'redirect_url' => $redirect_url
                ]);
                return $this;
            } else {
                $redirect_url = EticTools::getLink('checkout/failure');
                $this->setResponse('error', 'Order confirmation failed', [
                    'redirect_url' => $redirect_url
                ]);
                return $this;
            }
        } catch (\Exception $e) {
            $redirect_url = EticTools::getLink('checkout/failure');
            $this->setResponse('error', 'Order confirmation failed', [
                'redirect_url' => $redirect_url
            ]);
            return $this;
        }
    }

    private function actionSetModuleSettings(): self
    {
        $settings = $this->params['iapi_moduleSettings'];
        try {
            if ($settings) {
                foreach ($settings as $key => $value) {
                    EticConfig::set($key, $value);
                }
                $this->setResponse('success', 'Module settings updated');
            } else {
                $this->setResponse('error', 'Invalid module settings');
            }
        } catch (\Exception $e) {
            $this->setResponse('error', 'Failed to update module settings: ' . $e->getMessage());
        }
        return $this;
    }

    private function actionGetMerchantInfo(): self
    {
        try {
            $country_info = EticTools::getCountryInfo(EticTools::getConfigData('config_country_id'));
            $currency_info = EticTools::getCurrencyInfo(EticTools::getConfigData('config_currency'));
            $data = [
                'store' => [
                    'name' => EticTools::getConfigData('config_name'),
                    'url' => EticTools::getConfigData('site_url'),
                    'admin_email' => EticTools::getConfigData('config_email'),
                    'phone' => EticTools::getConfigData('config_telephone'),
                    'address' => [
                        'address' => EticTools::getConfigData('config_address'),
                        'country' => $country_info ? $country_info['name'] : 'TR'
                    ],
                    'language' => EticTools::getConfigData('config_language_admin')
                ],
                'payment' => [
                    'currency' => EticTools::getConfigData('config_currency'),
                    'currency_symbol' => $currency_info ? $currency_info['symbol_right'] : '₺'
                ]
            ];

            $this->setResponse('success', 'Merchant info retrieved successfully', $data);
            return $this;
        } catch (\Exception $e) {
            $this->setResponse('error', 'Failed to get merchant info: ' . $e->getMessage());
            return $this;
        }
    }

    public function getResponse()
    {
        return $this->response;
    }
}
