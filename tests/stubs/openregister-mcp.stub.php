<?php

/**
 * OpenRegister's MCP attribute and scannable-services contract, for tests and
 * static analysis when the openregister sibling app is absent. Declaration
 * only; the real classes live in openregister/lib/Mcp/. Loaded by
 * tests/bootstrap-unit.php only when the real classes do not resolve.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp {

	interface IMcpScannableServices {
		/**
		 * @return list<class-string>
		 */
		public function getScannableServiceClasses(): array;
	}
}

namespace OCA\OpenRegister\Mcp\Attribute {

	#[\Attribute(\Attribute::TARGET_METHOD)]
	final class McpTool {
		public function __construct(
			public readonly ?string $name = null,
			public readonly ?string $description = null,
			public readonly ?bool $readOnlyHint = null,
			public readonly ?bool $destructiveHint = null,
			public readonly ?bool $idempotentHint = null,
			public readonly ?string $scope = null,
			public readonly ?string $subject = null,
			public readonly ?string $action = null,
		) {
		}
	}
}
