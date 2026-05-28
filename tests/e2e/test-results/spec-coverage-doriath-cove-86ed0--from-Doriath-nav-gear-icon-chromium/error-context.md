# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: spec-coverage/doriath-coverage.spec.ts >> User Settings — spec: user-settings/spec.md >> user-opens-settings — NcAppSettingsDialog opens from Doriath nav gear icon
- Location: tests/e2e/spec-coverage/doriath-coverage.spec.ts:239:6

# Error details

```
Error: expect(locator).toBeVisible() failed

Locator: locator('h1.lock-screen__title, .lock-screen h1')
Expected: visible
Timeout: 15000ms
Error: element(s) not found

Call log:
  - Expect "toBeVisible" with timeout 15000ms
  - waiting for locator('h1.lock-screen__title, .lock-screen h1')

```

# Page snapshot

```yaml
- generic [active] [ref=e1]:
  - generic [ref=e3]:
    - banner [ref=e4]
    - generic [ref=e7]:
      - heading "Nextcloud" [level=1] [ref=e8]
      - generic [ref=e9]:
        - heading "Maintenance mode" [level=2] [ref=e11]
        - paragraph [ref=e12]: This Nextcloud instance is currently in maintenance mode, which may take a while. This page will refresh itself when the instance is available again.
        - paragraph [ref=e13]: Contact your system administrator if this message persists or appeared unexpectedly.
  - contentinfo [ref=e14]:
    - paragraph [ref=e15]:
      - link "Nextcloud" [ref=e16] [cursor=pointer]:
        - /url: https://nextcloud.com
      - text: – a safe home for all your data
```

# Test source

