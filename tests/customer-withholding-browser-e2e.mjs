import { readFile, mkdir, writeFile } from 'node:fs/promises';
const base = 'http://127.0.0.1:8862';
const debug = 'http://127.0.0.1:9332';
const fixture = JSON.parse(await readFile(process.env.MGYPACK_WHT_BROWSER_FIXTURE, 'utf8'));
if (fixture.database !== 'mgypack_wht_race_pg_20261005' || !fixture.username.startsWith('synthetic-closure-')) throw new Error('Synthetic browser fixture guard');
const directory = process.env.MGYPACK_WHT_BROWSER_ARTIFACTS;
if (!directory) throw new Error('Browser artifact directory required');
await mkdir(directory, { recursive: true });
const delay = (ms) => new Promise(resolve => setTimeout(resolve, ms));
const assert = (value, message) => { if (!value) throw new Error(message); };
const target = await fetch(`${debug}/json/new?${encodeURIComponent(base + '/login')}`, { method: 'PUT' }).then(r => r.json());
const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
let counter = 0;
const pending = new Map();
const errors = [];
socket.addEventListener('message', event => {
  const message = JSON.parse(event.data);
  if (message.id) {
    const p = pending.get(message.id); if (!p) return;
    pending.delete(message.id);
    if (message.error) p.reject(new Error(JSON.stringify(message.error))); else p.resolve(message.result);
  } else if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails?.exception?.description || message.params.exceptionDetails?.text);
  else if (message.method === 'Network.responseReceived' && message.params.response.url.startsWith(base) && message.params.response.status >= 400 && !message.params.response.url.endsWith('/favicon.ico')) errors.push(`${message.params.response.status} ${message.params.response.url}`);
});
const send = (method, params = {}) => new Promise((resolve, reject) => { const id = ++counter; pending.set(id, { resolve, reject }); socket.send(JSON.stringify({ id, method, params })); });
const evaluate = async expression => {
  const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
  return result.result.value;
};
const waitUntil = async (predicate, message) => { const until = Date.now() + 20000; while (Date.now() < until) { if (await predicate()) return; await delay(100); } throw new Error(message); };
const navigate = async path => { await send('Page.navigate', { url: base + path }); await waitUntil(() => evaluate('document.readyState === "complete"'), `Navigation ${path}`); await delay(500); };
const screenshot = async name => { const result = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true }); await writeFile(`${directory}/${name}.png`, Buffer.from(result.data, 'base64')); };
const manifest = { database: fixture.database, started_at: new Date().toISOString(), locales: [], writes: 'Synthetic login/session and locale only; no financial submission', source: fixture.invoice, errors };
try {
  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
  await navigate('/login');
  await evaluate(`(() => { document.querySelector('#login').value = ${JSON.stringify(fixture.username)}; document.querySelector('#password').value = 'password'; document.querySelector('form.js-auth-form').requestSubmit(); })()`);
  await waitUntil(() => evaluate('!location.pathname.includes("/login")'), 'Synthetic login did not complete');
  const context = await evaluate(`fetch('${base}/admin/operating-context/select', { method:'POST', credentials:'same-origin', headers:{Accept:'application/json','Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''}, body:JSON.stringify(${JSON.stringify(fixture.context)}) }).then(async r => ({ status:r.status, data:await r.json() }))`);
  assert(context.status === 200 && context.data.success, 'Synthetic operating context selection failed');
  for (const locale of ['ar', 'en']) {
    const switched = await evaluate(`fetch('${base}/lang/${locale}', {headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json())`);
    assert(switched.locale === locale, `Locale ${locale}`);
    await navigate(`/admin/sales/sales-invoices/${encodeURIComponent(fixture.invoice)}/withholding?payment_schedule_id=${fixture.schedule_id}&customer_receipt_id=${fixture.receipt_id}`);
    const state = await evaluate(`({lang:document.documentElement.lang, dir:document.documentElement.dir, title:document.querySelector('.card-header h5')?.innerText, source:!!document.querySelector('[name="source_fingerprint"]'), picker:!!document.querySelector('[data-picker-max="1"]'), text:document.body.innerText})`);
    assert(state.lang === locale && state.dir === (locale === 'ar' ? 'rtl' : 'ltr'), 'Locale direction mismatch');
    assert(state.source && state.picker && !state.text.includes('sales_ui.wht.'), 'Certificate source/picker/localization not usable');
    await evaluate(`jQuery(document.querySelector('.js-sales-attachment-picker')).trigger('file-picker:selected', [{file:{public_id:${JSON.stringify(fixture.certificate)}, original_name:'SYNTHETIC retained certificate.pdf'}}])`);
    assert(await evaluate(`document.querySelectorAll('form [name="attachment_doc_nums[]"]').length === 1`), 'Native picker event did not attach one certificate');
    await screenshot(`certificate-${locale}`);
    await navigate('/admin/sales/customer-withholding-settlements');
    assert(await evaluate(`document.body.innerText.includes('SYNTHETIC-CERT-1')`), 'Scoped certificate registry entry missing');
    await navigate('/admin/sales/sales-invoices/create?direct=1');
    await waitUntil(() => evaluate('!!window.AppSalesDiscounts && !!window.AppNumbers'), 'Commercial preview scripts missing');
    const preview = await evaluate(`(() => {
      const basis = document.querySelector('.js-sales-withholding-basis');
      if (!basis || basis.value !== 'eta_t4_net_excluding_tax') return { valid:false };
      for (const [selector,value] of [['.js-sales-quantity','1'],['.js-sales-price','1000'],['.js-sales-tax','140'],['.js-sales-withholding-rate','1']]) { const input=document.querySelector(selector); if (!input) return { valid:false, missing:selector }; input.value=value; input.dispatchEvent(new Event('input',{bubbles:true})); }
      return { valid:true, amount:document.querySelector('[data-sales-withholding-amount]')?.textContent, net:document.querySelector('[data-sales-net-payable]')?.textContent, expected:window.AppNumbers.format('10'), expectedNet:window.AppNumbers.format('1130') };
    })()`);
    assert(preview.valid && preview.amount === preview.expected && preview.net === preview.expectedNet, `Live commercial preview did not exclude VAT: ${JSON.stringify(preview)}`);
    await screenshot(`net-preview-${locale}`);
    manifest.locales.push({locale, direction:state.dir, certificate_source_review:true, picker_event_single_file:true, registry:true, net_preview:preview});
  }
  assert(errors.length === 0, `Browser runtime errors: ${JSON.stringify(errors)}`);
  manifest.passed = true;
} catch (error) {
  manifest.passed = false; manifest.failure = error.message; await screenshot('failure').catch(() => {}); throw error;
} finally {
  manifest.completed_at = new Date().toISOString();
  await writeFile(`${directory}/manifest.json`, JSON.stringify(manifest, null, 2));
  socket.close();
  await fetch(`${debug}/json/close/${target.id}`).catch(() => {});
}
