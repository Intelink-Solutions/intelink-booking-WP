# Stripe Checkout sandbox checklist — Intelink Booking 1.1.2

The plugin uses hosted Stripe Checkout. WooCommerce is not required.

1. Install on HTTPS WordPress staging, activate and create a booking page with `[intelink_booking]`.
2. In Intelink Booking > Settings / Payments, select **test** mode, enable Stripe, enter the test Stripe secret key (`sk_test_...`) and test webhook signing secret (`whsec_...`). The current integration uses the secret key server-side and redirects to hosted Checkout; a publishable key is not required for this path.
3. Configure a public Stripe event destination (account events) at:
   `https://YOUR-DOMAIN/wp-json/intelink-booking/v1/webhook/stripe?mode=test`
   Choose `checkout.session.completed`, `checkout.session.async_payment_succeeded`, and `checkout.session.async_payment_failed`. The last is currently handled through the pending reservation expiry rather than an instant failure notification.
4. Copy the *destination's* signing secret into the plugin's Stripe **test webhook** field. CLI and Dashboard destinations have different signing secrets.
5. Choose a Stripe-supported presentment currency that is configured in Intelink Booking and create a small paid service. Place a booking and select Stripe; confirm the Checkout URL is hosted on `checkout.stripe.com`.
6. Stripe test card: `4242 4242 4242 4242`, any future expiry and any 3-digit CVC. Test a declined card too: `4000 0000 0000 9995`.
7. In Stripe Dashboard > Workbench > Events/Event destinations, confirm webhook delivered a 2xx response. Verify Intelink Booking > Payments has one successful transaction and Appointments has one confirmed booking (unless manual approval is enabled).
8. In Intelink Booking > Notifications inspect one customer confirmation and one admin notification. `sent` means `wp_mail` accepted the message; confirm actual inbox delivery with your SMTP provider/logs. Check spam too.
9. Repeat/resent the same Stripe event and verify there is no duplicate payment, booking, or confirmation email. Test browser close before redirect; the webhook should still settle the payment.
10. Verify a failed/cancelled Checkout does not confirm a booking; after configured reservation timeout the unpaid hold should release. Verify a delayed or late successful payment is flagged for review if the original slot is no longer available.

Never paste `sk_test_`, `sk_live_` or `whsec_` secrets into chat, screenshots, source control, or frontend code. Use the plugin's password fields in wp-admin. Use separate live keys and a live webhook destination before enabling live mode.

Code-level safeguards: verified Stripe v1 signatures (five-minute tolerance), stored Checkout Session matching, client reference and metadata matching, server-side mode, amount, currency and payment-status checks, and payment-event deduplication. Sandbox transactions, webhook delivery and external email inbox delivery still require your own credentials and accessible staging site.
