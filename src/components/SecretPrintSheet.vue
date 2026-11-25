<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  SecretPrintSheet — the sheet a login prints as: its name, address,
  username and password (vault-item-clone-preview-and-print D3). It is
  teleported to the end of the body into a print-only container: on screen
  it is never shown, and in print it is the only thing on the page. The
  one-time code seed is not a prop and is never printed (D4).

  @spec openspec/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
-->
<template>
	<Teleport to="body">
		<div class="keepiq-print-root" data-testid="secret-print-sheet">
			<article class="secret-print-sheet">
				<h1 class="secret-print-sheet__name" data-testid="secret-print-name">
					{{ name }}
				</h1>
				<dl class="secret-print-sheet__fields">
					<template v-if="url">
						<dt>{{ t('keepiq', 'Address') }}</dt>
						<dd data-testid="secret-print-url">
							{{ url }}
						</dd>
					</template>
					<template v-if="login">
						<dt>{{ t('keepiq', 'Username') }}</dt>
						<dd data-testid="secret-print-login">
							{{ login }}
						</dd>
					</template>
					<dt>{{ t('keepiq', 'Password') }}</dt>
					<dd
						class="secret-print-sheet__password"
						data-testid="secret-print-password">
						{{ password }}
					</dd>
				</dl>
				<p class="secret-print-sheet__note">
					{{
						t(
							'keepiq',
							'Printed from Keepiq. Keep this sheet somewhere safe and destroy it when it is no longer needed.',
						)
					}}
				</p>
			</article>
		</div>
	</Teleport>
</template>

<script>
import { t } from '@nextcloud/l10n'
import secretPrintProps from './secretPrintProps.js'

/**
 * The print sheet of one login. Renders nothing on screen.
 */
export default {
	name: 'SecretPrintSheet',

	props: secretPrintProps,

	methods: {
		t,
	},
}
</script>

<style>
/* Not scoped: the print rules have to reach the rest of the page. */
@media screen {
	.keepiq-print-root {
		display: none;
	}
}

@media print {
	body > *:not(.keepiq-print-root) {
		display: none !important;
	}

	.keepiq-print-root {
		display: block;
		color: var(--color-main-text);
		background: var(--color-main-background);
	}

	.secret-print-sheet__fields {
		display: grid;
		grid-template-columns: max-content 1fr;
		gap: calc(var(--default-grid-baseline) * 2)
			calc(var(--default-grid-baseline) * 6);
	}

	.secret-print-sheet__fields dt {
		font-weight: bold;
	}

	.secret-print-sheet__password {
		font-family: var(--font-face-monospace, monospace);
		word-break: break-all;
	}

	.secret-print-sheet__note {
		margin-top: calc(var(--default-grid-baseline) * 8);
		color: var(--color-text-maxcontrast);
	}
}
</style>
