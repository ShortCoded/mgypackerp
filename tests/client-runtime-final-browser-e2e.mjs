const base = (process.env.ERP_BROWSER_BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const debug = (process.env.ERP_BROWSER_DEBUG_URL || 'http://127.0.0.1:9222').replace(/\/$/, '');
const login = process.env.ERP_BROWSER_LOGIN || 'demo.full@shortcoded.test';
const password = process.env.ERP_BROWSER_PASSWORD || 'RuntimeDemo2026!';
const target = await fetch(`${debug}/json/new?${encodeURIComponent(`${base}/login`)}`, { method: 'PUT' }).then(r => r.json());
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => { ws.addEventListener('open', resolve, { once: true }); ws.addEventListener('error', reject, { once: true }); });
let id = 0;
const pending = new Map();
ws.addEventListener('message', event => { const message = JSON.parse(event.data); if (message.id && pending.has(message.id)) { const call = pending.get(message.id); pending.delete(message.id); message.error ? call.reject(new Error(message.error.message)) : call.resolve(message.result || {}); } });
const send = (method, params = {}) => new Promise((resolve, reject) => { const callId = ++id; pending.set(callId, { resolve, reject }); ws.send(JSON.stringify({ id: callId, method, params })); });
const evaluate = async expression => { const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, userGesture: true }); if (result.exceptionDetails) throw new Error(result.exceptionDetails.text); return result.result?.value; };
const wait = async (check, label) => { const end = Date.now() + 30000; while (Date.now() < end) { try { if (await check()) return; } catch {} await new Promise(r => setTimeout(r, 150)); } throw new Error(label); };
const navigate = async path => { await send('Page.navigate', { url: `${base}${path}` }); await wait(() => evaluate(`document.readyState === 'complete'`), `Navigation failed: ${path}`); };
await Promise.all([send('Page.enable'), send('Runtime.enable'), send('Network.enable')]);
await navigate('/login');
if (!(await evaluate(`Boolean(document.querySelector('[name="login"]'))`))) {
  const logout = await evaluate(`(async()=>{const headers={Accept:'application/json','X-Requested-With':'XMLHttpRequest'};const csrf=await fetch('/auth/csrf-token',{headers}).then(r=>r.json());const response=await fetch('/logout',{method:'POST',headers:{...headers,'X-CSRF-TOKEN':csrf.csrf_token}});return response.status;})()`);
  if (logout < 200 || logout >= 400) throw new Error(`Existing browser session logout failed: ${logout}`);
  await navigate('/login');
}
if (await evaluate(`Boolean(document.querySelector('[name="login"]'))`)) {
  await evaluate(`(() => { const set=(s,v)=>{const e=document.querySelector(s);e.value=v;e.dispatchEvent(new Event('input',{bubbles:true}));}; set('[name="login"]',${JSON.stringify(login)});set('[name="password"]',${JSON.stringify(password)});document.querySelector('form').requestSubmit(); })()`);
  try {
    await wait(() => evaluate(`!location.pathname.includes('/login')`), 'Login failed');
  } catch (error) {
    const loginState = await evaluate(`({url:location.href,text:document.body.innerText.slice(0,1000)})`);
    throw new Error(`${error.message}: ${JSON.stringify(loginState)}`);
  }
}
const options = await evaluate(`fetch('/admin/operating-context/options',{headers:{Accept:'application/json'}}).then(r=>r.json())`);
const company = options.data?.companies?.[0];
if (!company) throw new Error('No authorized company context.');
const selected = await evaluate(`(async()=>{const headers={Accept:'application/json','X-Requested-With':'XMLHttpRequest'};const first=await fetch('/admin/operating-context/options',{headers}).then(r=>r.json());const c=first.data.companies[0];const scoped=await fetch('/admin/operating-context/options?company_doc_num='+encodeURIComponent(c.doc_num),{headers}).then(r=>r.json());const b=scoped.data.branches[0],p=scoped.data.financial_periods[0];const csrf=await fetch('/auth/csrf-token',{headers}).then(r=>r.json());const response=await fetch('/admin/operating-context/select',{method:'POST',headers:{...headers,'Content-Type':'application/json','X-CSRF-TOKEN':csrf.csrf_token},body:JSON.stringify({company_doc_num:c.doc_num,branch_doc_num:b.doc_num,financial_period_doc_num:p.doc_num})});return {status:response.status,body:await response.text()};})()`);
if (selected.status >= 300) throw new Error(`Context selection failed: ${selected.status}`);
await navigate('/lang/ar');
await navigate('/dashboard');
const localeState = await evaluate(`({lang:document.documentElement.lang,dir:document.documentElement.dir})`);
if (localeState.lang !== 'ar' || localeState.dir !== 'rtl') throw new Error(`Arabic RTL locale failed: ${JSON.stringify(localeState)}`);

