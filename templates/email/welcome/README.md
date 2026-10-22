# Human Gold Welcome Email Templates

These files are implementation-ready transactional email templates for the next Utsav/Cursor bundle.

## Observer trigger

Send `observer/email.html` with `observer/email.txt` as the plain-text alternative immediately after:

1. SMS OTP verification succeeds;
2. the first-party device-recognition token is issued; and
3. the pending Observe or Participate RSVP is saved.

The email-confirmation link is asynchronous. Failure to confirm email does not undo device registration or an ordinary RSVP.

## Participant trigger

Send `participant/email.html` with `participant/email.txt` as the plain-text alternative after the WooCommerce webhook confirms the named beneficiary's $12 annual MEGAvoter membership. Do not trigger it merely because checkout was opened or payment is pending.

If the beneficiary's email is already verified, suppress the conditional `email_confirmation_required` block, do not send the confirmation SMS, and enable the dashboard CTA after MFA. If email remains pending, the dashboard CTA must route to confirmation/MFA requirements rather than granting protected access.

## Required placeholders

Common:

- `{{masked_email}}`
- `{{confirm_email_url}}`
- `{{confirmation_expires_at}}`

Observer:

- `{{rsvp_choice}}`
- `{{event_name}}`
- `{{organization_name}}`
- `{{return_to_event_url}}`
- `{{privacy_help_url}}`
- `{{privacy_help_label}}`

Participant:

- `{{participant_display_name}}`
- `{{membership_start_date}}`
- `{{membership_expiration_date}}`
- `{{organizer_dashboard_url}}`
- `{{membership_help_url}}`
- `{{membership_help_label}}`

## Security rules

- Confirmation links must be random, hashed server-side, short-lived and single-use.
- Never place a raw device token, phone number, OTP or identity UUID in a URL.
- Opening a confirmation link confirms the email for the identity; it must not register the device opening the link.
- Replacing an email requires the recognized device and a fresh SMS OTP.
- Mask email addresses in every routed prompt.
- Record send, delivery, bounce, confirmation, resend and replacement events without exposing message contents in public logs.

Register the Observer template with the device-registration/RSVP completion service and the Participant template with the confirmed membership webhook service.
