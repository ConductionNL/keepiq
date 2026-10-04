# extension-generator-policy Specification

## Purpose
The generator under the organisation's policy, and every chosen kind of character in a password.

## Requirements

### Requirement: Every chosen kind of character appears
A generated password MUST hold at least one character of every kind the user chose (upper case, lower case, numbers, special characters), even with a minimum of zero, and none of a kind not chosen. The shared generator does this for the web app and the extension alike.

#### Scenario: Short password, all kinds
@e2e exclude Pure generator function. Covered by tests/extension/generatorPolicy.spec.js ("puts every chosen kind of character in, even with no minimum") and ("leaves out a kind that is not chosen").
- **GIVEN** all four kinds chosen, length 8, no minimums
- **WHEN** a password is generated 300 times
- **THEN** every one holds each kind

### Requirement: Controls the policy decides are shown as such
When the organisation's password policy is on, the Generator tab MUST keep a required kind switched on and its control disabled with the words "required by your organisation", MUST start the minimum of a required number or special character at one, and MUST not let the length go below the policy's floor. The policy is read live each time the tab opens, and the last one seen is used offline.

#### Scenario: A policy that requires numbers and symbols
@e2e exclude Browser-extension popup. Covered by tests/extension/generatorPolicy.spec.js ("locks the controls the policy decides and says why") and ("starts the minimum of a required kind at one").
- **GIVEN** a policy requiring numbers and special characters, with a floor of 14
- **WHEN** the Generator tab opens
- **THEN** those two controls are on, disabled and labelled, their minimums start at one, and the length starts at 14
