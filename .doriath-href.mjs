import { chromium } from '@playwright/test'
const BASE='http://localhost:8088'
const b=await chromium.launch(); const c=await b.newContext(); const p=await c.newPage()
await p.goto(`${BASE}/index.php/login`,{waitUntil:'domcontentloaded'})
await p.locator('input[name="user"]').fill('admin')
await p.locator('input[name="password"]').fill('admin')
await p.locator('button[type="submit"]').first().click()
await p.waitForSelector('#header',{timeout:30000})
await p.goto(`${BASE}/index.php/apps/doriath/`,{waitUntil:'domcontentloaded'})
await p.waitForTimeout(4000)
const r = await p.evaluate(() => {
  const nav = document.querySelector('.app-navigation')
  const as = nav ? Array.from(nav.querySelectorAll('a')) : []
  return { navPresent: !!nav, hrefs: as.slice(0,10).map(a => a.getAttribute('href')), texts: as.slice(0,10).map(a=>(a.textContent||'').trim().slice(0,20)) }
})
console.log(JSON.stringify(r,null,1))
await b.close()
