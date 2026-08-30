<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Color-coded password-strength badge for a secret, driven purely by the
  in-memory health store. Renders nothing while the vault is locked or when the
  secret has no strength score (machine key material is not scored). Severity is
  conveyed by an accessible text label, never colour alone (WCAG 1.4.1), and all
  colours come from NL Design / Nextcloud CSS variables — no hardcoded colours.

  @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-strength-scoring-and-badges
-->
<template>
	<span
		v-if="score !== null"
		class="strength-badge"
		:class="`strength-badge--${severity}`"
		:data-testid="`strength-badge-${secretId}`"
		:title="label">
		{{ label }}
	</span>
</template>

<script>
import { useHealthStore } from '../store/modules/health.js'

export default {
	name: 'StrengthBadge',

	props: {
		/** The secret's id, used to look up its score in the health store. */
		secretId: {
			type: String,
			required: true,
		},
	},

	computed: {
		/**
		 * The zxcvbn score (0–4) for this secret, or null when not scored.
		 *
		 * @return {number|null}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-strength-scoring-and-badges
		 */
		score() {
			const store = useHealthStore()
			const value = store.scoreById[this.secretId]
			return value === undefined || value === null ? null : value
		},

		/**
		 * Severity token (CSS class + semantic), mapped from the zxcvbn score.
		 *
		 * @return {string}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-strength-scoring-and-badges
		 */
		severity() {
			const tokens = ['danger', 'danger', 'warning', 'success', 'success']
			return tokens[this.score] || 'danger'
		},

		/**
		 * Accessible strength label (never colour-alone).
		 *
		 * @return {string}
		 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-strength-scoring-and-badges
		 */
		label() {
			const labels = [
				t('keepiq', 'Very weak'),
				t('keepiq', 'Weak'),
				t('keepiq', 'Fair'),
				t('keepiq', 'Strong'),
				t('keepiq', 'Very strong'),
			]
			return labels[this.score] || labels[0]
		},
	},
}
</script>

<style scoped lang="scss">
.strength-badge {
	display: inline-block;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 100px);
	font-size: 0.75rem;
	font-weight: 600;
	line-height: 1.4;

	/* --color-error / --color-warning / --color-success are BACKGROUND tints,
	   and each theme inverts them: success is #D8F3DA (pale) in light and
	   #11321A (near-black) in dark. A single shared foreground is therefore
	   wrong in one theme by construction — the badge used --color-primary-text
	   for all three, which is why "Very strong" was unreadable. Each tint has a
	   paired *-text value that Nextcloud flips with it; use that. */
	&--danger {
		background-color: var(--color-error);
		color: var(--color-error-text);
	}

	&--warning {
		background-color: var(--color-warning);
		color: var(--color-warning-text);
	}

	&--success {
		background-color: var(--color-success);
		color: var(--color-success-text);
	}
}
</style>
