<template>
	<div class="dashboard">
		<h2>{{ t('app-template', 'Welcome to App Template') }}</h2>
		<p>{{ t('app-template', 'Start building your Nextcloud app here.') }}</p>
	</div>
</template>

<script>
export default {
	name: 'Dashboard',
}
</script>

<style scoped>
.dashboard {
	padding: 20px;
}
</style>
