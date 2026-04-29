# Changelog - NakoPay for PrestaShop

## 0.1.0 - initial release
- Reverse-engineered from the Blockonomics PrestaShop module (same UX) but
  every internal rewired to the NakoPay API.
- `PaymentModule` registered via `hookPaymentOptions` (PS 1.7+ payment API).
- Front controllers: `payment` (open invoice + validate order in "Awaiting"),
  `status` (QR + 5s polling + JSON poll endpoint), `webhook` (HMAC receiver).
- HMAC-SHA256 webhook signature in place of Blockonomics' `?secret=` query param.
- Vanilla JS + qrious for the QR - Angular dropped (~250 KB lighter, faster TTI).
- Custom orders table `{prefix}nakopay_orders` (schema-versioned).
- Two custom order states: "Awaiting crypto payment" and "Crypto payment detected".
- Dual base URL strategy: ships the canonical Supabase functions URL and a
  reserved `api.nakopay.com/v1/` fallback constant. Active base resolved from
  admin setting → PHP constant `NAKOPAY_API_BASE` → primary.
- Single-coin BTC in v0.1; multi-coin scaffolded for v0.2.
