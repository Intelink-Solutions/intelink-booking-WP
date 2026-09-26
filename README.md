# Intelink Booking

Native WordPress appointment booking plugin by **Intelink Solutions**. Version 1.0.0.

## Installation

1. In WordPress, open **Plugins → Add New → Upload Plugin**.
2. Select `intelink-booking-1.0.0.zip`, install, and activate it.
3. Open **Intelink Booking → Settings**. Set the business timezone and currency before creating services, then configure contact details and booking rules.
4. Configure business working hours under **Availability**. Activation creates Monday–Friday, 09:00–17:00 hours; change these to match the business.
5. Create categories, staff, and active services. No demonstration services are installed on client sites.
6. Enable at least one payment method in **Payments → Payment settings**. Pay at Appointment is enabled initially.
7. Add a WordPress page containing `[intelink_booking]`. A Shortcode block works in the block editor. No theme edits or page-builder dependency are needed.
8. Configure the website Privacy Policy under WordPress Settings and test a booking before sharing the page.

Requirements: WordPress 6.5+, PHP 8.1+ with OpenSSL and Fileinfo, MySQL/MariaDB with InnoDB transactions and `GET_LOCK`, and a modern browser. Public online checkout requires HTTPS. WooCommerce is optional. Database operations must use the same primary database connection; route this plugin's tables away from read replicas if using a database-routing plugin.

## Customer interface

The four-step form uses the supplied layout: left progress sidebar, website branding, right content, horizontal service cards, search/category filters, calendar, information form, review, and bottom navigation. Container queries adapt it to both full-width and constrained theme layouts. There is no separate summary panel.

Branding priority is booking logo override → WordPress custom logo → site icon → website name. Configure support phone, email, support hours, primary color, and accent color in Settings. Featured images are selected from the WordPress Media Library; services without an image use a neutral graphic.

Shortcodes:

```text
[intelink_booking]
[intelink_booking service="15"]
[intelink_booking category="consultation"]
[intelink_booking services="15,18,22"]
```

Multiple instances are isolated and have no shared DOM IDs. Enable multiple-service bookings in Settings when needed. Shortcode filters control the displayed catalog; they are not an authorization boundary.

The confirmation download is a text document. Print Confirmation supports the browser's print/save-to-PDF function. Management links use a high-entropy capability token, with cancellation and rescheduling deadlines checked on the server. Anyone holding a complete management link can manage that appointment; do not publish these links.

## Services, staff, and availability

Services support regular and sale prices, durations in minutes, preparation/cleanup buffers, capacity, category, images, staff assignments, payment requirements, and fixed/percentage deposits. Only active services are public.

Service and staff editors include weekly schedule overrides. Check **Apply these schedule changes when saving** to change a profile's schedule; normal profile edits preserve advanced schedules. The profile editor applies the same hours to selected weekdays. Use Availability for different hours by weekday or multiple periods.

Availability records use Business, Service, or Staff scope. Owner ID is 0 for Business, or the relevant record ID. Weekdays are Monday=1 through Sunday=7. Breaks are JSON time pairs, for example:

```json
[["12:00","13:00"],["15:00","15:15"]]
```

Special working dates override the weekly rows for that date. Holidays and staff days off belong under **Availability → Holidays & blocked dates**. A blocked date wins over special hours. Service and staff hours intersect with business hours. Opening periods must be within one day; split overnight working hours into separate calendar days.

Multiple services form one combined appointment with one staff member eligible for all assigned services. The combined duration and the largest before/after buffers must fit inside availability. Separate staff handoffs within one appointment are not modeled.

Capacity uses overlapping occupied intervals, including buffers. Adjacent appointments do not count as overlapping. Temporary reservations consume capacity until expiry. A database transaction and site-specific MySQL advisory lock serialize final reservation checks, rescheduling, and payment reconciliation.

Booking notice is in minutes. Cancellation, rescheduling, and reminder settings are in hours. Maximum advance booking is in days. Appointment times are stored as UTC and displayed in the configured business timezone.

## Independent payments

The plugin uses its own payment ledger. Enable any applicable method:

- Paystack Direct: hosted transaction checkout and server verification.
- Flutterwave Direct: v3 hosted checkout and transaction verification.
- Stripe Direct: hosted Checkout Sessions and server verification.
- WooCommerce: optional order/pay-for-order integration.
- Manual Bank Transfer: displays account instructions and awaits administrator receipt confirmation.
- Pay at Appointment: confirms or awaits approval while remaining unpaid.
- Free Booking: automatically records a zero-value successful payment without a gateway.

