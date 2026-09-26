# Recent booking popups (v1.5.0)

Administration: Intelink Booking → Recent Booking Popups in the standalone booking dashboard.

- Disabled by default. Enable to show notices based only on actual verified **live** successful payments for confirmed/completed appointments in the last 30 days and currently active services.
- No customer name, country, booking reference, or private appointment details are published.
- Test payments and illustrative examples never reach the public popup.
- Admin design preview is explicitly labeled demo.
- Configure location, initial delay, repeat interval, visible duration, service image, maximum items, and an optional booking-page link.
- If no eligible bookings exist, nothing is displayed. This behavior is intentional.
- Service images come from the service's WordPress featured image.
- Clear WordPress/page caches after changing settings. Do not cache dynamic activity on a public CDN for prolonged periods.
- This feature is not a page-view tracker. Do not describe notices as “recently viewed” without real view events.

The previous appointment booking, payments, portal, and notifications code is preserved.