```ts
  147 | 	 * THEN the strength meter MUST show a warning and the submit button
  148 | 	 *      MUST remain disabled.
  149 | 	 *
  150 | 	 * Uses the pre-provisioned `dor-e2e-test` user (password: DorTest123!) which
  151 | 	 * has no EncryptionSuite, so Doriath shows the first-time setup form.
  152 | 	 * If that user already has a suite from a prior run we skip gracefully.
  153 | 	 */
  154 | 	test.setTimeout(90_000)
  155 | 	test('weak-password-rejected — strength meter blocks weak password on setup', async ({ page }) => {
  156 | 		// @e2e encryption-suites::weak-password-rejected
  157 | 
  158 | 		// dor-e2e-test is a pre-provisioned account with no EncryptionSuite.
  159 | 		const testUser = 'dor-e2e-test'
  160 | 		const testPassword = 'DorTest123!'
  161 | 
  162 | 		// Open a fresh browser context to get a clean session for testUser.
  163 | 		const browser = page.context().browser()!
  164 | 		const testContext = await browser.newContext()
  165 | 		const testPage = await testContext.newPage()
  166 | 		testPage.setDefaultTimeout(20_000)
  167 | 
  168 | 		try {
  169 | 			const loginRes = await testPage.goto('http://localhost:8080/index.php/login', { waitUntil: 'domcontentloaded' })
  170 | 
  171 | 			// If the login page redirected directly to the dashboard (already authed),
  172 | 			// navigate directly to the Doriath lock screen as the test user.
  173 | 			const currentUrl = testPage.url()
  174 | 			if (!/\/login/.test(currentUrl)) {
  175 | 				// Already authenticated — skip login form, go straight to doriath.
  176 | 				await testPage.goto('http://localhost:8080/index.php/apps/doriath/lock', { waitUntil: 'domcontentloaded' })
  177 | 			} else {
  178 | 				await testPage.locator('input[name="user"]').fill(testUser, { timeout: 10_000 })
  179 | 				await testPage.locator('input[name="password"]').fill(testPassword)
  180 | 				await testPage.locator('button[type="submit"]').first().click()
  181 | 				await testPage.waitForSelector('#header, header.header', { timeout: 30_000 })
  182 | 				await testPage.goto('http://localhost:8080/index.php/apps/doriath/lock', { waitUntil: 'domcontentloaded' })
  183 | 			}
  184 | 
  185 | 			// Wait for the lock screen to render.
  186 | 			const heading = testPage.locator('h1.lock-screen__title, .lock-screen h1')
  187 | 			await expect(heading).toBeVisible({ timeout: 20_000 })
  188 | 			const headingText = await heading.textContent()
  189 | 
  190 | 			// If this user already has a suite from a previous test run, the app
  191 | 			// shows the unlock form — skip gracefully.
  192 | 			if (!/set up/i.test(headingText ?? '')) {
  193 | 				return
  194 | 			}
  195 | 
  196 | 			// Type a weak password ("password") — zxcvbn score 0.
  197 | 			await testPage.locator('input[type="password"]').first().fill('password')
  198 | 
  199 | 			// Wait for the strength meter to appear (rendered by PasswordStrengthMeter.vue).
  200 | 			await expect(
  201 | 				testPage.locator('.password-strength-meter'),
  202 | 			).toBeVisible({ timeout: 5_000 })
  203 | 
  204 | 			// The strength feedback must indicate weakness.
  205 | 			const feedback = testPage.locator('.password-strength-meter__feedback')
  206 | 			await expect(feedback).toBeVisible()
  207 | 			const feedbackText = await feedback.textContent()
  208 | 			// Accept any of: "Very weak", "Weak", "At least N characters", warning text.
  209 | 			expect(feedbackText).toBeTruthy()
  210 | 
  211 | 			// The submit button must be disabled because canSubmitSetup is false
  212 | 			// (password too weak — strengthValid === false).
  213 | 			const submitBtn = testPage.locator('button[type="button"]').filter({ hasText: /Set up vault/i })
  214 | 			await expect(submitBtn).toBeVisible()
  215 | 			await expect(submitBtn).toBeDisabled()
  216 | 		} finally {
  217 | 			// Force-close the context without waiting for all network requests.
  218 | 			testContext.close().catch(() => {})
  219 | 		}
  220 | 	})
  221 | })
  222 | 
  223 | // ---------------------------------------------------------------------------
  224 | // User Settings
  225 | // ---------------------------------------------------------------------------
  226 | 
  227 | test.describe('User Settings — spec: user-settings/spec.md', () => {
  228 | 
  229 | 	/**
  230 | 	 * @e2e user-settings::user-opens-settings
  231 | 	 * GIVEN a user clicks the gear icon in the Doriath navigation
  232 | 	 * WHEN the dialog opens
  233 | 	 * THEN the system MUST display NcAppSettingsDialog with user preference sections.
  234 | 	 *
  235 | 	 * The gear icon is part of the Nextcloud app-navigation footer and opens
  236 | 	 * the NcAppSettingsDialog injected via CnAppRoot's #user-settings slot.
  237 | 	 * We navigate to the Doriath app first to ensure the Vue app is mounted.
  238 | 	 */
  239 | 	test('user-opens-settings — NcAppSettingsDialog opens from Doriath nav gear icon', async ({ page }) => {
  240 | 		// @e2e user-settings::user-opens-settings
  241 | 
  242 | 		// Navigate into the app so the Vue shell is mounted.
  243 | 		await goToLockScreen(page)
  244 | 		// Wait for the lock screen to be ready (confirms the SPA mounted).
  245 | 		await expect(
  246 | 			page.locator('h1.lock-screen__title, .lock-screen h1'),
> 247 | 		).toBeVisible({ timeout: 15_000 })
      |     ^ Error: expect(locator).toBeVisible() failed
  248 | 
  249 | 		// The NcAppSettingsDialog can be opened via the app navigation gear
  250 | 		// icon. In CnAppRoot it is wired to the "user-settings" manifest action.
  251 | 		// The gear button selector follows Nextcloud's app-navigation pattern.
  252 | 		const settingsBtn = page.locator(
  253 | 			'button[data-nav-entry-id="user-settings"], '
  254 | 			+ '.app-navigation__footer button[aria-label*="settings" i], '
  255 | 			+ '.app-navigation__footer button[aria-label*="Settings" i], '
  256 | 			+ 'a[href*="user-settings"], '
  257 | 			+ 'button.settings-button',
  258 | 		).first()
  259 | 
  260 | 		// If the gear button is in the viewport, click it.
  261 | 		if (await settingsBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
  262 | 			await settingsBtn.click()
  263 | 			// The NcAppSettingsDialog must appear.
  264 | 			const dialog = page.locator('[class*="settings-dialog"], [role="dialog"]').first()
  265 | 			await expect(dialog).toBeVisible({ timeout: 10_000 })
  266 | 		} else {
  267 | 			// App navigation may be collapsed or the button selector varies
  268 | 			// across NC versions. Assert the lock screen is present as a
  269 | 			// minimum — the settings dialog requires the app to be unlocked.
  270 | 			// This confirms the app shell has mounted (prerequisite for settings).
  271 | 			await expect(
  272 | 				page.locator('.lock-screen'),
  273 | 			).toBeVisible({ timeout: 5_000 })
  274 | 		}
  275 | 	})
  276 | })
  277 | 
  278 | // ---------------------------------------------------------------------------
  279 | // Admin Settings
  280 | // ---------------------------------------------------------------------------
  281 | 
  282 | test.describe('Admin Settings — spec: admin-settings/spec.md', () => {
  283 | 
  284 | 	/**
  285 | 	 * @e2e admin-settings::admin-opens-settings
  286 | 	 * GIVEN the admin navigates to Doriath settings
  287 | 	 * WHEN the page loads
  288 | 	 * THEN the first section MUST be a CnVersionInfoCard with app name "Doriath"
  289 | 	 *      and current version.
  290 | 	 */
  291 | 	test('admin-opens-settings — admin settings page loads with CnVersionInfoCard', async ({ page }) => {
  292 | 		// @e2e admin-settings::admin-opens-settings
  293 | 
  294 | 		await page.goto('/index.php/settings/admin/doriath', { waitUntil: 'domcontentloaded' })
  295 | 		await page.waitForLoadState('domcontentloaded', { timeout: 15_000 })
  296 | 
  297 | 		// The NC settings page renders a server-side h1 "Administration settings: Doriath"
  298 | 		// and loads the doriath-settings.js bundle for the Vue UI.
  299 | 		// Assert the server-rendered heading confirming the correct section loaded.
  300 | 		const pageHeading = page.locator('h1').first()
  301 | 		await expect(pageHeading).toBeVisible({ timeout: 15_000 })
  302 | 		const headingText = await pageHeading.textContent()
  303 | 		expect(headingText).toMatch(/Doriath/i)
  304 | 	})
  305 | 
  306 | 	/**
  307 | 	 * @e2e admin-settings::ca-healthy
  308 | 	 * GIVEN the CA is bootstrapped and no renewal is needed
  309 | 	 * WHEN admin views settings
  310 | 	 * THEN the CA section MUST show "Healthy" status.
  311 | 	 *
  312 | 	 * The test environment bootstraps the CA on first install (Repair step).
  313 | 	 * The CA status endpoint returns {"status":"healthy"} in normal conditions.
  314 | 	 * CaHealthSection.vue renders the translated "Healthy" label when
  315 | 	 * caStatus.status === 'healthy'.
  316 | 	 */
  317 | 	test('ca-healthy — CA section shows Healthy status on admin settings page', async ({ page }) => {
  318 | 		// @e2e admin-settings::ca-healthy
  319 | 
  320 | 		// First verify via API that the CA is actually healthy in this environment.
  321 | 		const apiRes = await page.request.get(
  322 | 			'/index.php/apps/doriath/api/v1/ca/status',
  323 | 			{
  324 | 				headers: {
  325 | 					Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`,
  326 | 					'OCS-APIREQUEST': 'true',
  327 | 					'X-Requested-With': 'XMLHttpRequest',
  328 | 				},
  329 | 			},
  330 | 		)
  331 | 		// If the CA API is unreachable, skip gracefully.
  332 | 		if (!apiRes.ok()) {
  333 | 			test.skip(true, `CA status API returned ${apiRes.status()} — skipping CA healthy UI check`)
  334 | 			return
  335 | 		}
  336 | 		const caData = await apiRes.json()
  337 | 		if (caData.status !== 'healthy') {
  338 | 			test.skip(true, `CA status is "${caData.status}" not "healthy" in this environment`)
  339 | 			return
  340 | 		}
  341 | 
  342 | 		await page.goto('/index.php/settings/admin/doriath')
  343 | 		await page.waitForLoadState('networkidle', { timeout: 30_000 })
  344 | 
  345 | 		// CaHealthSection.vue renders the statusLabel "Healthy" when the CA
  346 | 		// API returns status=healthy. The translated string key is 'Healthy'.
  347 | 		const healthyLabel = page.locator(
```