Enter separate test and live credentials under Payments settings or Integrations. Blank secret fields retain existing credentials. Each transaction retains its test/live mode. Keys stay in server-side WordPress options and are never included in the public catalog or JavaScript. Protect database backups as you would other WordPress secrets.

For deposits, select Deposit under Payment Mode and configure each service's deposit type/value. Amounts, discounts, tax, and the amount payable now are calculated server-side. Currency cannot be changed after booking/price data exists, to prevent reinterpreting historical money values.

### Webhooks

Copy the displayed webhook URL for the selected mode into the provider dashboard. On sites with pretty permalinks the URL has this form:

```text
https://example.com/wp-json/intelink-booking/v1/webhook/paystack?mode=test
https://example.com/wp-json/intelink-booking/v1/webhook/flutterwave?mode=test
https://example.com/wp-json/intelink-booking/v1/webhook/stripe?mode=test
```

Plain WordPress permalinks are also supported; use the exact URL displayed in Settings rather than reconstructing it.

- Paystack validates `x-paystack-signature` with the transaction mode's secret key.
- Flutterwave v3 validates the configured secret hash from `verif-hash`; HMAC delivery using `flutterwave-signature` is also accepted. Configure the same secret hash in both dashboards.
- Stripe validates `Stripe-Signature`, including its timestamp, using the webhook signing secret. Subscribe to Checkout Session completion, asynchronous success/failure, and expiry events.

Redirects and browser payment messages never mark a booking paid. The server checks the transaction reference, amount, currency, and verified success. Repeated callbacks are idempotent. A late payment is recorded even if its slot was lost, but the cancelled booking is not falsely confirmed; administrator review is required.

Bank-transfer reservations expire using the configured reservation interval. Set an interval appropriate for manual reconciliation. Paid appointments awaiting manual approval do not expire automatically.

### Refunds and reconciliation

Use the payment table's Refund action. For bank/pay-at-appointment entries, return funds outside the plugin first; the action records that return. Online refunds call the selected provider when supported. Pending provider refunds can be verified from the Payments page. Flutterwave's plain `completed` refund state is treated as pending disbursement; final disbursement states are required before updating the refunded ledger.

A durable refund intent is saved before contacting a gateway. An ambiguous network result blocks a second refund request. Check the provider dashboard and enter its refund ID to reconcile it. If the provider confirms that no refund was created, an administrator/developer must review and clear the uncertain intent before retrying; it is intentionally not retried blindly.

WooCommerce orders contain a fee item for the amount due now and carry the booking transaction reference. Its gateway processes the order normally. Payment completion/paid order status is verified against the order total and currency. WooCommerce refunds are reconciled into the booking ledger. Configure WooCommerce gateways and checkout pages separately. Do not delete linked orders while bookings are retained.

## Administration

All 14 menu pages are functional: Dashboard, Appointments, Calendar, Services, Service Categories, Staff, Customers, Availability, Payments, Coupons, Notifications, Reports, Settings, and Integrations.

The administrator role receives `manage_intelink_booking`. Grant this capability only to trusted booking managers; it permits access to customer information and payment administration. Admin forms use WordPress nonces; private REST endpoints check the capability.

Appointment details support contact/note editing, confirmation, rescheduling, cancellation, completion, and no-show marking. Rescheduling rechecks capacity. Closed appointments require a new booking for a new reservation. History records retain timestamps and acting user IDs. Customer master records are not overwritten by unauthenticated repeat bookings; each appointment preserves its submitted customer snapshot.

Reports calculate appointment trends, status counts, popular services, and net payment revenue from stored records. Revenue includes verified gateway payments and administrator-recorded offline receipts, less recorded refunds. Report dates use the business timezone. List screens show up to 500 recent records; appointment reference search narrows the list. Reports calculate the full selected range.

## Custom fields and uploads

Open **Settings → Custom booking fields**. Supported types are text, email, phone, number, textarea, dropdown, radio, checkbox, date, and file. Dropdown/radio options use one line per option. Fields support required status, ordering, default values, placeholders, and enable/disable controls.

Validation rules use JSON with supported keys such as:

```json
{"min":18,"max":120,"maxlength":100}
```

Numeric limits apply to number fields; maxlength applies to text length. Required checkboxes must be checked. Arbitrary administrator-provided regular expressions are not executed.

