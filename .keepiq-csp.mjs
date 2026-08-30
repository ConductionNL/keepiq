import { chromium } from '@playwright/test'
const BASE='http://localhost:8088'
const b=await chromium.launch(); const c=await b.newContext(); const p=await c.newPage()
let csp=null
p.on('response',r=>{ if(r.url().endsWith('/apps/keepiq/') || r.url().includes('/apps/keepiq/#')) { const h=r.headers(); if(h['content-security-policy']) csp=h['content-security-policy'] } })
await p.goto(`${BASE}/index.php/login`,{waitUntil:'domcontentloaded'})
await p.locator('input[name="user"]').fill('admin'); await p.locator('input[name="password"]').fill('admin')
await p.locator('button[type="submit"]').first().click(); await p.waitForSelector('#header',{timeout:30000})
const resp = await p.goto(`${BASE}/index.php/apps/keepiq/`,{waitUntil:'domcontentloaded'})
const h = resp.headers()['content-security-policy'] || '(none)'
console.log('CSP on the keepiq SPA page:')
console.log(h)
console.log()
console.log("contains 'wasm-unsafe-eval':", h.includes('wasm-unsafe-eval'))
console.log("contains 'unsafe-eval':", /(?<!wasm-)unsafe-eval/.test(h))
await b.close()
