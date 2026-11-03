## ADDED Requirements

### Requirement: Card fields are never captured
The capture collector MUST NOT report a field with a card role as a password or a username, so submitting a payment form never shows a save or update bar and never puts a card number or security code into the capture queue. Capturing a submitted card or identity is not part of this change.

#### Scenario: Checkout with a password-typed CVV
- **GIVEN** a checkout form with a card number, an expiry and a `type=password` CVV, all filled
- **WHEN** the user submits it
- **THEN** no `capture.submitted` is sent and no bar is shown