Uploads accept PDF, JPEG, PNG, and text up to 2 MB. MIME type is checked server-side. They are initially held in expiring private database transients, then copied into the appointment's private metadata. They are never written into public uploads. Authorized administrators download them from appointment details. Include these private records in the business's retention policy and backup plan.

## Email and scheduled work

Notifications provides editable customer/admin templates for confirmation, cancellation, rescheduling, payment failure, late-payment review, and reminders. Configure sender details in Settings and optional recipient overrides per template. The editor supports preview and a test email to the signed-in administrator.

Emails use `wp_mail`, so SMTP plugins continue to work. A transactional notification outbox has unique event keys and atomic worker claims. Confirmations are queued within the booking transaction and processed after the request, with cron retries for failed sends. Logs distinguish queued, sending, sent, skipped, and failed. Sent means WordPress's mail transport accepted the message, not proof of delivery to an inbox. Interrupted sending jobs require review before retrying to avoid duplicates.

Supported variables:

```text
{{customer_name}} {{customer_email}} {{customer_phone}}
{{booking_reference}} {{service_name}} {{appointment_date}}
{{appointment_time}} {{appointment_duration}} {{appointment_location}}
{{payment_amount}} {{payment_method}} {{payment_status}} {{booking_status}}
{{cancel_booking_url}} {{reschedule_booking_url}}
{{business_name}} {{business_logo}}
```

`business_logo` is the image URL, suitable for a template's image `src` attribute. Date/time variables include the business timezone.

Maintenance is scheduled every minute with WP-Cron. Configure a real server cron to run WordPress scheduled events regularly on low-traffic sites. Availability checks ignore expired holds immediately even before cleanup runs. Exclude booking-management responses from full-page caches. Guest catalog/forms do not depend on a cached REST nonce; logged-in sessions still require a valid WordPress REST nonce.

## Source layout

- `intelink-booking.php`: plugin boot, activation, scheduled hooks.
- `includes/store.php`: prefixed tables, migration, database transactions, settings defaults.
- `includes/schedule.php`: calendar windows, buffers, capacity, staff assignment.
- `includes/booking.php`: validation, pricing, reservations, management capability tokens.
- `includes/providers.php`: provider contract and separate gateway classes.
- `includes/payments.php`: payment lifecycle, verification, webhook idempotency, refunds, WooCommerce hooks.
- `includes/notifications.php`: templates, event outbox, delivery worker.
- `includes/api.php`: public/private REST routes.
- `includes/admin.php`: schema-based administration screens, CRUD, analytics.
- `includes/frontend.php`, `assets/`: shortcode, responsive form, confirmation downloads.
- `tests/`: real WordPress integration, concurrent database, provider contract, WooCommerce, and browser tests.

All tables use the configured WordPress prefix. Database migrations use `dbDelta`; the installed version is tracked in an option. Deactivation removes the scheduled maintenance event and retains data. Uninstall also retains data deliberately; export/backup before manually removing plugin tables and options. Network-wide multisite activation is not implemented; activate and configure per site.

## Validation and release status

See `TESTING.md` for commands and `TEST-RESULTS.md` for the tested environment and results. Local WordPress, database, browser, and WooCommerce order tests have been performed. External gateway HTTP tests use controlled responses. **No actual Paystack, Flutterwave, or Stripe sandbox transaction or live transaction has been performed, and inbox delivery has not been verified.** Complete the provider-specific sandbox checklist on an HTTPS staging site before accepting customer payments.

Provider references used during implementation:

