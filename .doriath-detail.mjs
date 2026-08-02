import { chromium } from '@playwright/test'
const BASE='http://localhost:8088'
const b=await chromium.launch(); const c=await b.newContext(); const p=await c.newPage()
const errs=[]; p.on('pageerror',e=>errs.push(String(e).slice(0,200)))
p.on('console',m=>{if(m.type()==='error')errs.push('c:'+m.text().slice(0,160))})
await p.goto(`${BASE}/index.php/login`,{waitUntil:'domcontentloaded'})
await p.locator('input[name="user"]').fill('admin'); await p.locator('input[name="password"]').fill('admin')
await p.locator('button[type="submit"]').first().click(); await p.waitForSelector('#header',{timeout:30000})
await p.goto(`${BASE}/index.php/apps/doriath/`,{waitUntil:'domcontentloaded'}); await p.waitForTimeout(3000)
await p.locator('.lock-screen input[type="password"]').first().fill('Oj',{force:true}); await p.waitForTimeout(400)
await p.evaluate(()=>{const bs=[...document.querySelectorAll('.lock-screen button')];const u=bs.find(b=>/Unlock/i.test(b.textContent||''));if(u)u.click()})
await p.waitForTimeout(4000)
await p.evaluate(()=>{const as=[...document.querySelectorAll('.app-navigation a')];const v=as.find(a=>/Vault/i.test(a.textContent||''));if(v)v.click()})
await p.waitForTimeout(3000)
errs.length=0
const rows = await p.locator('.secret-list-item, [data-testid="secret-row"]').count()
console.log('secret rows:', rows)
await p.evaluate(()=>{const r=document.querySelector('.secret-list-item');if(r)r.click()})
await p.waitForTimeout(3500)
console.log('url:',p.url())
const probe = await p.evaluate(()=>({
  detail: !!document.querySelector('.secret-detail'),
  pwField: document.querySelectorAll('.secret-detail .doriath-password-field').length,
  pwInput: document.querySelectorAll('.secret-detail .doriath-password-field input').length,
  anyInput: document.querySelectorAll('.secret-detail input').length,
  detailText: (document.querySelector('.secret-detail')?.innerText||'').slice(0,200)
}))
console.log(JSON.stringify(probe,null,1))
console.log('errors:', errs.slice(0,5))
await b.close()
