<?php
/**
 * Chain2Pay's callback. The signed URL identifies the cart and the amount; the Chain2Pay API
 * then confirms that the payment is paid before the order moves to "Payment accepted".
 *
 * @license GPL-3.0-or-later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Chain2payCallbackModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;
    public $guestAllowed = true;

    /** Callbacks must get through while the shop is in maintenance mode. */
    protected function displayMaintenancePage()
    {
    }

    /** Callbacks come from the Chain2Pay server, whatever countries the shop sells to. */
    protected function displayRestrictedCountryPage()
    {
    }

    public function postProcess()
    {
        $idCart = (int) Tools::getValue('id_cart');
        $amount = (string) Tools::getValue('amt');
        $iso = strtoupper((string) Tools::getValue('cur'));
        $paymentId = trim((string) Tools::getValue('chain2pay_order_id'));

        if ($idCart < 1 || !is_numeric($amount) || $iso === '') {
            $this->answer(400, 'bad request');
        }
        $ref = Chain2PayPsCore::orderRef('prestashop', $idCart, $amount, $iso);
        if (!Chain2PayPsCore::checkToken(Chain2pay::secret(), $ref, (string) Tools::getValue('c2p_token'))) {
            $this->answer(403, 'invalid token');
        }

        $db = Db::getInstance();
        $lock = 'chain2pay_' . substr(md5(_DB_NAME_ . '|' . _DB_PREFIX_), 0, 12) . '_' . $idCart;
        if ((int) $db->getValue("SELECT GET_LOCK('" . pSQL($lock) . "', 20)") !== 1) {
            $this->answer(503, 'busy, retry later');
        }
        try {
            $this->settle($idCart, $amount, $iso, $paymentId, $ref);
        } finally {
            $db->getValue("SELECT RELEASE_LOCK('" . pSQL($lock) . "')");
        }
    }

    private function settle($idCart, $amount, $iso, $paymentId, $ref)
    {
        $idOrder = (int) (method_exists('Order', 'getIdByCartId') ? Order::getIdByCartId($idCart) : Order::getOrderByCartId($idCart));
        $order = $idOrder ? new Order($idOrder) : null;
        if (!$order || !Validate::isLoadedObject($order) || $order->module !== $this->module->name) {
            // The order is created just before the buyer is redirected: Chain2Pay calls again.
            $this->answer(404, 'unknown order');
        }

        // A cart can be split into several orders that share one reference.
        $orders = array();
        foreach (Order::getByReference($order->reference) as $sibling) {
            if ((int) $sibling->id_cart === $idCart) {
                $orders[] = $sibling;
            }
        }
        if (!$orders) {
            $orders = array($order);
        }

        $awaiting = (int) Configuration::getGlobalValue('CHAIN2PAY_OS_AWAITING');
        $allPaid = true;
        foreach ($orders as $one) {
            if (!$one->hasBeenPaid()) {
                $allPaid = false;
            }
        }
        if ($allPaid) {
            $this->answer(200, '*ok*');
        }

        $stored = array();
        $rows = Db::getInstance()->executeS('SELECT `payment_id` FROM `' . _DB_PREFIX_ . Chain2pay::TABLE . '` WHERE `id_cart` = ' . (int) $idCart);
        foreach (is_array($rows) ? $rows : array() as $row) {
            $stored[] = $row['payment_id'];
        }
        if (!$stored) {
            $this->answer(404, 'no payment was created for this cart');
        }

        $check = Chain2PayPsCore::verifyPaid((string) Configuration::get('CHAIN2PAY_API_KEY', null, null, (int) $order->id_shop), $paymentId, $ref, (float) $amount, $iso, $stored, 'PrestaShop/' . $this->module->version);
        if ($check['result'] === Chain2PayPsCore::UNAVAILABLE) {
            PrestaShopLogger::addLog('Chain2Pay: callback for order ' . $idOrder . ' could not be verified yet: ' . $check['reason'], 2, null, 'Order', $idOrder, true);
            $this->answer(503, 'verification unavailable, retry later');
        }
        if ($check['result'] === Chain2PayPsCore::NOT_PAID) {
            $this->answer(409, 'payment not paid');
        }
        if ($check['result'] === Chain2PayPsCore::MISMATCH) {
            PrestaShopLogger::addLog('Chain2Pay: callback for order ' . $idOrder . ' ignored because ' . $check['reason'], 3, null, 'Order', $idOrder, true);
            $this->answer(400, 'payment does not match this order');
        }

        $payment = $check['payment'];
        $summary = Chain2PayPsCore::describe($payment);
        $txid = Chain2PayPsCore::transactionId($payment, $paymentId);

        $total = 0.0;
        $currency = new Currency((int) $order->id_currency);
        foreach ($orders as $one) {
            $total += (float) $one->total_paid_tax_incl;
        }
        if ((float) $amount + 0.01 < $total || strtoupper((string) $currency->iso_code) !== $iso) {
            $this->record($idCart, $paymentId, 'amount_changed', $txid, $amount . ' ' . $iso . ' was paid, but the order total is now ' . Chain2PayPsCore::amount($total) . ' ' . $currency->iso_code . '. Check the order before shipping. ' . $summary);
            $this->answer(200, '*ok*');
        }
        foreach ($orders as $one) {
            if ((int) $one->getCurrentState() !== $awaiting && !$one->hasBeenPaid()) {
                $this->record($idCart, $paymentId, 'paid_order_closed', $txid, 'A payment was received while the order was no longer awaiting payment, so its state was not changed. Refund the buyer if needed. ' . $summary);
                $this->answer(200, '*ok*');
            }
        }

        $paidState = (int) Configuration::get('PS_OS_PAYMENT');
        foreach ($orders as $one) {
            if ($one->hasBeenPaid()) {
                continue;
            }
            $history = new OrderHistory();
            $history->id_order = (int) $one->id;
            $history->changeIdOrderState($paidState, (int) $one->id);
            $history->addWithemail(true);

            try {
                $fresh = new Order((int) $one->id);
                foreach ($fresh->getOrderPaymentCollection() as $orderPayment) {
                    if ((string) $orderPayment->transaction_id === '') {
                        $orderPayment->transaction_id = Tools::substr($txid, 0, 254);
                        $orderPayment->update();
                    }
                }
            } catch (Exception $e) {
                // The order is paid; only the transaction reference could not be written on the payment line.
            }
        }
        $this->record($idCart, $paymentId, 'paid', $txid, $summary);
        $this->answer(200, '*ok*');
    }

    private function record($idCart, $paymentId, $status, $txid, $note)
    {
        Db::getInstance()->update(
            Chain2pay::TABLE,
            array(
                'status' => pSQL($status),
                'tx_hash' => pSQL(Tools::substr($txid, 0, 255)),
                'note' => pSQL(Tools::substr($note, 0, 500)),
                'date_upd' => date('Y-m-d H:i:s'),
            ),
            '`id_cart` = ' . (int) $idCart . " AND `payment_id` = '" . pSQL($paymentId) . "'"
        );
    }

    private function answer($code, $text)
    {
        http_response_code((int) $code);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $text;
        exit;
    }
}
