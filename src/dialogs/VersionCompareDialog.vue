<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Side-by-side comparison of two decrypted states of one secret: two earlier
  versions, or one earlier version and the current value. Both arrive already
  decrypted with the in-session key; the comparison runs here, in the browser,
  and nothing of it is sent to the server. Masked values (the value and the
  additional fields) stay hidden until the owner reveals them, and revealing
  is per open, like the version details dialog.

  Lives in its own file per ADR-004.

  @spec openspec/changes/capability-requirements-from-the-matrix/specs/secret-version-history/spec.md#requirement-compare-two-versions-side-by-side
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Compare versions')"
		:open="comparison !== null"
		size="large"
		data-testid="version-compare-dialog"
		@update:open="$emit('close')">
		<template v-if="comparison">
			<p v-if="!changed" data-testid="version-compare-same">
				{{ t('keepiq', 'No field changed between these two.') }}
			</p>
			<table class="version-compare__table" data-testid="version-compare-table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('keepiq', 'Field') }}
						</th>
						<th scope="col">
							{{ comparison.leftLabel }}
						</th>
						<th scope="col">
							{{ comparison.rightLabel }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in rows"
						:key="(row.additional ? 'extra:' : '') + row.field"
						:class="{ 'version-compare__row--changed': row.changed }"
						:data-testid="`version-compare-row-${row.field}`"
						:data-changed="row.changed ? 'true' : 'false'">
						<th scope="row">
							{{ labelOf(row) }}
							<span v-if="row.changed" class="version-compare__badge">
								{{ t('keepiq', 'Changed') }}
							</span>
						</th>
						<td>{{ shown(row, row.left) }}</td>
						<td>{{ shown(row, row.right) }}</td>
					</tr>
				</tbody>
			</table>
			<NcButton
				variant="tertiary"
				data-testid="version-compare-reveal"
				@click="revealed = !revealed">
				{{ revealed ? t('keepiq', 'Hide') : t('keepiq', 'Reveal') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { anyChanged, compareStates } from '../utils/versionCompare.js'

const MASK = '••••••••••'

export default {
	name: 'VersionCompareDialog',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/**
		 * `{ left, right, leftLabel, rightLabel }` with both states decrypted,
		 * or `null` when closed.
		 */
		comparison: {
			type: Object,
			default: null,
		},
	},

	emits: ['close'],

	data() {
		return {
			revealed: false,
		}
	},

	computed: {
		/**
		 * The compared rows.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/secret-version-history/spec.md#requirement-compare-two-versions-side-by-side
		 */
		rows() {
			if (!this.comparison) {
				return []
			}
			return compareStates(this.comparison.left, this.comparison.right)
		},

		/**
		 * Whether any field differs.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/secret-version-history/spec.md#requirement-compare-two-versions-side-by-side
		 */
		changed() {
			return anyChanged(this.rows)
		},
	},

	watch: {
		/**
		 * Mask again whenever another comparison opens.
		 *
		 * @return {void}
		 */
		comparison() {
			this.revealed = false
		},
	},

	methods: {
		/**
		 * The label of a row.
		 *
		 * @param {object} row The row.
		 * @return {string}
		 */
		labelOf(row) {
			if (row.additional) {
				return row.field
			}
			const labels = {
				name: this.t('keepiq', 'Name'),
				url: this.t('keepiq', 'URL'),
				login: this.t('keepiq', 'Login'),
				key: this.t('keepiq', 'Value'),
			}
			return labels[row.field] || row.field
		},

		/**
		 * A value as shown: masked until revealed for a masked row, '—' when empty.
		 *
		 * @param {object} row The row.
		 * @param {string} value The value.
		 * @return {string}
		 */
		shown(row, value) {
			if (value === '') {
				return '—'
			}
			return row.masked && !this.revealed ? MASK : value
		},
	},
}
</script>

<style scoped>
.version-compare__table {
	width: 100%;
	border-collapse: collapse;
	margin-block: 8px;
}

.version-compare__table th,
.version-compare__table td {
	text-align: start;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	word-break: break-word;
}

.version-compare__row--changed {
	background-color: var(--color-warning-hover, var(--color-background-hover));
}

.version-compare__badge {
	display: inline-block;
	margin-inline-start: 6px;
	font-size: 12px;
	font-weight: normal;
	color: var(--color-warning-text, var(--color-main-text));
}
</style>
