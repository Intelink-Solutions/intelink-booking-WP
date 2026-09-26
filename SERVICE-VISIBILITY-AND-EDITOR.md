# Intelink Booking v1.4.1 — Service visibility and editor update

## What changed
- Dedicated two-column Add/Edit Service form with sections for information, pricing, duration/programme, availability, publishing, image and category.
- New services are **Active** by default when saved from the admin editor. Existing Draft and Inactive services are **not** automatically published.
- Removed the WordPress rich-text editor from this form to avoid the malformed toolbar in embedded admin screens. Service description is still sanitized with `wp_kses_post` on save.
- Added public catalogue diagnostics on the Services screen, including a link to the actual REST catalogue.
- The existing shortcode, service records, payment gateways, appointments and customer dashboards are preserved.

## If a service still does not appear
1. Open Intelink Booking > Services; edit the service and set Visibility to **Active**, then Save Changes.
2. Check `https://YOUR-SITE/wp-json/intelink-booking/v1/catalog`. The service must occur in `services`.
3. Ensure your booking page contains `[intelink_booking]`. A shortcode with a specific `service`, `services`, or `category` attribute intentionally restricts the results.
4. Clear browser, caching-plugin and CDN caches; refresh the page.
5. If catalog returns an error, check the WordPress debug log and server PHP logs. If the catalogue includes the service but the UI does not, check browser developer tools for failed JS requests or a stale `assets/booking.js` file.
6. Active services appear even if there are no available times; working schedules are required for users to proceed with a date/time booking.

## Upgrade
Back up WordPress files and database. Upload the new ZIP and replace the installed plugin. Do not uninstall it; existing records remain in their current custom tables. Review draft services individually rather than bulk-publishing them.
