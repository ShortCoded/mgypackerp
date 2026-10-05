import { readFile, mkdir, writeFile } from 'node:fs/promises';
const base = 'http://127.0.0.1:8862';
const debug = 'http://127.0.0.1:9332';
const fixture = JSON.parse(await readFile(process.env.MGYPACK_ISSUE_BROWSER_FIXTURE, 'utf8'));
if (fixture.database !== 'mgypack_wht_race_pg_20261005' || !fixture.username.startsWith('synthetic-closure-')) throw new Error('Synthetic browser fixture guard');
const directory = process.env.MGYPACK_ISSUE_BROWSER_ARTIFACTS;
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
const manifest = { database: fixture.database, started_at: new Date().toISOString(), locales: [], writes: 'Synthetic login/session and locale only; native issue and PDF are read-only', source: fixture.document, errors };
try {
  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable'); await send('Network.clearBrowserCookies');
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
  await navigate('/login');
  await evaluate(`(() => { document.querySelector('#login').value = ${JSON.stringify(fixture.username)}; document.querySelector('#password').value = 'password'; document.querySelector('form.js-auth-form').requestSubmit(); })()`);
  await waitUntil(() => evaluate('!location.pathname.includes("/login")'), 'Synthetic login did not complete');
  const context = await evaluate(`fetch('${base}/admin/operating-context/select', { method:'POST', credentials:'same-origin', headers:{Accept:'application/json','Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''}, body:JSON.stringify(${JSON.stringify(fixture.context)}) }).then(async r => ({ status:r.status, data:await r.json() }))`);
  assert(context.status === 200 && context.data.success, 'Synthetic operating context selection failed');
  for (const locale of ['ar', 'en']) {
    const switched = await evaluate(`fetch('${base}/lang/${locale}', {headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json())`);
    assert(switched.locale === locale, `Locale ${locale}`);
    await navigate(`/admin/inventory/documents/${encodeURIComponent(fixture.document)}`);
    const state = await evaluate(`({lang:document.documentElement.lang,dir:document.documentElement.dir,text:document.querySelector('[data-inventory-lineage]')?.innerText,chain:document.querySelector('[data-inventory-source-chain]')?.innerText})`);
    assert(state.lang === locale && state.dir === (locale === 'ar' ? 'rtl' : 'ltr'), 'Locale direction mismatch');
    assert(state.text && state.text.includes(fixture.item_code) && state.text.includes(fixture.item_name) && state.text.includes(fixture.material_request) && state.text.includes(fixture.run), 'Native item/source labels missing');
    assert(!state.text.includes(fixture.reservation_uuid) && !state.text.includes(fixture.requirement_uuid) && !state.text.includes('inventory.movements.lineage.'), 'Exposed UUID or untranslated reference');
    assert(!state.text.includes('@else') && !state.text.includes(locale === 'ar' ? 'مرجع المصدر غير متاح' : 'Source reference unavailable'), 'Blade directive or incorrect fallback leaked into a complete source row');
    await evaluate(`document.querySelector('[data-inventory-lineage]').scrollIntoView({block:'center'})`);
    await screenshot(`lineage-${locale}`);
    const pdf = await evaluate(`fetch('${base}/admin/inventory/documents/${encodeURIComponent(fixture.document)}/print').then(async r=>{const bytes=new Uint8Array(await r.arrayBuffer());let binary='';for(let i=0;i<bytes.length;i+=32768){binary+=String.fromCharCode(...bytes.subarray(i,i+32768));}return {status:r.status,type:r.headers.get('content-type'),base64:btoa(binary)};})`);
    assert(pdf.status === 200 && pdf.type.includes('application/pdf'), 'Native PDF route failed');
    await writeFile(`${directory}/native-issue-${locale}.pdf`, Buffer.from(pdf.base64,'base64'));
    manifest.locales.push({locale,direction:state.dir,item_source_labels:true,visible_uuids:false,native_pdf:true});
  }
  assert(errors.length === 0, `Browser runtime errors: ${JSON.stringify(errors)}`);
  const logout = await evaluate(`fetch('${base}/logout', {method:'POST', credentials:'same-origin', headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''}}).then(async r=>({status:r.status,data:await r.json()}))`);
  assert(logout.status === 200 && logout.data.success, 'Synthetic browser logout did not complete');
  manifest.synthetic_logout = true;
  manifest.passed = true;
} catch (error) {
  manifest.passed = false; manifest.failure = error.message; await screenshot('failure').catch(() => {}); throw error;
} finally {
  manifest.completed_at = new Date().toISOString();
  await writeFile(`${directory}/manifest.json`, JSON.stringify(manifest, null, 2));
  socket.close();
  await fetch(`${debug}/json/close/${target.id}`).catch(() => {});
}
