# Changelog - NakoPay for PrestaShop

## 0.1.0 - initial release
- Native PrestaShop 1.7+ payment module registered via `hookPaymentOptions`.
- Front controllers: `payment` (open invoice + validate order in "Awaiting"),
  `status` (QR + 5s polling + JSON poll endpoint), `webhook` (HMAC receiver).
- HMAC-SHA256 signed webhooks (`X-NakoPay-Signature`, 5-minute replay window).
- Vanilla JS + qrious for the QR (no Angular, no framework UI kit).
- Custom orders table `{prefix}nakopay_orders` (schema-versioned).
- Two custom order states: "Awaiting crypto payment" and "Crypto payment detected".
- Dual base URL strategy: ships the canonical Supabase functions URL and a
  reserved `api.nakopay.com/v1/` fallback constant. Active base resolved from
  admin setting -> PHP constant `NAKOPAY_API_BASE` -> primary.
- Single-coin BTC in v0.1; multi-coin scaffolded for v0.2.