const select2Paths = [
  '/admin/accounting/journal-entries/select2/accounts?report_scope=1',
  '/admin/accounting/journal-entries/select2/customers',
  '/admin/accounting/journal-entries/select2/suppliers',
  '/admin/accounting/journal-entries/select2/cost-centers',
  '/admin/finance/select2/cashboxes',
  '/admin/finance/select2/bank-accounts',
];
const select2 = await evaluate(`(async()=>{const paths=${JSON.stringify(select2Paths)};const output=[];for(const path of paths){const firstUrl=path+(path.includes('?')?'&':'?')+'page=1&per_page=1';const firstResponse=await fetch(firstUrl,{headers:{Accept:'application/json'}});const first=await firstResponse.json();const query=first.results?.[0]?.text||'';const searchUrl=path+(path.includes('?')?'&':'?')+'page=1&per_page=1&q='+encodeURIComponent(query);const pageTwoUrl=path+(path.includes('?')?'&':'?')+'page=2&per_page=1';const searchedResponse=await fetch(searchUrl,{headers:{Accept:'application/json'}});const pageTwoResponse=await fetch(pageTwoUrl,{headers:{Accept:'application/json'}});const searched=await searchedResponse.json();const pageTwo=await pageTwoResponse.json();output.push({path,status:firstResponse.status,first,searchStatus:searchedResponse.status,searched,pageTwoStatus:pageTwoResponse.status,pageTwo});}return output;})()`);
for (const probe of select2) {
  if (probe.status !== 200 || probe.searchStatus !== 200 || probe.pageTwoStatus !== 200 || !Array.isArray(probe.first.results) || typeof probe.first.pagination?.more !== 'boolean' || !Array.isArray(probe.searched.results) || !Array.isArray(probe.pageTwo.results)) {
    throw new Error(`Select2 contract failed: ${JSON.stringify(probe)}`);
  }
}

const pagePaths = [
  '/admin/accounting/accounts',
  '/admin/accounting/cost-centers',
  '/admin/accounting/journal-entries',
  '/admin/finance/opening-balances',
  '/admin/finance/bank-accounts',
  '/admin/finance/cashboxes',
  '/admin/finance/cash-receipt-vouchers',
  '/admin/finance/cash-payment-vouchers',
  '/admin/finance/cheques',
  '/admin/finance/fund-transfers',
  '/admin/finance/cashbox-count',
  '/admin/accounting/reports/general-journal',
  '/admin/accounting/reports/account-ledger',
  '/admin/accounting/reports/customer-statement',
  '/admin/accounting/reports/supplier-statement',
  '/admin/accounting/reports/trial-balance',
  '/admin/accounting/reports/financial-statements',
  '/admin/accounting/reports/reconciliation-center',
  '/admin/accounting/reports/financial-analytics/expense-analysis',
  '/admin/accounting/reports/financial-analytics/financial-ratios',
  ...['cashbox-balances','cashbox-statement','cash-vouchers','bank-account-balances','bank-account-statement','bank-reconciliation','treasury-transfers','received-cheques','issued-cheques','cleared-cheques','returned-cheques','cancelled-cheques','cheque-transit','advances-allocations','unapproved-documents','customer-aging','supplier-aging'].map(name=>'/admin/reports/finance/'+name),
  ...['product-cost','work-order-cost','estimated-vs-actual','cost-variance','profitability','work-in-progress','finished-goods-cost','allocation-analysis'].map(name=>'/admin/reports/costing/'+name),
];
const pageSmoke = await evaluate(`(async()=>{const paths=${JSON.stringify(pagePaths)};const output=[];for(const path of paths){const response=await fetch(path,{headers:{Accept:'text/html'}});const body=await response.text();output.push({path,status:response.status,serverError:/Server Error|Internal Server Error|Whoops/i.test(body),rawKey:/(?:ledger_reports|finance_reports|financial_analytics)\\.(?:sources|statuses|values)\\.[a-z0-9_.-]+/i.test(body)});}return output;})()`);
const failedPages = pageSmoke.filter(page => page.status !== 200 || page.serverError || page.rawKey);
if (failedPages.length) throw new Error(`Accounting/Finance/Costing page smoke failed: ${JSON.stringify(failedPages)}`);

const shellQueries = ['إعدادات التكاليف','التكلفة المعيارية للخامات','تسوية البنك','إعدادات تشغيل الشركة','تعريفات الضرائب'];
const shellSearch = await evaluate(`Promise.all(${JSON.stringify(shellQueries)}.map(async query=>{const response=await fetch('/admin/navigation-search?q='+encodeURIComponent(query),{headers:{Accept:'application/json'}});const body=await response.json();return {query,status:response.status,results:body.data?.results||[]};}))`);
if (shellSearch.some(result => result.status !== 200 || result.results.length !== 0)) throw new Error(`Hidden shell leaked through navigation search: ${JSON.stringify(shellSearch)}`);

