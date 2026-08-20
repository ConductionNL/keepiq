import { chromium } from '@playwright/test'
const BASE = 'http://localhost:8088'
const b = await chromium.launch(); const c = await b.newContext(); const p = await c.newPage()
const bad = []
p.on('response', r => { if (r.status() >= 400) bad.push(`${r.status()} ${r.request().method()} ${r.url()}`) })
await p.goto(`${BASE}/index.php/login`, { waitUntil: 'domcontentloaded' })
await p.locator('input[name="user"]').fill('admin')
await p.locator('input[name="password"]').fill('admin')
await p.locator('button[type="submit"]').first().click()
await p.waitForSelector('#header', { timeout: 30000 })
bad.length = 0
await p.goto(`${BASE}/index.php/apps/doriath/`, { waitUntil: 'domcontentloaded' })
await p.waitForTimeout(6000)
console.log('--- failing requests on app root ---')
bad.forEach(x => console.log(x))
console.log('--- doriath-owned failures:', bad.filter(x => x.includes('/doriath')).length)
await b.close()
