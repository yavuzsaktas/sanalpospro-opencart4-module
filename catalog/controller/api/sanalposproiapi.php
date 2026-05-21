<?php

namespace Opencart\Catalog\Controller\Extension\Sanalpospro\Api;

include_once DIR_EXTENSION . 'sanalpospro/catalog/controller/vendor/include.php';

use Eticsoft\Paythor\Sanalpospro\InternalApi;
use Eticsoft\Paythor\Sanalpospro\EticTools;

class Sanalposproiapi extends \Opencart\System\Engine\Controller
{
    public function index()
    {
        $action = EticTools::getVal('action', false);
        if (isset($action) && $action == 'orderConfirmation') {
            $this->orderConfirmation();
            return;
        }
        if (isset($action) && $action == 'callback') {
            $this->callback();
            return;
        }
        if (!isset($_SERVER['HTTP_REFERER']) || parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST) !== $_SERVER['HTTP_HOST']) {
            header('Content-Type: application/json');
            header('HTTP/1.0 403 Forbidden');
            die(json_encode(['status' => 'error', 'message' => 'Access denied 2']));
        }
        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting;
        $api = InternalApi::getInstance()->setSettings($settings)->setController($this)->run();
        header('Content-Type: application/json');
        die(json_encode($api->response));
    }

    private function orderConfirmation()
    {
        $nonce = EticTools::getVal('nonce', false);
        $p_id = EticTools::getVal('p_id', false);
        if (!$nonce || !$p_id) {
            header('Content-Type: application/json');
            header('HTTP/1.0 403 Forbidden');
            die(json_encode(['status' => 'error', 'message' => 'Access denied 1']));
        }
        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting;
        $api = InternalApi::getInstance()->setSettings($settings)->setController($this);
        $api->action = 'confirmOrder';
        $api->xfvv = $nonce;
        $api->params['process_token'] = $p_id;
        $api->call();

        if (isset($api->response["status"]) && isset($api->response["data"]["redirect_url"])) {
            header('Location: ' . $api->response["data"]["redirect_url"]);
            exit;
        }

        header('Content-Type: application/json');
        die(json_encode($api->response));
    }

    private function callback()
    {
        $nonce = EticTools::getVal('nonce', false);
        if (!$nonce || $nonce !== $this->config->get('payment_sanalpospro_xfvv')) {
            header('Content-Type: application/json');
            header('HTTP/1.0 403 Forbidden');
            die(json_encode(['status' => 'error', 'message' => 'Access denied']));
        }

        $requestData = json_decode(file_get_contents('php://input'), true);
        if (!is_array($requestData)) {
            header('Content-Type: application/json');
            header('HTTP/1.0 400 Bad Request');
            die(json_encode(['status' => 'error', 'message' => 'Invalid payload']));
        }

        $order_id = (int) ($requestData['oid'] ?? 0);
        if (!$order_id) {
            header('Content-Type: application/json');
            header('HTTP/1.0 400 Bad Request');
            die(json_encode(['status' => 'error', 'message' => 'Order id not found']));
        }

        $this->load->model('checkout/order');
        $order = $this->model_checkout_order->getOrder($order_id);
        if (empty($order)) {
            header('Content-Type: application/json');
            header('HTTP/1.0 404 Not Found');
            die(json_encode(['status' => 'error', 'message' => 'Order not found']));
        }

        $paid_status_id = !empty($this->config->get('payment_sanalpospro_order_status')) ? (int) $this->config->get('payment_sanalpospro_order_status') : 2;
        if ((int) $order['order_status_id'] === $paid_status_id) {
            http_response_code(200);
            header('Content-Type: application/json');
            die(json_encode(['status' => 'success']));
        }

        $hash = $requestData['hash'] ?? '';
        if (!$hash) {
            header('Content-Type: application/json');
            header('HTTP/1.0 400 Bad Request');
            die(json_encode(['status' => 'error', 'message' => 'Hash not found']));
        }

        $this->logCallbackRequest($order_id, $requestData);

        $this->session->data['order_id'] = $order_id;
        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting;
        $api = InternalApi::getInstance()->setSettings($settings)->setController($this);
        $api->action = 'confirmOrder';
        $api->isCallback = true;
        $api->xfvv = $this->config->get('payment_sanalpospro_xfvv');
        $api->params['process_token'] = $hash;
        $api->call();

        http_response_code(200);
        header('Content-Type: application/json');
        die(json_encode(['status' => 'success']));
    }

    private function logCallbackRequest(int $order_id, array $requestData): void {
        $logDir = DIR_LOGS . 'sanalpospro/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'order_id'  => $order_id,
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
            'payload'   => $requestData,
        ];

        $filename = $logDir . 'callback_' . $order_id . '_' . date('Ymd_His') . '.json';
        file_put_contents($filename, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