await navigate('/dashboard');
const dashboard = await evaluate(`({status:document.body.innerText.length,shells:['إعدادات التكاليف','التكلفة المعيارية للخامات','إعدادات تشغيل الشركة'].filter(x=>document.body.innerText.includes(x)),summaries:Boolean(document.querySelector('[data-dashboard-summaries]'))})`);
if (dashboard.shells.length) throw new Error(`Dashboard/menu shell leak: ${JSON.stringify(dashboard)}`);
const summaryStatuses = await evaluate(`Promise.all(['/dashboard/summaries/sales','/dashboard/summaries/purchases'].map(u=>fetch(u,{headers:{Accept:'application/json'}}).then(async r=>({u,status:r.status,body:await r.json()}))))`);
if (summaryStatuses.some(x => ![200,403].includes(x.status) || (x.status === 200 && !Array.isArray(x.body.metrics)))) throw new Error(`Dashboard summaries failed: ${JSON.stringify(summaryStatuses)}`);
await navigate('/admin/sales/price-lists');
let showPath = null;
const priceListDeadline = Date.now() + 5000;
while (!showPath && Date.now() < priceListDeadline) {
  showPath = await evaluate(`[...document.querySelectorAll('a[href*="/admin/sales/price-lists/"]')].map(a=>new URL(a.href).pathname).find(p=>!['create','pdf','export','history','edit'].some(x=>p.includes(x))) || null`);
  if (!showPath) await new Promise(resolve => setTimeout(resolve, 200));
}
if (!showPath) {
  showPath = await evaluate(`fetch('/admin/sales/select2/price-lists',{headers:{Accept:'application/json'}}).then(async r=>{if(!r.ok)return null;const rows=await r.json();return rows[0]?.doc_num?'/admin/sales/price-lists/'+encodeURIComponent(rows[0].doc_num):null;})`);
}
if (!showPath) {
  for (const candidate of options.data?.companies || []) {
    const candidateResult = await evaluate(`(async()=>{const headers={Accept:'application/json','X-Requested-With':'XMLHttpRequest'};const scoped=await fetch('/admin/operating-context/options?company_doc_num='+encodeURIComponent(${JSON.stringify(candidate.doc_num)}),{headers}).then(r=>r.json());const b=scoped.data?.branches?.[0],p=scoped.data?.financial_periods?.[0];if(!b||!p)return null;const csrf=await fetch('/auth/csrf-token',{headers}).then(r=>r.json());const response=await fetch('/admin/operating-context/select',{method:'POST',headers:{...headers,'Content-Type':'application/json','X-CSRF-TOKEN':csrf.csrf_token},body:JSON.stringify({company_doc_num:${JSON.stringify(candidate.doc_num)},branch_doc_num:b.doc_num,financial_period_doc_num:p.doc_num})});if(!response.ok)return null;const lists=await fetch('/admin/sales/select2/price-lists',{headers}).then(async r=>r.ok?r.json():[]);return lists[0]?.doc_num?'/admin/sales/price-lists/'+encodeURIComponent(lists[0].doc_num):null;})()`);
    if (candidateResult) {
      showPath = candidateResult;
      break;
    }
  }
}
let priceList = { skipped: true, reason: 'No persisted price list row available' };
if (showPath) {
  await navigate(showPath);
  priceList = await evaluate(`(()=>{const text=document.body.innerText;const pdf=[...document.querySelectorAll('a')].find(a=>a.textContent.trim()==='PDF');const print=[...document.querySelectorAll('a,button')].find(a=>['Print','طباعة'].includes(a.textContent.trim()));return {pdf:pdf?.getAttribute('href')||null,print:Boolean(print),windowPrint:document.documentElement.innerHTML.includes('window.print')};})()`);
  if (!priceList.pdf || priceList.print || priceList.windowPrint) throw new Error(`Price List output UI invalid: ${JSON.stringify(priceList)}`);
  priceList.pdfResponse = await evaluate(`fetch(${JSON.stringify(priceList.pdf)},{credentials:'same-origin'}).then(async r=>{const b=new Uint8Array(await r.arrayBuffer());return {status:r.status,type:r.headers.get('content-type'),signature:String.fromCharCode(...b.slice(0,4))};})`);
  if (priceList.pdfResponse.status !== 200 || priceList.pdfResponse.signature !== '%PDF') throw new Error(`Price List PDF failed: ${JSON.stringify(priceList.pdfResponse)}`);
}
await navigate('/admin/inventory/sales-valuation');
const inventory = await evaluate(`({path:location.pathname,title:document.title,hasPriceList:Boolean(document.querySelector('[name="price_list_id"]')),hasError:document.body.innerText.includes('Server Error')})`);
if (!inventory.hasPriceList || inventory.hasError) throw new Error(`Inventory Sales Valuation failed: ${JSON.stringify(inventory)}`);
console.log(JSON.stringify({ ok: true, localeState, select2: select2.map(x=>({path:x.path,first:x.first.results.length,more:x.first.pagination.more,searched:x.searched.results.length,pageTwo:x.pageTwo.results.length})), pageSmoke:{checked:pageSmoke.length}, shellSearch, dashboard, summaryStatuses: summaryStatuses.map(x=>({url:x.u,status:x.status,metrics:Array.isArray(x.body.metrics)?x.body.metrics.length:0})), priceList, inventory }, null, 2));
ws.close();
