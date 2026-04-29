<?php
/**
 * HMAC-SHA256 webhook receiver. Source of truth for payment completion.
 *
 * Replaces Blockonomics' insecure `?secret=` query-param scheme with the
 * standard signed-event pattern used by every other NakoPay plugin:
 *   X-NakoPay-Signature: t=<unix>,v1=<hex>
 *   body = JSON event payload, hashed as `t.body` with the webhook secret.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NakoPayWebhookModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;
    public $ajax = true;

    public function initContent()
    {
        // Skip the normal content rendering pipeline.
    }

    public function init()
    {
        parent::init();

        $raw = (string) file_get_contents('php://input');
        $sig = isset($_SERVER['HTTP_X_NAKOPAY_SIGNATURE']) ? (string) $_SERVER['HTTP_X_NAKOPAY_SIGNATURE'] : '';

        require_once dirname(__FILE__) . '/../../classes/NakoPayClient.php';
        require_once dirname(__FILE__) . '/../../classes/NakoPayOrders.php';

        $client = new NakoPayClient();
        if (!$client->verifyWebhook($raw, $sig)) {
            http_response_code(401);
            echo 'invalid signature';
            exit;
        }

        $event = json_decode($raw, true);
        if (!is_array($event) || empty($event['data']['id'])) {
            http_response_code(400);
            echo 'invalid payload';
            exit;
        }

        $invoice_id = (string) $event['data']['id'];
        $status     = isset($event['data']['status']) ? (string) $event['data']['status'] : 'pending';
        $tx         = isset($event['data']['tx_hash']) ? (string) $event['data']['tx_hash'] : '';

        $row = NakoPayOrders::findByInvoice($invoice_id);
        if (!$row) {
            http_response_code(404);
            echo 'unknown invoice';
            exit;
        }

        NakoPayOrders::updateStatus($invoice_id, $status, $tx);

        if (in_array($status, ['paid', 'completed'], true) && (int) $row['id_order'] > 0) {
            $order = new Order((int) $row['id_order']);
            if (Validate::isLoadedObject($order)) {
                $paid = (int) Configuration::get('PS_OS_PAYMENT');
                if ($paid > 0 && (int) $order->current_state !== $paid) {
                    $order->setCurrentState($paid);
                }
            }
        } elseif ($status === 'processing' && (int) $row['id_order'] > 0) {
            $order    = new Order((int) $row['id_order']);
            $detected = (int) Configuration::get('NAKOPAY_OS_DETECTED');
            if (Validate::isLoadedObject($order) && $detected > 0 && (int) $order->current_state !== $detected) {
                $order->setCurrentState($detected);
            }
        }

        http_response_code(200);
        echo 'ok';
        exit;
    }
}
