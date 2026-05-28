<?php
/**
 * NakoPay for PrestaShop - payment module.
 *
 * Flow: cart -> pick crypto at checkout -> QR + address page (5s polling) ->
 * webhook completes the order. Payment-method registration uses the modern
 * PS 1.7+ `hookPaymentOptions` API, and the checkout UI is vanilla JS +
 * qrious so there is no Angular / jQuery / framework UI kit to ship.
 *
 * @author    NakoPay
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/NakoPayClient.php';
require_once __DIR__ . '/classes/NakoPayOrders.php';

class NakoPay extends PaymentModule
{
    /** @var array */
    private $postErrors = [];

    public function __construct()
    {
        $this->name                   = 'nakopay';
        $this->tab                    = 'payments_gateways';
        $this->version                = '1.0.0';
        $this->author                 = 'NakoPay';
        $this->need_instance          = 0;
        $this->bootstrap              = true;
        $this->controllers            = ['payment', 'status', 'webhook'];
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->currencies             = true;
        $this->currencies_mode        = 'checkbox';

        parent::__construct();

        $this->displayName      = $this->l('NakoPay (Bitcoin / Crypto)');
        $this->description      = $this->l('Accept Bitcoin and other crypto. Wallet-to-wallet, non-custodial, one flat fee.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the NakoPay module?');

        if (!Configuration::get('NAKOPAY_API_KEY')) {
            $this->warning = $this->l('NakoPay API key is not configured.');
        }
    }

    /* ------------------------------------------------------ install / uninstall */

    public function install()
    {
        if (!parent::install()
            || !$this->installOrderState('NAKOPAY_OS_WAITING', 'Awaiting crypto payment', '#FF8C00')
            || !$this->installOrderState('NAKOPAY_OS_DETECTED', 'Crypto payment detected', '#4169E1')
            || !$this->installDb()
            || !$this->registerHook('paymentOptions')
            || !$this->registerHook('paymentReturn')
            || !$this->registerHook('displayHeader')
        ) {
            return false;
        }

        Configuration::updateValue('NAKOPAY_API_KEY', '');
        Configuration::updateValue('NAKOPAY_WEBHOOK_SECRET', '');
        Configuration::updateValue('NAKOPAY_TEST_MODE', 0);
        Configuration::updateValue('NAKOPAY_API_BASE_OVERRIDE', '');
        Configuration::updateValue('NAKOPAY_TITLE', 'Pay with Bitcoin / Crypto');
        Configuration::updateValue('NAKOPAY_DESCRIPTION', 'Funds settle directly into the merchant wallet - non-custodial.');
        Configuration::updateValue('NAKOPAY_DEFAULT_COIN', 'BTC');

        return true;
    }

    public function uninstall()
    {
        // Note: we intentionally do NOT drop the orders table on uninstall, so
        // merchants don't lose payment history if they reinstall.
        return parent::uninstall()
            && $this->uninstallOrderState('NAKOPAY_OS_WAITING')
            && $this->uninstallOrderState('NAKOPAY_OS_DETECTED')
            && Configuration::deleteByName('NAKOPAY_API_KEY')
            && Configuration::deleteByName('NAKOPAY_WEBHOOK_SECRET')
            && Configuration::deleteByName('NAKOPAY_TEST_MODE')
            && Configuration::deleteByName('NAKOPAY_API_BASE_OVERRIDE')
            && Configuration::deleteByName('NAKOPAY_TITLE')
            && Configuration::deleteByName('NAKOPAY_DESCRIPTION')
            && Configuration::deleteByName('NAKOPAY_DEFAULT_COIN');
    }

    private function installOrderState($key, $name, $color)
    {
        if ((int) Configuration::get($key) > 0) {
            return true;
        }
        $os                = new OrderState();
        $os->name          = array_fill(0, 10, $name);
        $os->color         = $color;
        $os->send_email    = false;
        $os->template      = array_fill(0, 10, '');
        $os->hidden        = false;
        $os->delivery      = false;
        $os->logable       = false;
        $os->invoice       = false;
        $os->paid          = false;
        $os->module_name   = $this->name;
        if (!$os->add()) {
            return false;
        }
        Configuration::updateValue($key, (int) $os->id);
        return true;
    }

    private function uninstallOrderState($key)
    {
        $id = (int) Configuration::get($key);
        if ($id > 0) {
            $os = new OrderState($id);
            if (Validate::isLoadedObject($os)) {
                $os->delete();
            }
        }
        Configuration::deleteByName($key);
        return true;
    }

    private function installDb()
    {
        return NakoPayOrders::install();
    }

    /* ----------------------------------------------------------------- hooks */

    public function hookDisplayHeader($params)
    {
        return '';
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active || !$this->checkCurrency($params['cart'])) {
            return [];
        }
        if (!Configuration::get('NAKOPAY_API_KEY')) {
            return [];
        }

        $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setCallToActionText(Configuration::get('NAKOPAY_TITLE') ?: $this->l('Pay with Bitcoin / Crypto'))
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', [], true))
            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/logo.png'))
            ->setAdditionalInformation($this->fetch('module:nakopay/views/templates/hook/payment_option.tpl'));

        return [$option];
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return '';
        }
        $this->smarty->assign([
            'shop_name' => $this->context->shop->name,
            'reference' => isset($params['order']) ? $params['order']->reference : '',
        ]);
        return $this->fetch('module:nakopay/views/templates/hook/payment_return.tpl');
    }

    public function checkCurrency($cart)
    {
        $currency_order    = new Currency((int) $cart->id_currency);
        $currencies_module = $this->getCurrency((int) $cart->id_currency);
        if (is_array($currencies_module)) {
            foreach ($currencies_module as $c) {
                if ($currency_order->id == $c['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    /* -------------------------------------------------- admin configuration */

    public function getContent()
    {
        $html = '';
        if (Tools::isSubmit('btnSubmit')) {
            $this->postValidation();
            if (!count($this->postErrors)) {
                $this->postProcess();
                $html .= $this->displayConfirmation($this->l('Settings saved.'));
            } else {
                foreach ($this->postErrors as $err) {
                    $html .= $this->displayError($err);
                }
            }
        }
        return $html . $this->renderForm();
    }

    private function postValidation()
    {
        $key = trim((string) Tools::getValue('NAKOPAY_API_KEY'));
        if ($key !== '' && !preg_match('/^(sk|pk)_(live|test)_[A-Za-z0-9_-]+$/', $key)) {
            $this->postErrors[] = $this->l('API key looks malformed. Expected sk_live_… or sk_test_…');
        }
        $base = trim((string) Tools::getValue('NAKOPAY_API_BASE_OVERRIDE'));
        if ($base !== '' && !filter_var($base, FILTER_VALIDATE_URL)) {
            $this->postErrors[] = $this->l('API Base URL must be a valid URL or blank.');
        }
    }

    private function postProcess()
    {
        Configuration::updateValue('NAKOPAY_API_KEY', trim((string) Tools::getValue('NAKOPAY_API_KEY')));
        Configuration::updateValue('NAKOPAY_WEBHOOK_SECRET', trim((string) Tools::getValue('NAKOPAY_WEBHOOK_SECRET')));
        Configuration::updateValue('NAKOPAY_TEST_MODE', (int) Tools::getValue('NAKOPAY_TEST_MODE'));
        Configuration::updateValue('NAKOPAY_API_BASE_OVERRIDE', trim((string) Tools::getValue('NAKOPAY_API_BASE_OVERRIDE')));
        Configuration::updateValue('NAKOPAY_TITLE', trim((string) Tools::getValue('NAKOPAY_TITLE')));
        Configuration::updateValue('NAKOPAY_DESCRIPTION', trim((string) Tools::getValue('NAKOPAY_DESCRIPTION')));
        Configuration::updateValue('NAKOPAY_DEFAULT_COIN', strtoupper(trim((string) Tools::getValue('NAKOPAY_DEFAULT_COIN'))) ?: 'BTC');
    }

    private function renderForm()
    {
        $webhook_url = $this->context->link->getModuleLink($this->name, 'webhook', [], true);

        $fields_form = [
            'form' => [
                'legend' => ['title' => $this->l('NakoPay settings'), 'icon' => 'icon-cogs'],
                'input'  => [
                    ['type' => 'text',     'label' => $this->l('Title (shown at checkout)'), 'name' => 'NAKOPAY_TITLE',       'size' => 64, 'required' => true],
                    ['type' => 'textarea', 'label' => $this->l('Description'),               'name' => 'NAKOPAY_DESCRIPTION'],
                    ['type' => 'text',     'label' => $this->l('API Key'),                   'name' => 'NAKOPAY_API_KEY',     'size' => 64, 'desc' => $this->l('sk_live_… for production, sk_test_… for sandbox.')],
                    ['type' => 'text',     'label' => $this->l('Webhook Secret'),            'name' => 'NAKOPAY_WEBHOOK_SECRET', 'size' => 64, 'desc' => $this->l('Shown once when you create the webhook in the NakoPay dashboard.')],
                    ['type' => 'text',     'label' => $this->l('Webhook URL'),               'name' => 'NAKOPAY_WEBHOOK_URL', 'size' => 80, 'desc' => $this->l('Paste this into NakoPay → Webhooks.'), 'disabled' => true],
                    ['type' => 'switch',   'label' => $this->l('Test mode'),                 'name' => 'NAKOPAY_TEST_MODE', 'is_bool' => true,
                        'values' => [
                            ['id' => 'NAKOPAY_TEST_MODE_on',  'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'NAKOPAY_TEST_MODE_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    ['type' => 'text', 'label' => $this->l('Default coin'),                  'name' => 'NAKOPAY_DEFAULT_COIN',       'size' => 8],
                    ['type' => 'text', 'label' => $this->l('API Base URL (advanced)'),       'name' => 'NAKOPAY_API_BASE_OVERRIDE', 'size' => 80,
                        'desc' => $this->l('Leave blank for default. Used for self-hosted migration.')],
                ],
                'submit' => ['title' => $this->l('Save'), 'name' => 'btnSubmit'],
            ],
        ];

        $helper                          = new HelperForm();
        $helper->module                  = $this;
        $helper->name_controller         = $this->name;
        $helper->token                   = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex            = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language   = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->title                   = $this->displayName;
        $helper->submit_action           = 'btnSubmit';
        $helper->fields_value            = [
            'NAKOPAY_API_KEY'           => Configuration::get('NAKOPAY_API_KEY'),
            'NAKOPAY_WEBHOOK_SECRET'    => Configuration::get('NAKOPAY_WEBHOOK_SECRET'),
            'NAKOPAY_WEBHOOK_URL'       => $webhook_url,
            'NAKOPAY_TEST_MODE'         => (int) Configuration::get('NAKOPAY_TEST_MODE'),
            'NAKOPAY_TITLE'             => Configuration::get('NAKOPAY_TITLE'),
            'NAKOPAY_DESCRIPTION'       => Configuration::get('NAKOPAY_DESCRIPTION'),
            'NAKOPAY_DEFAULT_COIN'      => Configuration::get('NAKOPAY_DEFAULT_COIN') ?: 'BTC',
            'NAKOPAY_API_BASE_OVERRIDE' => Configuration::get('NAKOPAY_API_BASE_OVERRIDE'),
        ];

        return $helper->generateForm([$fields_form]);
    }

    /* ----------------------------------------------------------- accessors */

    public function getOrderStateWaiting()
    {
        return (int) Configuration::get('NAKOPAY_OS_WAITING');
    }

    public function getOrderStateDetected()
    {
        return (int) Configuration::get('NAKOPAY_OS_DETECTED');
    }
}
