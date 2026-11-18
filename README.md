# Chain2Pay for PrestaShop

Accept card payments in your PrestaShop store. Buyers pay by card through an on-ramp provider on the Chain2Pay checkout, and you receive the payment in crypto in your own wallet.

## Requirements

- PrestaShop 1.7, 8 or 9
- PHP 7.1 or newer with the cURL extension

## Before you start

You need three things:

1. A Chain2Pay account. Create one at https://dashboard.chain2pay.is.
2. A wallet address added and verified in the dashboard. Payments are sent to this wallet.
3. An API key linked to that wallet. Live keys start with `c2p_live_`, test keys start with `c2p_sandbox_`.

Your site must be reachable from the internet over HTTPS. Chain2Pay calls your site to confirm each payment, so a site on `localhost` or behind a password cannot receive confirmations.

## Install

1. Download `Chain2Pay-V1.0.0-PrestaShop.zip` from the [latest release](https://github.com/chain2pay-is/chain2pay-prestashop/releases/latest).
2. In the back office, open **Modules > Module Manager** and click **Upload a module**.
3. Choose the zip file as it is. PrestaShop installs the module and shows a **Configure** button.

## Configure

1. Click **Configure** on the Chain2Pay module.
2. Paste your **Chain2Pay API key**.
3. Under **Providers shown at checkout**, tick the providers you want to offer. **Hosted checkout: all providers (recommended)** is ticked by default.
4. Click **Save**.

Each ticked provider is one payment method at checkout. A provider is hidden for carts it cannot take, for example Stripe for a cart that is not in USD or EUR.

To use the custom provider, tick **Custom provider** and fill in **Custom provider slug**. **Custom provider label** changes its name at checkout.

If you restricted payment methods by currency, customer group, country or carrier, also allow Chain2Pay in **Payment > Preferences**.

## Providers

| Provider | Name shown to the buyer | Indicative minimum (USD or EUR) |
|---|---|---|
| Hosted checkout (all providers) | Card Payment | 6 |
| Stripe | Card (Stripe) | 3, USD and EUR only |
| MoonPay | Pay with MoonPay | 20 |
| Revolut | Pay with Revolut | 6 |
| Coinbase | Pay with Coinbase | 10 |
| PayPal | Pay with PayPal | 5 |
| Banxa | Pay with Banxa | 17 |
| Ramp Network | Pay with Ramp | 7 |
| Robinhood (US only) | Pay with Robinhood | 5 |
| Skrill | Pay with Skrill | 22 |
| Alchemy Pay | Pay with Alchemy Pay | 17 |
| Alchemy Pay (local methods) | Local payment methods | 15 |
| Blockchain.com | Pay with Blockchain.com | 15 |
| BTC Direct | Pay with BTC Direct | 33 |
| Coindisco | Pay by card | 23 |
| Cash App (US only) | Pay with Cash App | 20 |
| Crypto | Pay with Crypto | 6 |
| Custom provider | Pay by Card (Chain2Pay) | 5 |

The hosted checkout is the recommended choice: the buyer picks among the providers available for their country and amount. Minimums and availability change over time; the Chain2Pay API always applies the current rules and the plugin shows its answer to the buyer.

The custom provider sends buyers to any other Chain2Pay provider: type its slug, in lowercase, in the plugin settings.

## How a payment is confirmed

1. The buyer chooses a Chain2Pay method. The plugin creates a payment through the Chain2Pay API and sends the buyer to the checkout page.
2. The buyer pays by card with the provider.
3. Chain2Pay calls your site on a URL that the plugin signed for this order and this amount.
4. The plugin asks the Chain2Pay API whether that payment is really paid, and only then marks the order as paid.

A call with a wrong signature, an unknown payment or a lower amount is refused and the order stays unpaid.

## Order statuses

| Situation | Order status |
|---|---|
| Buyer sent to the checkout | **Awaiting Chain2Pay payment** (created by the module) |
| Payment confirmed | **Payment accepted**, with the transaction reference on the payment line |
| Payment received for an order that is no longer awaiting payment | Unchanged; the Chain2Pay panel of the order shows `paid_order_closed` |
| Payment received after the order total changed | Unchanged; the Chain2Pay panel of the order shows `amount_changed` |

Orders left in **Awaiting Chain2Pay payment** were not paid. Cancel them when you no longer expect the payment.

The module keeps answering Chain2Pay while the shop is in maintenance mode, so payments made during maintenance are still confirmed.

## Troubleshooting

| Symptom | What to check |
|---|---|
| No Chain2Pay method at checkout | The API key is filled in, at least one provider is enabled, and the order currency and amount are accepted by that provider. |
| "Payment could not be started" | The API key is valid and linked to a verified wallet. The exact answer of the API is written in the log named below. |
| The buyer paid but the order is still unpaid | Your site must answer Chain2Pay's call: no maintenance page, no password protection, no firewall rule that blocks the callback URL. Chain2Pay repeats the call, so the order is updated once the site answers. |
| Stripe is not offered | Stripe takes USD and EUR orders only. |
| The order was paid after you cancelled it | The plugin does not reopen a closed order. The payment is noted on the order so that you can refund the buyer or reopen the order yourself. |

Documentation: https://docs.chain2pay.is. Support: support@chain2pay.is.

Log: **Advanced Parameters > Logs** in the back office. Messages start with `Chain2Pay:`.

## Uninstall

Open **Modules > Module Manager**, find Chain2Pay and choose **Uninstall**. The order status and the table of Chain2Pay payments are kept, because existing orders refer to them.

## License

GPL-3.0-or-later. See the license file in this repository.
