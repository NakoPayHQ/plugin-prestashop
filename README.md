# NakoPay for PrestaShop

Accept Bitcoin and other crypto in PrestaShop with a one-flat-fee, non-custodial
checkout. Wallet-to-wallet - NakoPay never holds your funds.

## Requirements

- PrestaShop 1.7.x or 8.x
- PHP 7.4+
- A NakoPay account (free) - <https://nakopay.com/dashboard/api-keys>

## Download

| # | Source | When to use |
|---|--------|-------------|
| 1 | **PrestaShop Addons Marketplace** - <https://addons.prestashop.com/en/...nakopay> | *Listing pending review - use option 2 in the meantime.* |
| 2 | **GitHub Releases zip** - <https://github.com/NakoPayHQ/plugin-prestashop/releases/latest/download/nakopay.zip> | Available today. Download `nakopay.zip` (already structured for PrestaShop's uploader). |
| 3 | **Build from source** | See bottom of this file. |

## Install

You only need **one** of the methods below. Method A is what most users do.

### Method A - Upload via PrestaShop admin (recommended)

1. Download `nakopay.zip` (do **not** unzip).
2. Log in to your PrestaShop back office.
3. Go to **Modules -> Module Manager**.
4. Click **Upload a module** (top right).
5. Drag and drop `nakopay.zip` into the dialog, or click **select file**.
6. PrestaShop installs and offers to **Configure** it - click that.

### Method B - SFTP / cPanel File Manager

1. Unzip on your computer - you get a folder called `nakopay/`.
2. Upload the **whole folder** to `<prestashop-root>/modules/` so the final path is:
   ```
   modules/nakopay/nakopay.php
   ```
3. In PrestaShop back office, **Modules -> Module Manager**, search "NakoPay", click **Install**.

## Configure

1. Get an API key: <https://nakopay.com/dashboard/api-keys>.
2. **Modules -> Module Manager -> NakoPay -> Configure**.
3. Paste your **API key** (`sk_test_...` or `sk_live_...`).
4. Copy the read-only **Webhook URL** field shown by the module.
5. In your NakoPay dashboard, **Settings -> Webhooks -> Add endpoint**, paste that URL, subscribe to `invoice.paid`, `invoice.completed`, `invoice.expired`, `invoice.cancelled`.
6. NakoPay shows a **signing secret** once - copy it back into the module's **Webhook secret** field.
7. Tick **Enable test mode** if you're using `sk_test_*` keys.
8. Save.

## Verify

- Open your storefront, add anything to the cart, go to checkout.
- **Pay with NakoPay (Bitcoin)** should appear as a payment option.
- Place a test order with `sk_test_*` keys - QR + address appears. Pay with Bitcoin testnet, the order should flip to "Payment accepted" within ~10 seconds.

## Test mode

Use `sk_test_*` keys to run the full checkout against the NakoPay sandbox. Flip the **Test mode** switch off and use `sk_live_*` for production.

## Uninstall

1. **Modules -> Module Manager -> NakoPay -> Disable**.
2. Click **Uninstall** to remove it (this also drops the `{prefix}nakopay_orders` table).
3. Optional: click **Delete** to remove files from disk.

## Architecture

- `nakopay.php` - `PaymentModule` (install, settings, hooks).
- `classes/NakoPayClient.php` - HTTP client with the dual-base-URL strategy.
- `classes/NakoPayOrders.php` - `{prefix}nakopay_orders` table wrapper.
- `controllers/front/payment.php` - opens the invoice, validates a PS order in the "Awaiting" state.
- `controllers/front/status.php` - renders the QR + polling page; doubles as a JSON poll endpoint (`?poll=1`).
- `controllers/front/webhook.php` - HMAC-SHA256 receiver (source of truth).
- `views/js/checkout.js` - vanilla JS, no Angular. QR via `qrious`.

## Design choices

| Concern | NakoPay |
|---|---|
| Webhook auth | `X-NakoPay-Signature` HMAC-SHA256 with 5-minute replay window |
| Front JS | Vanilla JS + `qrious` (~10 KB), no Angular / jQuery / framework UI kit |
| Polling | 5s status polling, JSON poll endpoint at `?poll=1` |
| Multi-coin UI | Single-coin BTC in v0.1, scaffolded for v0.2 |
| API base | Dual-base-URL with admin override + `NAKOPAY_API_BASE` constant fallback |

## Build from source

```bash
git clone https://github.com/NakoPayHQ/plugin-prestashop.git
cd plugin-prestashop
# PrestaShop expects the zip's top-level folder to be the module name
mkdir -p /tmp/build && cp -r . /tmp/build/nakopay
cd /tmp/build && zip -r nakopay.zip nakopay -x "*.git*" "*.DS_Store"
```

## Verify before shipping

```
bash ../scripts/check-no-internal-urls.sh ../prestashop
```

## Support

- Issues: <https://github.com/NakoPayHQ/plugin-prestashop/issues>
- Email: support@nakopay.com

## License

MIT - see [`../LICENSE`](../LICENSE).
