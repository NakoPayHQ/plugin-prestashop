<?php
/**
 * Customer checkout controller - opens the NakoPay invoice, persists the
 * mapping row, validates a PrestaShop order in the "Awaiting" state, then
 * redirects to the status page where the QR + polling loop lives.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class NakoPayPaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $cart = $this->context->cart;

        if (!Validate::isLoadedObject($cart)
            || !$cart->id_customer
            || !$cart->id_address_delivery
            || !$cart->id_address_invoice
            || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === $this->module->name) {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            $this->errors[] = $this->module->l('NakoPay is not enabled.', 'payment');
            return $this->setTemplate('module:nakopay/views/templates/front/error.tpl');
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $currency = new Currency((int) $cart->id_currency);
        $total    = (float) $cart->getOrderTotal(true, Cart::BOTH);

        require_once dirname(__FILE__) . '/../../classes/NakoPayClient.php';
        require_once dirname(__FILE__) . '/../../classes/NakoPayOrders.php';
        $client = new NakoPayClient();

        $row = NakoPayOrders::findOpenForCart((int) $cart->id);
        if (!$row) {
            $resp = $client->createInvoice([
                'amount'         => number_format($total, 2, '.', ''),
                'currency'       => $currency->iso_code,
                'coin'           => Configuration::get('NAKOPAY_DEFAULT_COIN') ?: 'BTC',
                'description'    => sprintf('PrestaShop cart #%d', (int) $cart->id),
                'customer_email' => $customer->email,
                'cart_id'        => (int) $cart->id,
            ]);

            if (empty($resp['_ok']) || empty($resp['id'])) {
                $this->context->smarty->assign(['error_message' => isset($resp['_error']) ? $resp['_error'] : 'NakoPay invoice could not be opened.']);
                return $this->setTemplate('module:nakopay/views/templates/front/error.tpl');
            }

            $this->module->validateOrder(
                (int) $cart->id,
                $this->module->getOrderStateWaiting(),
                $total,
                $this->module->displayName,
                'NakoPay invoice ' . (string) $resp['id'],
                ['{nakopay_invoice_id}' => (string) $resp['id'], '{address}' => isset($resp['address']) ? (string) $resp['address'] : ''],
                (int) $currency->id,
                false,
                $customer->secure_key
            );

            NakoPayOrders::save([
                'id_cart'            => (int) $cart->id,
                'id_order'           => (int) $this->module->currentOrder,
                'nakopay_invoice_id' => (string) $resp['id'],
                'status'             => isset($resp['status']) ? (string) $resp['status'] : 'pending',
                'amount_fiat'        => $total,
                'currency'           => $currency->iso_code,
                'amount_crypto'      => isset($resp['amount_crypto']) ? (float) $resp['amount_crypto'] : null,
                'crypto_code'        => isset($resp['coin']) ? strtoupper((string) $resp['coin']) : 'BTC',
                'address'            => isset($resp['address']) ? (string) $resp['address'] : '',
                'payment_uri'        => isset($resp['payment_uri']) ? (string) $resp['payment_uri'] : '',
            ]);

            $row = NakoPayOrders::findOpenForCart((int) $cart->id);
        }

        Tools::redirect($this->context->link->getModuleLink(
            $this->module->name,
            'status',
            ['invoice' => $row['nakopay_invoice_id']],
            true
        ));
    }
}
