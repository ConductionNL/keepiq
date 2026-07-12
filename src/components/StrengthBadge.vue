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
			return (value === undefined || value === null) ? null : value
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
				t('doriath', 'Very weak'),
				t('doriath', 'Weak'),
				t('doriath', 'Fair'),
				t('doriath', 'Strong'),
				t('doriath', 'Very strong'),
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
	color: var(--color-primary-text, #fff);

	&--danger {
		background-color: var(--color-error, #c0392b);
	}

	&--warning {
		background-color: var(--color-warning, #d49000);
		color: var(--color-main-text, #000);
	}

	&--success {
		background-color: var(--color-success, #2d7b35);
	}
}
</style>
