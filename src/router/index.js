import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import Dashboard from '../views/Dashboard.vue'
import LockScreen from '../views/LockScreen.vue'
import AdminRoot from '../views/settings/AdminRoot.vue'

Vue.use(Router)

const router = new Router({
	mode: 'history',
	base: generateUrl('/apps/doriath'),
	routes: [
		{ path: '/', name: 'Dashboard', component: Dashboard },
		{ path: '/lock', name: 'Lock', component: LockScreen },
		{ path: '/settings', name: 'Settings', component: AdminRoot },
		{ path: '*', redirect: '/' },
	],
})

// Lock screen navigation guard.
// Note: useSessionStore() is imported lazily inside the guard because Pinia
// is not yet active when this module is first loaded (Vue 2 + PiniaVuePlugin
// requires the Vue instance to exist before store access).
let sessionStore = null

router.beforeEach((to, from, next) => {
	// Lazy-load the session store on first navigation.
	if (sessionStore === null) {
		try {
			const { useSessionStore } = require('../store/modules/session.js')
			sessionStore = useSessionStore()
		} catch {
			// Pinia not ready yet — allow navigation (App.vue will handle loading state).
			return next()
		}
	}

	// Public routes skip the lock screen.
	if (to.meta?.public === true || to.name === 'Lock') {
		if (to.name === 'Lock' && !sessionStore.isLocked) {
			return next({ name: 'Dashboard' })
		}
		return next()
	}

	// If locked, redirect to lock screen with return URL.
	if (sessionStore.isLocked) {
		return next({
			name: 'Lock',
			query: { returnUrl: to.fullPath },
		})
	}

	// Update activity on route change.
	sessionStore.updateActivity()
	next()
})

export default router
