<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The crossed-out link in a tinted circle that heads a public page's
  "not available" state, on the link-share page and the request fill page.

  @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
-->
<template>
	<div class="link-unavailable-icon">
		<LinkVariantOff :size="36" />
	</div>
</template>

<script>
import LinkVariantOff from 'vue-material-design-icons/LinkVariantOff.vue'

export default {
	name: 'LinkUnavailableIcon',

	components: { LinkVariantOff },
}
</script>

<style scoped>
.link-unavailable-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 72px;
	height: 72px;
	margin: 0 auto 16px;
	border-radius: 50%;
	background: var(--color-background-dark, #ededed);
	background: color-mix(in srgb, var(--color-error, #d91f2d) 12%, transparent);
	color: var(--color-error, #d91f2d);
}
</style>
