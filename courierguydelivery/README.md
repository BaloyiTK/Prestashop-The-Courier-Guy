# The Courier Guy Delivery for PrestaShop

Version **1.0.0** — developed by **Kaziara Solutions**.

## Checkout design

The module creates one **The Courier Guy** delivery method. After the customer selects it, a service dropdown appears.

### South Africa

- PUDO Locker-to-Locker — R60 — 1–3 business days
- Economy Road Freight — R95 — 2–4 business days
- PUDO Locker-to-Door — R99 — 1–3 business days
- Overnight Express — R125 — next business day by 17:00
- Same-Day Metro — R140 — same day when booked before 11:00 (availability dependent)

### International addresses

- International Express — R450 — 3–7 business days

For **PUDO Locker-to-Locker**, the destination PUDO locker is required before checkout can continue to payment. Other services use the customer's normal PrestaShop delivery address.

## Important pricing note

The prices above are configured as fixed base/starting rates supplied for this module version. Real courier charges can vary by parcel size, weight, route, surcharges, and current Courier Guy pricing. This version does not call a live Courier Guy API.

## Compatibility

PrestaShop 8.x. The module uses standard carrier-module hooks and server-side delivery-step validation.

## Disclaimer

This is an independent integration and is not an official module of The Courier Guy or PUDO. Brand names and logos belong to their respective owners.
