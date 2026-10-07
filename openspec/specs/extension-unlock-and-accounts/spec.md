# extension-unlock-and-accounts Specification

## Purpose
Unlocking and accounts in the browser extension: offline unlock, clear messages, the unlock screen, and logging out.

## Requirements

### Requirement: Unlock offline and say what went wrong
When the server cannot be reached, the extension MUST unlock with the suite in the account's vault snapshot, if there is one, and the master password. Without a snapshot it cannot unlock offline. A wrong master password MUST be reported as "Invalid master password". A failed pairing MUST say what to do about it: the user name and app password were refused, the account may not use Keepiq, Keepiq is not installed there or the address is wrong, the server could not answer, or the server cannot be reached. The unlock screen MUST put the cursor in the master password field, MUST offer Show and Hide for it, and MUST unlock on Enter.

#### Scenario: The server is away
@e2e exclude Browser-extension worker. Covered by tests/extension/unlockAccounts.spec.js ("unlocks from the vault snapshot when the server cannot be reached") and ("cannot unlock offline without a snapshot").
- **GIVEN** a locked account with a vault snapshot, and no server
- **WHEN** the user enters the master password
- **THEN** the vault unlocks offline; a wrong password is still refused

#### Scenario: Clear messages
@e2e exclude Browser-extension worker. Covered by tests/extension/unlockAccounts.spec.js ("says the master password is wrong, not what the crypto library said"), ("explains a failed pairing (%s)") and ("explains a server that does not run Keepiq when pairing").
- **GIVEN** a wrong master password, or a pairing the server refuses
- **WHEN** the user tries
- **THEN** the message says what went wrong in plain words

#### Scenario: The unlock screen
@e2e exclude Browser-extension popup. Covered by tests/extension/unlockAccounts.spec.js ("focuses the master password, shows and hides it, and unlocks on Enter").
- **GIVEN** a locked account
- **WHEN** the popup opens
- **THEN** the master password field has the cursor, Show and Hide switch it, and Enter unlocks

### Requirement: Lock and log out per account or all
Settings MUST offer to log out of the account on screen and to log out of all accounts, each after asking: logging out deletes the account's app password in Nextcloud and signs it out here, keeping its address and user, so the user signs in again with a new app password. The signed-out screen MUST say whether the user logged out or the password was revoked. Settings MUST also offer to lock all accounts. The account bar MUST show the initials of the account on screen.

#### Scenario: Log out of one account
@e2e exclude Browser-extension worker. Covered by tests/extension/unlockAccounts.spec.js ("logs out of one account: its app password is deleted in Nextcloud and the account kept") and ("logs out of all accounts").
- **GIVEN** two unlocked accounts
- **WHEN** the user logs out of the one on screen, or of all
- **THEN** that account's (or every) app password is deleted in Nextcloud and the account shows as signed out

#### Scenario: From Settings
@e2e exclude Browser-extension popup. Covered by tests/extension/unlockAccounts.spec.js ("locks all accounts, logs out from Settings after asking, and shows why").
- **GIVEN** the popup with two unlocked accounts
- **WHEN** the user locks all accounts, then logs out of one and confirms
- **THEN** both lock, and the signed-out screen says the user logged out
