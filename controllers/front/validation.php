<?php
/**
 * Checkout: creates the Chain2Pay payment, places the order in the "Awaiting Chain2Pay
 * payment" state and sends the buyer to the Chain2Pay checkout.
 *
 * @license GPL-3.0-or-later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Chain2payValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $cart = $this->context->cart;
        if (!$this->module->active || !Validate::isLoadedObject($cart) || !$cart->id_customer || !$cart->id_address_delivery || !$cart->id_address_invoice) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // The buyer may have changed address or currency since the payment step was displayed.
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === $this->module->name) {
                $authorized = true;
                break;
            }
        }
        $customer = new Customer((int) $cart->id_customer);
        if (!$authorized || !Validate::isLoadedObject($customer) || !$this->module->checkCurrency($cart)) {
            $this->backToPayment($this->module->l('This payment method is not available.', 'validation'));
        }
        if ($cart->orderExists()) {
            Tools::redirect($this->context->link->getPageLink('history', true));
        }

        $provider = (string) Tools::getValue('provider');
        $apiKey = trim((string) Configuration::get('CHAIN2PAY_API_KEY'));
        $slug = in_array($provider, Chain2pay::enabledProviders(), true)
            ? Chain2PayPsCore::providerSlug($provider, (string) Configuration::get('CHAIN2PAY_CUSTOM_SLUG'))
            : '';
        if ($apiKey === '' || $slug === '') {
            $this->backToPayment($this->module->l('This payment method is not available.', 'validation'));
        }

        $currency = new Currency((int) $cart->id_currency);
        $iso = strtoupper((string) $currency->iso_code);
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $problem = Chain2PayPsCore::precheck($provider, $total, $iso);
        if ($problem !== '') {
            $this->backToPayment($problem);
        }

        $idCart = (int) $cart->id;
        $ref = Chain2PayPsCore::orderRef('prestashop', $idCart, $total, $iso);
        $callback = $this->context->link->getModuleLink(
            $this->module->name,
            'callback',
            array(
                'id_cart' => $idCart,
                'amt' => Chain2PayPsCore::amount($total),
                'cur' => $iso,
                'c2p_token' => Chain2PayPsCore::token(Chain2pay::secret(), $ref),
            ),
            true
        );

        $created = Chain2PayPsCore::createPayment(
            $apiKey,
            $total,
            $iso,
            $slug,
            (string) $customer->email,
            $callback,
            array('order_ref' => $ref, 'platform' => 'prestashop', 'order_id' => (string) $idCart),
            'PrestaShop/' . $this->module->version
        );
        if (empty($created['success'])) {
            PrestaShopLogger::addLog('Chain2Pay: payment for cart ' . $idCart . ' could not be started. ' . $created['error'], 3, null, 'Cart', $idCart, true);
            $this->backToPayment($this->module->l('Payment could not be started. Please try again in a moment or choose another payment method.', 'validation'));
        }

        $now = date('Y-m-d H:i:s');
        Db::getInstance()->insert(Chain2pay::TABLE, array(
            'id_cart' => $idCart,
            'id_order' => 0,
            'payment_id' => pSQL($created['payment_id']),
            'provider' => pSQL($slug),
            'amount' => (float) Chain2PayPsCore::amount($total),
            'currency' => pSQL($iso),
            'status' => 'pending',
            'date_add' => $now,
            'date_upd' => $now,
        ));

        try {
            $this->module->validateOrder(
                $idCart,
                (int) Configuration::getGlobalValue('CHAIN2PAY_OS_AWAITING'),
                $total,
                Chain2pay::providerLabel($provider) . ' (Chain2Pay)',
                null,
                array(),
                (int) $currency->id,
                false,
                $customer->secure_key
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Chain2Pay: order for cart ' . $idCart . ' could not be created. ' . $e->getMessage(), 3, null, 'Cart', $idCart, true);
            $this->backToPayment($this->module->l('Your order could not be created. Please try again.', 'validation'));
        }

        Db::getInstance()->update(
            Chain2pay::TABLE,
            array('id_order' => (int) $this->module->currentOrder, 'date_upd' => date('Y-m-d H:i:s')),
            '`id_cart` = ' . $idCart
        );

        Tools::redirect($created['checkout_url']);
    }

    /** Sends the buyer back to the payment step with a message. */
    private function backToPayment($message)
    {
        $this->errors[] = $message;
        $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, array('step' => 3)));
    }
}
