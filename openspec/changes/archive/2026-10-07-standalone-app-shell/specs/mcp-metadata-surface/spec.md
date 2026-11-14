## MODIFIED Requirements

### Requirement: Surface Is Exposed Only Through The Scannable-Services Opt-In
`lib/Mcp/KeepiqScannableServices.php` MUST implement `OCA\OpenRegister\Mcp\IMcpScannableServices`, MUST be registered under the `IMcpScannableServices::keepiq` DI alias, and MUST list exactly the classes carrying the three read tools. Keepiq MUST NOT register an `IMcpToolProvider`, MUST NOT declare `x-openregister-mcp` on any register (nothing to derive; vault rows never enter OR per the `integration-boundary` capability), and MUST NOT expose any write tool. Tool visibility is governed by OpenRegister's default-deny grant whitelist; Keepiq MUST NOT implement a parallel grant system.

The surface MUST exist only when OpenRegister is installed and enabled, because OpenRegister's MCP endpoint is what offers it. Keepiq MUST register the alias as a string-to-string service alias that autoloads nothing at registration time, so that registering it neither requires nor detects OpenRegister. `KeepiqScannableServices` and the `#[McpTool]`-carrying classes MUST be loaded only when OpenRegister's scanner resolves the alias, which can only happen while OpenRegister is running. Keepiq MUST NOT put OpenRegister's autoload prefix on the autoloader to make this work. Without OpenRegister, no Keepiq code path MUST resolve the alias or instantiate a tool class.

#### Scenario: The surface is exactly three read tools
- **WHEN** Hermiq lists the `keepiq.*` tool catalog
- **THEN** it contains exactly `listEntries`, `expiryReport`, `rotationStatus`, each `readOnlyHint: true`, `scope: read`
- **AND** no tool name ends in `.create`, `.update`, `.delete` and no other curated tool exists
@e2e exclude registry-shape contract; covered by the MCP surface probe (tests/integration/Mcp/McpSurfaceTest.php).

#### Scenario: Ungranted agent sees nothing
- **WHEN** an agent without a Keepiq grant lists tools
- **THEN** no `keepiq.*` tool is offered
@e2e exclude registry-shape contract; covered by the MCP surface probe (tests/integration/Mcp/McpSurfaceTest.php).

#### Scenario: Without OpenRegister nothing MCP-related loads
- **GIVEN** an instance where OpenRegister is not installed
- **WHEN** Keepiq registers and boots, and a user works through the vault
- **THEN** `Application::register()` MUST complete with the alias registered, and no `OCA\OpenRegister\Mcp\…` name and no `OCA\Keepiq\Mcp\…` class MUST be autoloaded during the request
@e2e exclude bootstrap wiring — no UI surface; covered by a unit test that registers McpRegistrar against a recording context and autoloader.

#### Scenario: With OpenRegister the tools appear without extra setup
- **GIVEN** an instance where OpenRegister is installed and enabled, and an agent with a Keepiq read grant
- **WHEN** the agent lists tools through OpenRegister's MCP endpoint
- **THEN** the three `keepiq.*` read tools MUST be offered, exactly as before this change
@e2e exclude agent-facing MCP surface — verified live through OpenRegister's MCP endpoint as part of the change's live check, as hermiq-ai-tooling was (#1048).
