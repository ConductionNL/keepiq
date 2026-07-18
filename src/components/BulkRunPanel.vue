<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Shared bulk-run progress + report UI (bulk-actions §3.2): a progress
  bar with cancel while the runner is active, then a per-item report
  table (ok / failed+reason / skipped) with a retry-failed button.

  @spec openspec/changes/bulk-actions/specs/bulk-actions/spec.md#requirement-progress-and-report
-->
<template>
	<div class="bulk-run" data-testid="bulk-run-panel">
		<div v-if="progress.running" class="bulk-run__progress">
			<progress :value="progress.done" :max="progress.total" class="bulk-run__bar" />
			<span data-testid="bulk-progress-label">
				{{ progress.label }} — {{ progress.done }} / {{ progress.total }}
			</span>
			<NcButton type="tertiary" data-testid="bulk-cancel" @click="store.cancel()">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
		</div>

		<template v-else-if="report.length">
			<table class="bulk-run__report" data-testid="bulk-report">
				<thead>
					<tr>
						<th>{{ t('doriath', 'Secret') }}</th>
						<th>{{ t('doriath', 'Result') }}</th>
						<th>{{ t('doriath', 'Reason') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in report" :key="row.secretId">
						<td class="bulk-run__mono">
							{{ nameFor(row.secretId) }}
						</td>
						<td>
							<span :class="['bulk-run__status', `bulk-run__status--${row.status}`]">
								{{ row.status }}
							</span>
						</td>
						<td>{{ row.reason || '' }}</td>
					</tr>
				</tbody>
			</table>
			<NcButton v-if="failedCount > 0"
				type="secondary"
				data-testid="bulk-retry-failed"
				@click="$emit('retry')">
				{{ t('doriath', 'Retry {count} failed', { count: failedCount }) }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { useBulkStore } from '../store/modules/bulk.js'
import { useSecretStore } from '../store/modules/secret.js'

export default {
	name: 'BulkRunPanel',
	components: { NcButton },
	emits: ['retry'],
	computed: {
		store() {
			return useBulkStore()
		},
		progress() {
			return this.store.progress
		},
		report() {
			return this.store.report
		},
		failedCount() {
			return this.store.failedItems.length
		},
	},
	methods: {
		/**
		 * Resolve a secret id to its (plaintext) name for the report.
		 *
		 * @param {string} secretId The secret id.
		 * @return {string}
		 */
		nameFor(secretId) {
			const secret = useSecretStore().secrets.find((s) => s.id === secretId)
			return secret?.name || secretId
		},
	},
}
</script>

<style scoped>
.bulk-run__progress {
	display: flex;
	align-items: center;
	gap: 8px;
}

.bulk-run__bar {
	flex: 1;
}

.bulk-run__report {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 8px;
}

.bulk-run__report th,
.bulk-run__report td {
	text-align: start;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.bulk-run__mono {
	word-break: break-all;
}

.bulk-run__status {
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 12px);
	font-size: 12px;
	font-weight: 600;
}

.bulk-run__status--ok {
	background-color: var(--color-success, #2d7b41);
	color: var(--color-success-text, #fff);
}

.bulk-run__status--failed {
	background-color: var(--color-error, #d91f2d);
	color: var(--color-error-text, #fff);
}

.bulk-run__status--skipped {
	background-color: var(--color-background-dark, #ededed);
	color: var(--color-main-text, #222);
}
</style>
