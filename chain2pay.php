<?php
/**
 * Chain2Pay payment module for PrestaShop 1.7, 8 and 9.
 *
 * Buyers pay by card through an on-ramp provider on the Chain2Pay checkout,
 * and the merchant receives the payment in crypto in their own wallet.
 *
 * @see https://chain2pay.is
 * @see https://docs.chain2pay.is
 *
 * @license GPL-3.0-or-later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/Chain2PayPsCore.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class Chain2pay extends PaymentModule
{
    const TABLE = 'chain2pay_payment';

    public function __construct()
    {
        $this->name = 'chain2pay';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Chain2Pay';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->controllers = array('validation', 'callback');
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = $this->l('Chain2Pay');
        $this->description = $this->l('Accept card payments through Chain2Pay: buyers pay by card through an on-ramp provider and you receive the payment in crypto in your own wallet.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall Chain2Pay?');

        if (!Configuration::get('CHAIN2PAY_API_KEY')) {
            $this->warning = $this->l('Enter your Chain2Pay API key to start accepting payments.');
        }
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayAdminOrderMainBottom')
            && $this->installDb()
            && $this->installOrderState()
            && $this->installConfiguration();
    }

    public function uninstall()
    {
        // The payments table and the order state are kept: existing orders still refer to them.
        foreach (array('CHAIN2PAY_API_KEY', 'CHAIN2PAY_PROVIDERS', 'CHAIN2PAY_CUSTOM_SLUG', 'CHAIN2PAY_CUSTOM_LABEL') as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    private function installDb()
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
                `id_chain2pay_payment` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_cart` INT(11) UNSIGNED NOT NULL,
                `id_order` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `payment_id` VARCHAR(100) NOT NULL,
                `provider` VARCHAR(40) NOT NULL,
                `amount` DECIMAL(20,2) NOT NULL DEFAULT 0,
                `currency` VARCHAR(3) NOT NULL,
                `status` VARCHAR(30) NOT NULL DEFAULT \'pending\',
                `tx_hash` VARCHAR(255) DEFAULT NULL,
                `note` VARCHAR(500) DEFAULT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_chain2pay_payment`),
                KEY `id_cart` (`id_cart`),
                KEY `id_order` (`id_order`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4'
        );
    }

    /** Orders wait in their own "Awaiting Chain2Pay payment" state until the payment is confirmed. */
    public function installOrderState()
    {
        $idState = (int) Configuration::getGlobalValue('CHAIN2PAY_OS_AWAITING');
        if ($idState && Validate::isLoadedObject(new OrderState($idState))) {
            return true;
        }
        $state = new OrderState();
        $state->name = array();
        foreach (Language::getLanguages(false) as $language) {
            $state->name[(int) $language['id_lang']] = 'Awaiting Chain2Pay payment';
        }
        $state->module_name = $this->name;
        $state->color = '#0066FF';
        $state->send_email = false;
        $state->logable = false;
        $state->invoice = false;
        $state->hidden = false;
        $state->unremovable = true;
        $state->delivery = false;
        $state->shipped = false;
        $state->paid = false;
        if (!$state->add()) {
            return false;
        }

        return Configuration::updateGlobalValue('CHAIN2PAY_OS_AWAITING', (int) $state->id);
    }

    private function installConfiguration()
    {
        if (!Configuration::getGlobalValue('CHAIN2PAY_SECRET')) {
            Configuration::updateGlobalValue('CHAIN2PAY_SECRET', bin2hex(random_bytes(32)));
        }
        if (Configuration::get('CHAIN2PAY_PROVIDERS') === false) {
            Configuration::updateValue('CHAIN2PAY_PROVIDERS', 'multihosted');
        }

        return true;
    }

    /** Secret that signs callback URLs. Created on install, never shown. */
    public static function secret()
    {
        $secret = (string) Configuration::getGlobalValue('CHAIN2PAY_SECRET');
        if (strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(32));
            Configuration::updateGlobalValue('CHAIN2PAY_SECRET', $secret);
        }

        return $secret;
    }

    /** Ids of the providers the merchant enabled, in the order of the provider list. */
    public static function enabledProviders()
    {
        $saved = array_filter(array_map('trim', explode(',', (string) Configuration::get('CHAIN2PAY_PROVIDERS'))));
        $enabled = array();
        foreach (array_keys(Chain2PayPsCore::providers()) as $id) {
            if (in_array($id, $saved, true)) {
                $enabled[] = $id;
            }
        }

        return $enabled;
    }

    /** Name of a provider at checkout. */
    public static function providerLabel($id)
    {
        $providers = Chain2PayPsCore::providers();
        if (!isset($providers[$id])) {
            return 'Chain2Pay';
        }
        if ($id === 'custom' && trim((string) Configuration::get('CHAIN2PAY_CUSTOM_LABEL')) !== '') {
            return trim((string) Configuration::get('CHAIN2PAY_CUSTOM_LABEL'));
        }

        return $providers[$id]['label'];
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitChain2pay')) {
            $apiKey = trim((string) Tools::getValue('CHAIN2PAY_API_KEY'));
            $enabled = array();
            foreach (array_keys(Chain2PayPsCore::providers()) as $id) {
                if (Tools::getValue('CHAIN2PAY_PROVIDER_' . $id)) {
                    $enabled[] = $id;
                }
            }
            $customSlug = strtolower(trim((string) Tools::getValue('CHAIN2PAY_CUSTOM_SLUG')));
            Configuration::updateValue('CHAIN2PAY_API_KEY', $apiKey);
            Configuration::updateValue('CHAIN2PAY_PROVIDERS', implode(',', $enabled));
            Configuration::updateValue('CHAIN2PAY_CUSTOM_SLUG', $customSlug);
            Configuration::updateValue('CHAIN2PAY_CUSTOM_LABEL', trim((string) Tools::getValue('CHAIN2PAY_CUSTOM_LABEL')));

            if ($apiKey === '') {
                $output .= $this->displayError($this->l('Enter your Chain2Pay API key: without it no payment method is shown at checkout.'));
            } elseif (strpos($apiKey, 'c2p_') !== 0) {
                $output .= $this->displayWarning($this->l('This does not look like a Chain2Pay API key. Keys start with c2p_live_ or c2p_sandbox_.'));
            }
            if (!$enabled) {
                $output .= $this->displayError($this->l('Tick at least one provider: without it no payment method is shown at checkout.'));
            }
            if (in_array('custom', $enabled, true) && Chain2PayPsCore::providerSlug('custom', $customSlug) === '') {
                $output .= $this->displayError($this->l('The custom provider is ticked but its slug is empty or invalid.'));
            }
            $output .= $this->displayConfirmation($this->l('Settings updated'));
        }

        return $output . $this->renderForm();
    }

    private function renderForm()
    {
        $providerRows = array();
        foreach (Chain2PayPsCore::providerChoices() as $id => $name) {
            $providerRows[] = array('id' => $id, 'name' => $name, 'val' => 1);
        }
        $form = array(
            'form' => array(
                'legend' => array('title' => $this->l('Chain2Pay settings'), 'icon' => 'icon-cogs'),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Chain2Pay API key'),
                        'name' => 'CHAIN2PAY_API_KEY',
                        'required' => true,
                        'desc' => $this->l('Your key from the Chain2Pay dashboard (c2p_live_... or, for tests, c2p_sandbox_...).'),
                    ),
                    array(
                        'type' => 'checkbox',
                        'label' => $this->l('Providers shown at checkout'),
                        'name' => 'CHAIN2PAY_PROVIDER',
                        'desc' => $this->l('Each ticked provider is one payment method at checkout. The hosted checkout lets the buyer choose among every provider available for their country and amount.'),
                        'values' => array('query' => $providerRows, 'id' => 'id', 'name' => 'name'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Custom provider slug'),
                        'name' => 'CHAIN2PAY_CUSTOM_SLUG',
                        'desc' => $this->l('Only used by the "Custom provider" method: the Chain2Pay provider slug, in lowercase.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Custom provider label'),
                        'name' => 'CHAIN2PAY_CUSTOM_LABEL',
                        'desc' => $this->l('Name of the "Custom provider" method at checkout.'),
                    ),
                ),
                'submit' => array('title' => $this->l('Save'), 'name' => 'submitChain2pay'),
            ),
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->show_toolbar = false;
        $helper->submit_action = 'submitChain2pay';

        $enabled = self::enabledProviders();
        $helper->fields_value['CHAIN2PAY_API_KEY'] = (string) Configuration::get('CHAIN2PAY_API_KEY');
        $helper->fields_value['CHAIN2PAY_CUSTOM_SLUG'] = (string) Configuration::get('CHAIN2PAY_CUSTOM_SLUG');
        $helper->fields_value['CHAIN2PAY_CUSTOM_LABEL'] = (string) Configuration::get('CHAIN2PAY_CUSTOM_LABEL');
        foreach (array_keys(Chain2PayPsCore::providers()) as $id) {
            $helper->fields_value['CHAIN2PAY_PROVIDER_' . $id] = in_array($id, $enabled, true) ? 1 : 0;
        }

        return $helper->generateForm(array($form));
    }

    /** True when the cart currency is one of the currencies enabled for this module. */
    public function checkCurrency($cart)
    {
        $cartCurrency = new Currency((int) $cart->id_currency);
        $moduleCurrencies = $this->getCurrency((int) $cart->id_currency);
        if (is_array($moduleCurrencies)) {
            foreach ($moduleCurrencies as $currency) {
                if ((int) $cartCurrency->id === (int) $currency['id_currency']) {
                    return true;
                }
            }
        }

        return false;
    }

    /** One payment option per enabled provider that can take this cart (currency and minimum amount). */
    public function hookPaymentOptions($params)
    {
        if (!$this->active || trim((string) Configuration::get('CHAIN2PAY_API_KEY')) === '') {
            return array();
        }
        $cart = isset($params['cart']) ? $params['cart'] : $this->context->cart;
        if (!Validate::isLoadedObject($cart) || !$cart->nbProducts() || !$this->checkCurrency($cart)) {
            return array();
        }
        $currency = new Currency((int) $cart->id_currency);
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

        $options = array();
        $providers = Chain2PayPsCore::providers();
        foreach (self::enabledProviders() as $id) {
            if (Chain2PayPsCore::providerSlug($id, (string) Configuration::get('CHAIN2PAY_CUSTOM_SLUG')) === ''
                || Chain2PayPsCore::precheck($id, $total, $currency->iso_code) !== '') {
                continue;
            }
            $option = new PaymentOption();
            $option->setModuleName($this->name)
                ->setCallToActionText(self::providerLabel($id))
                ->setAction($this->context->link->getModuleLink($this->name, 'validation', array('provider' => $id), true))
                ->setAdditionalInformation('<p>' . Tools::safeOutput($providers[$id]['description']) . '. ' . Tools::safeOutput($this->l('You will complete the payment on the secure Chain2Pay checkout page.')) . '</p>');
            $options[] = $option;
        }

        return $options;
    }

    /** Back office, order page: the Chain2Pay payments created for this order. */
    public function hookDisplayAdminOrderMainBottom($params)
    {
        $idOrder = isset($params['id_order']) ? (int) $params['id_order'] : 0;
        if (!$idOrder) {
            return '';
        }
        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }
        $rows = Db::getInstance()->executeS(
            'SELECT `payment_id`, `provider`, `amount`, `currency`, `status`, `tx_hash`, `note`, `date_add`, `date_upd`
             FROM `' . _DB_PREFIX_ . self::TABLE . '`
             WHERE `id_cart` = ' . (int) $order->id_cart . '
             ORDER BY `id_chain2pay_payment` ASC'
        );
        $this->context->smarty->assign(array('chain2pay_rows' => is_array($rows) ? $rows : array()));

        return $this->display(__FILE__, 'views/templates/hook/admin_order.tpl');
    }
}
