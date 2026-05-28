<?php
/**
 * Customer-facing payment page (QR + polling).
 * Also serves a JSON poll endpoint when ?poll=1 is set.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NakoPayStatusModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $invoice_id = Tools::getValue('invoice');
        if (!$invoice_id) {
            Tools::redirect('index.php');
        }
        require_once dirname(__FILE__) . '/../../classes/NakoPayClient.php';
        require_once dirname(__FILE__) . '/../../classes/NakoPayOrders.php';

        $row = NakoPayOrders::findByInvoice((string) $invoice_id);
        if (!$row) {
            Tools::redirect('index.php');
        }

        if ((int) Tools::getValue('poll') === 1) {
            $client = new NakoPayClient();
            $resp   = $client->getInvoice((string) $invoice_id);
            $status = $row['status'];
            if (!empty($resp['_ok']) && !empty($resp['status'])) {
                $status = (string) $resp['status'];
                if ($status !== $row['status']) {
                    NakoPayOrders::updateStatus($invoice_id, $status, isset($resp['tx_hash']) ? (string) $resp['tx_hash'] : null);
                    if (in_array($status, ['paid', 'completed'], true) && (int) $row['id_order'] > 0) {
                        $this->markOrderPaid((int) $row['id_order']);
                    }
                }
            }
            $payload = ['status' => $status];
            if (in_array($status, ['paid', 'completed'], true) && (int) $row['id_order'] > 0) {
                $order = new Order((int) $row['id_order']);
                $payload['redirect'] = $this->context->link->getPageLink(
                    'order-confirmation',
                    true,
                    null,
                    [
                        'id_cart'   => (int) $row['id_cart'],
                        'id_module' => (int) $this->module->id,
                        'id_order'  => (int) $row['id_order'],
                        'key'       => $order->secure_key,
                    ]
                );
            }
            header('Content-Type: application/json');
            echo json_encode($payload);
            exit;
        }
    }

    public function initContent()
    {
        parent::initContent();

        $invoice_id = Tools::getValue('invoice');
        require_once dirname(__FILE__) . '/../../classes/NakoPayOrders.php';
        $row = NakoPayOrders::findByInvoice((string) $invoice_id);
        if (!$row) {
            Tools::redirect('index.php');
        }

        $poll_url = $this->context->link->getModuleLink(
            $this->module->name,
            'status',
            ['invoice' => $row['nakopay_invoice_id'], 'poll' => 1],
            true
        );

        $this->context->controller->registerJavascript('nakopay-qrious', 'modules/nakopay/views/js/vendors/qrious.min.js', ['priority' => 90]);
        $this->context->controller->registerJavascript('nakopay-checkout', 'modules/nakopay/views/js/checkout.js', ['priority' => 100]);
        $this->context->controller->registerStylesheet('nakopay-style', 'modules/nakopay/views/css/style.css', ['priority' => 100]);

        $this->context->smarty->assign([
            'nakopay'  => [
                'invoice_id' => $row['nakopay_invoice_id'],
                'address'    => $row['address'],
                'amount'     => $row['amount_crypto'],
                'currency'   => $row['currency'],
                'coin'       => $row['crypto_code'],
                'fiat'       => $row['amount_fiat'],
                'bip21'      => $row['payment_uri'],
                'status'     => $row['status'],
                'poll_url'   => $poll_url,
            ],
        ]);

        $this->setTemplate('module:nakopay/views/templates/front/status.tpl');
    }

    private function markOrderPaid($id_order)
    {
        $order = new Order($id_order);
        if (!Validate::isLoadedObject($order)) {
            return;
        }
        $paidStateId = (int) Configuration::get('PS_OS_PAYMENT');
        if ($paidStateId > 0 && (int) $order->current_state !== $paidStateId) {
            $order->setCurrentState($paidStateId);
        }
    }
}
