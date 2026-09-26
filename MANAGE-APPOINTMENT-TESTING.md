# Intelink Booking v1.3.0 — Customer self-service tests

1. Create a page with `[intelink_manage_appointment]`; verify branding and private-link access.
2. Book a test service; open its link from the confirmation email. The link carries a private capability in the URL fragment. Avoid sharing it.
3. Verify service, appointment status, amount and payment status. Print and download confirmation.
4. Select a new date; only server-provided available times should appear. Select a time and confirm. Verify updated appointment and reschedule notifications.
5. Confirm previously occupied slot is released and the new slot occupied, considering capacity.
6. Click Request cancellation; click Keep appointment to abort. Repeat and confirm cancellation; verify status and cancellation notifications.
7. Confirm a cancelled/completed booking cannot be changed, and policies block changes after cutoff.
8. A cancellation does not automatically refund a paid transaction; review refunds separately.
9. Test missing/invalid token and simultaneous slot selection in a WordPress staging environment.
10. Verify both desktop and mobile rendering. No payment gateway or external mail delivery is claimed tested by these local syntax checks.
