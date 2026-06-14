## ADDED Requirements

### Requirement: Notification on Share Received
When a secret is shared with a user (directly or via group share approval), the recipient MUST receive a Nextcloud notification via `OCP\Notification\IManager`.

Notification content:
- App: `doriath`
- Subject: `secret_shared`
- Parameters: sharer user ID, secret name
- Object: `secret`, secret_id (the recipient's copy)
- Body: "{User A} shared a secret with you"
- Action link: opens the shared secret in the recipient's vault

The notification respects the user's `notify_shares` setting. If the user has disabled share notifications, the notification MUST NOT be sent.

#### Scenario: Secret shared with user
- **WHEN** user A shares a secret with user B and B has notify_shares enabled
- **THEN** B MUST receive a Nextcloud notification with subject secret_shared

#### Scenario: Share notification disabled
- **WHEN** user A shares a secret with user B and B has notify_shares disabled
- **THEN** no notification is sent to B

### Requirement: Notification on Share Request
When a recipient submits a share request, the original owner MUST receive a Nextcloud notification. When the owner resolves the request (approve or deny), the requester MUST receive a notification of the outcome.

Notification subjects:
- `share_request`: sent to owner when a request is submitted
- `share_request_result`: sent to requester when owner approves or denies

Both notifications respect the `notify_shares` user setting.

#### Scenario: Share request submitted
- **WHEN** user B submits a share request to owner A
- **THEN** A MUST receive a notification with subject share_request and actions Approve/Deny

#### Scenario: Share request resolved
- **WHEN** owner A approves or denies B's share request
- **THEN** B MUST receive a notification with subject share_request_result indicating the outcome

### Requirement: Notification on Group Member Added
When a new user joins a group with active GroupShares, the secret owner MUST receive a notification asking to approve the share. This notification MUST include Approve and Deny actions.

Notification subject: `group_member_added`
This notification respects the owner's `notify_group_shares` setting.

#### Scenario: New group member notification
- **WHEN** user X joins group G which has a GroupShare for a secret owned by A
- **THEN** A MUST receive a notification with subject group_member_added, including user X's display name, group G's name, and the secret's name
- **AND** the notification MUST include Approve and Deny action buttons

### Requirement: Notification on Shared Copy Compromise
When a shared copy is flagged possibly_compromised_at during suite migration, the original owner MUST receive a notification advising them to replace the secret value.

Notification subject: `secret_compromised`
This notification respects the owner's `notify_security` setting.

#### Scenario: Compromise notification sent
- **WHEN** a shared copy of A's secret is flagged possibly_compromised_at during B's suite migration
- **THEN** A MUST receive a notification with subject secret_compromised referencing the affected secret

### Requirement: NotificationService with Subject Setting Map
All sharing-related notifications MUST be routed through a NotificationService that checks the recipient's notification preferences before sending. The service MUST use a SUBJECT_SETTING_MAP constant to map notification subjects to user setting keys.

| Subject | Setting Key | Default |
|---------|------------|---------|
| `secret_shared` | `notify_shares` | true |
| `share_request` | `notify_shares` | true |
| `share_request_result` | `notify_shares` | true |
| `group_member_added` | `notify_group_shares` | true |
| `secret_compromised` | `notify_security` | true |

#### Scenario: Notification preference check
- **WHEN** a sharing notification with subject S is about to be sent to user U
- **THEN** the NotificationService MUST look up S in SUBJECT_SETTING_MAP to find the setting key
- **AND** check user U's preference for that setting key
- **AND** only send the notification if the preference is true or not explicitly set (default to true)

### Requirement: INotifier Implementation
The app MUST register an INotifier that renders all sharing notification subjects into human-readable messages for the Nextcloud notification panel.

#### Scenario: Notification rendering
- **WHEN** the Nextcloud notification panel renders a Doriath sharing notification
- **THEN** the INotifier MUST produce a localized message with the sharer's display name and the secret's name
- **AND** include a link to the affected secret in the recipient's vault
