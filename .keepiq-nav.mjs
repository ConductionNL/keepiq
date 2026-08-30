import { chromium } from '@playwright/test'
const BASE='http://localhost:8088'
const b=await chromium.launch(); const c=await b.newContext(); const p=await c.newPage()
await p.goto(`${BASE}/index.php/login`,{waitUntil:'domcontentloaded'})
await p.locator('input[name="user"]').fill('admin')
await p.locator('input[name="password"]').fill('admin')
await p.locator('button[type="submit"]').first().click()
await p.waitForSelector('#header',{timeout:30000})
await p.goto(`${BASE}/index.php/apps/keepiq/`,{waitUntil:'domcontentloaded'})
await p.waitForTimeout(3000)
// unlock with the seeded dev master password
await p.locator('.lock-screen input[type="password"]').first().fill('Oj',{force:true})
await p.waitForTimeout(400)
await p.evaluate(()=>{const bs=[...document.querySelectorAll('.lock-screen button')];const u=bs.find(b=>/Unlock/i.test(b.textContent||''));if(u)u.click()})
await p.waitForTimeout(4000)
console.log('after unlock, lock-screen count:', await p.locator('.lock-screen').count())
console.log('url:', p.url())
// click the Vault nav entry natively and see if the route changes
await p.evaluate(()=>{const as=[...document.querySelectorAll('.app-navigation a')];const v=as.find(a=>/Vault/i.test(a.textContent||''));if(v)v.click()})
await p.waitForTimeout(2500)
console.log('after clicking Vault -> url:', p.url())
console.log('secret-list present:', await p.locator('[data-testid="secret-list-view"], .secret-list-view').count())
await b.close()