- [Paystack verification](https://paystack.com/docs/payments/verify-payments/) and [webhooks](https://paystack.com/docs/payments/webhooks/).
- [Flutterwave v3 webhooks](https://developer.flutterwave.com/docs/webhooks), [verification](https://developer.flutterwave.com/docs/transaction-verification), and [refund states](https://developer.flutterwave.com/docs/refunds).
- [Stripe Checkout Sessions](https://docs.stripe.com/api/checkout/sessions) and [webhook signatures](https://docs.stripe.com/webhooks/signatures).

License: GPL-2.0-or-later.


## Payment integration update (1.1.0)

The existing standalone booking/ledger design and optional WooCommerce order-payment adapter are retained. This release additionally constrains hosted checkout redirects to verified HTTPS payment-provider hosts (or the site's own WooCommerce pay page), checks Paystack's transaction environment, rejects malformed signed webhook JSON, processes only supported payment-success events for booking confirmation, adds defensive WooCommerce order handling, disables redirects for provider API calls, and preserves the payment-method field in all booking-verification responses. Zero-due appointments cannot be initialized as a second charged payment. Webhook endpoint errors are returned generically to callers while a diagnostic is written to the server error log.

### Configure and verify before accepting live payments

1. Back up the database and plugin before installing the updated ZIP. Update the plugin in WordPress rather than installing another copy; it uses the same tables and booking records.
2. Open **Intelink Booking → Payments → Settings**, select **Test** mode, enable the gateway(s) you intend to use and configure each test secret. For Flutterwave also configure the webhook secret hash. For Stripe set the webhook signing secret. Keep the test and live credentials distinct.
3. Register the webhook URL shown in the admin for each provider and selected mode. The webhook endpoint must be publicly reachable via HTTPS. Paystack expects HMAC-SHA512; Flutterwave supports the configured verif-hash and its signed webhook variant; Stripe verifies timestamped v1 HMAC-SHA256 signatures.
4. Book a service using each enabled gateway's documented sandbox payment method. Verify that amounts and currency match, the booking moves to Confirmed (or Pending when manual approval is enabled), and customer/admin confirmation messages are queued only once. Confirm that failed or cancelled payments do not get marked paid.
5. Test callback/repeated webhook delivery, expired reservations, partial deposits, refund and refund reconciliation, and WooCommerce order-payment flow if WooCommerce is enabled. Confirm payment mode and selected website currency are supported by the merchant account.
6. Only after successful sandbox tests switch the relevant provider and keys to Live. WordPress cron must run for queued notifications and reservation expiry; install/configure an SMTP plugin for reliable delivery.

**Local contract tests:** `php tests/payment-contracts.php` (maintained outside the installable plugin ZIP). These are mocked provider-contract checks and do not replace authenticated sandbox payments, end-to-end WordPress tests, or real email-delivery tests.


## Version 1.2.0: dedicated appointment management and standalone administration
- Customer appointment management: `[intelink_manage_appointment]` shortcode or existing secure email link `/?ib_manage=BOOKING_REFERENCE#ib_token=...`. The token is required to access booking details, cancel or reschedule. Links retain all existing policy and availability checks.
- Standalone administration entry: `/?ib_portal=1` (requires login and `manage_intelink_booking` capability). This single custom shell contains the existing WordPress administration modules in a same-origin embedded workspace; it is a branded interface, not a replacement authentication system. Existing wp-admin pages continue working.
- Portal navigation reuses existing admin forms and backend rather than duplicating booking operations. All management actions still require WordPress nonce/capability validation.
- After upgrading, visit the portal link while signed in as an administrator. Do not disable `/wp-admin/`, since the portal workspace uses its underlying booking pages.

## v1.3.0 customer appointment management

The `[intelink_manage_appointment]` shortcode renders the dedicated self-service page. A complete private email link includes `?ib_manage=REFERENCE#ib_token=...`. The frontend retrieves server-side management eligibility and displays the cancellation/rescheduling deadline, current booking details, and live eligible time slots. Changes require explicit confirmation, and server-side policy/capacity checks still apply. Cancellation does not automatically refund an existing payment. See `MANAGE-APPOINTMENT-TESTING.md` for a staging test plan.


## v1.4.0 — Customer accounts and programme services

- Customer sign-in/registration and dashboard: `https://YOUR-DOMAIN/?ib_account=1` or `[intelink_customer_dashboard]`. WordPress authentication is retained securely; ordinary customers are redirected away from wp-admin. An existing guest booking must be linked using its private reference and 64-character management token. No email-only account takeover. Customer notification history shows delivery queue status (not guaranteed inbox delivery).
- Dedicated management admin portal: `?ib_portal=1` has its own branded sign-in screen. The standard top-level plugin menu redirects to this portal; hidden underlying WordPress admin routes remain for the existing iframe-based workspace and server-side capabilities. This is not a replacement for WordPress core authentication or other WordPress administrator access.
- Service Programme Length Unit: single/days/weeks/months with a positive count. Session Duration (minutes) remains the actual calendar reservation length; programme length is informational. **Recurring sessions, payment installments, and multi-day calendar occupancy are NOT yet implemented.** Book additional programme sessions separately.
- Test account registration, login, booking while logged in using the same email, guest-booking claim with a private token, notifications, and reschedule/cancel on staging before production.
