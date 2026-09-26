# Changing Intelink Booking currency safely

In WordPress admin, go to Intelink Booking > Settings > Business. Choose USD (or type a supported three-letter ISO currency code), tick the repricing acknowledgement, then Save settings.

**Important:** This is a denomination change, NOT a foreign exchange conversion. A service previously priced at 100 NGN becomes 100 USD. The plugin re-encodes its stored minor units; review all service and sale prices immediately. Past appointments and payment records retain their saved currency and amount. Revenue reports display separate currency totals rather than summing incompatible monetary units.

Changing currency does not change an existing Stripe Checkout Session or outstanding payment. Existing reservations should be paid/refunded in their original currency. Test the public booking form in a fresh browser session; clear any page/CDN cache after changing settings. Test email notifications through your configured SMTP provider and the test email button in Intelink Booking > Notifications.

For Stripe sandbox, configure a valid Stripe test secret and webhook signing secret in Intelink Booking > Integrations, select test mode, enable Stripe under Payments, and verify an actual test payment before enabling production payments. Never paste secret keys into chat.
