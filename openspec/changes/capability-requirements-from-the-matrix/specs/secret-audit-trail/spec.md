## ADDED Requirements

### Requirement: Risky events raise an alert at once

When one of the following events is recorded, the system MUST send a Nextcloud notification to the people it concerns at that moment, not in a digest: a honey secret is accessed (its owner and every administrator), an emergency contact requests access (the grantor), a secret is flagged as possibly compromised (its owner), a new device asks for approval (the user), and an account recovery request is filed (the recovery officers). A notification MUST name the event, the actor and the secret or account, and MUST NOT carry secret material. The event MUST also reach the audit trail and any configured SIEM sink as usual. Events that the server cannot observe, such as a wrong master password typed in the browser, are not alerted.

#### Scenario: A grantor is told about an emergency access request

- **GIVEN** a vault owner who named a colleague as emergency contact
- **WHEN** the colleague requests emergency access
- **THEN** the owner receives a Nextcloud notification at once that names the colleague and the wait period
- **AND** the audit trail records the request

#### Scenario: Administrators are told a decoy was touched

- **GIVEN** a honey secret owned by a security officer
- **WHEN** an application fetches it through the machine API
- **THEN** the owner and every administrator receive a notification naming the application
- **AND** the notification contains no value of the secret
