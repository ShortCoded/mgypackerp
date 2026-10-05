import { readFile, mkdir, writeFile } from 'node:fs/promises';
const base = 'http://127.0.0.1:8864';
const debug = 'http://127.0.0.1:9334';
const fixture = JSON.parse(await readFile(process.env.MGYPACK_PRODUCTION_BROWSER_FIXTURE, 'utf8'));
if (fixture.database !== 'mgypack_production_evidence_pg_20261005' || !fixture.username.startsWith('synthetic-closure-')) throw new Error('Synthetic browser fixture guard');
const directory = process.env.MGYPACK_PRODUCTION_BROWSER_ARTIFACTS;
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
const assetErrors = [];
socket.addEventListener('message', event => {
  const message = JSON.parse(event.data);
  if (message.id) {
    const p = pending.get(message.id); if (!p) return;
    pending.delete(message.id);
    if (message.error) p.reject(new Error(JSON.stringify(message.error))); else p.resolve(message.result);
  } else if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails?.exception?.description || message.params.exceptionDetails?.text);
  else if (message.method === 'Network.responseReceived' && message.params.response.url.startsWith(base) && message.params.response.status >= 400 && !message.params.response.url.endsWith('/favicon.ico')) { const issue = `${message.params.response.status} ${message.params.response.url}`; if(message.params.response.status===403 && message.params.response.url.endsWith('/storage/pwa/icons/apple-touch-icon-f170f868-2eaf-40dc-a096-c7365d7d7c84.png')) assetErrors.push(issue); else errors.push(issue); }
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
const requests = [];
socket.addEventListener('message', event => { const m = JSON.parse(event.data); if(m.method === 'Network.requestWillBeSent' && m.params.request.method === 'POST' && m.params.request.url.startsWith(base)) requests.push(m.params.request); });
const manifest = {database:fixture.database,started_at:new Date().toISOString(),runs:[],errors,asset_errors:assetErrors,approval:'Explicit synthetic local operational/QA simulation; never customer or live approval',writes:'Native browser forms on isolated actual customer-run clone only',requests:[]};
const fill = async (suffix, values, addWorker = false) => evaluate(`(() => {
  const form = Array.from(document.forms).find(f => new URL(f.action).pathname.endsWith(${JSON.stringify(suffix)}));
  if(!form) throw new Error('Native form missing: '+${JSON.stringify(suffix)});
  for(let parent=form.parentElement;parent;parent=parent.parentElement) if(parent.tagName==='DETAILS') parent.open=true;
  if(${addWorker}) form.querySelector('[data-add-shift-worker]').click();
  const values=${JSON.stringify(values)};
  for(const [name,value] of Object.entries(values)) {
    const field=Array.from(form.elements).find(e=>e.name===name); if(!field) throw new Error('Native field missing: '+name);
    if(field.tagName==='SELECT'&&!Array.from(field.options).some(o=>o.value===String(value))) field.add(new Option(String(value),String(value)));
    field.value=String(value);field.dispatchEvent(new Event('input',{bubbles:true}));field.dispatchEvent(new Event('change',{bubbles:true}));
    if(window.jQuery&&field.tagName==='SELECT') window.jQuery(field).trigger('change');
  }
  const nonce=crypto.randomUUID();window.__gateNonce=nonce;form.requestSubmit();return nonce;
})()`);
const submit = async (suffix, values, addWorker = false) => { const nonce=await fill(suffix,values,addWorker);await waitUntil(()=>evaluate(`window.__gateNonce!==${JSON.stringify(nonce)}`),'Native form did not navigate: '+suffix);await waitUntil(()=>evaluate('document.readyState==="complete"'),'Post navigation');await delay(350);const alert=await evaluate('Array.from(document.querySelectorAll(".alert-danger,.invalid-feedback")).map(e=>e.innerText).filter(Boolean).join(" | ")');assert(!alert,'Native validation: '+alert); };
const localTime = () => evaluate(`new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,19)`);
try {
 await send('Page.enable');await send('Runtime.enable');await send('Network.enable');await send('Network.clearBrowserCookies');await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
 await navigate('/login');await evaluate(`(() => {document.querySelector('#login').value=${JSON.stringify(fixture.username)};document.querySelector('#password').value='password';document.querySelector('form.js-auth-form').requestSubmit();})()`);await waitUntil(()=>evaluate('!location.pathname.includes("/login")'),'Synthetic login');
 for(const run of fixture.runs){
  const context=await evaluate(`fetch('/admin/operating-context/select',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||''},body:JSON.stringify(${JSON.stringify(run.context)})}).then(async r=>({status:r.status,data:await r.json()}))`);assert(context.status===200&&context.data.success,'Actual factory context');
  await evaluate(`fetch('/lang/${run.locale}',{headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json())`);await navigate(run.path);
  const policy={execution_structure:'factory_workflow'};run.roles.forEach((role,index)=>{policy[`stage_roles[${index}][role]`]=role;policy[`stage_roles[${index}][confirmation_reason]`]='SYNTHETIC local browser: explicit original customer checklist review; no customer approval';});run.requirements.forEach((_,index)=>policy[`requirements[${index}][basis]`]=run.kind==='injection'?'measured_material':'output_components');if(!run.resume_mode || run.resume_mode==='legacy') await submit('/output-evidence',policy);
  const crew={shift_code:`SYN-BROWSER-${run.id}`,shift_name:`SYNTHETIC browser shift ${run.id}`,starts_at:'00:00',ends_at:'23:59','crew[0][employee_id]':run.crew[0].id,'crew[0][role]':'technician','crew[0][planned_hours]':'8','crew[1][employee_id]':run.crew[1].id,'crew[1][role]':'supervisor','crew[1][planned_hours]':'8'};if(!run.resume_entry_id) await submit('/shift-defaults',crew,true);
  const shiftId=await evaluate(`Array.from(document.querySelector('[name="production_shift_id"]').options).find(o=>o.textContent.includes('SYN-BROWSER-${run.id}'))?.value`);assert(shiftId,'Saved shift missing');
  const stamp=await localTime();const entry={'production_shift_id':shiftId,work_date:stamp.slice(0,10),started_at:stamp,'sheet_fields[sheet_kind]':run.kind,'sheet_fields[pack_ratio]':run.pack_ratio,'sheet_fields[primary_material_requirement_public_id]':run.requirements[0].public_id,notes:'SYNTHETIC native browser shift on actual customer order/machine; no customer approval','sheet_fields[carton_type]':'SYNTHETIC trial carton','sheet_fields[carton_size]':'Customer frozen pack ratio retained'};
  if(run.kind==='injection') Object.assign(entry,{'sheet_fields[cavities]':'8','sheet_fields[cycle_seconds]':'20','sheet_fields[piece_weight_grams]':'12.65','sheet_fields[average_piece_weight_grams]':'12.65','sheet_fields[bag_type]':'SYNTHETIC trial bag','sheet_fields[bag_size]':'Trial'});else Object.assign(entry,{'sheet_fields[machine_speed]':'1','sheet_fields[cover_components]':'Actual frozen customer components; no film-roll component invented','sheet_fields[product_size]':'SYNTHETIC trial size'});
  if(!run.resume_entry_id) await submit('/shifts',entry);
  const entryId=await evaluate(`document.querySelector('[name="production_shift_entry_id"] option[value]:not([value=""])')?.value`);assert(entryId,'Native shift linkage missing');
  if(run.progress_token) await evaluate(`(() => {const f=Array.from(document.forms).find(f=>new URL(f.action).pathname.endsWith('/progress'));const input=document.createElement('input');input.type='hidden';input.name='_submission_token';input.value=${JSON.stringify(run.progress_token)};f.appendChild(input);})()`);
  const progress={good_base_quantity:run.quantity,production_shift_entry_id:entryId,notes:'SYNTHETIC actual browser output, actual issued material, quantity QA then receipt'};if(run.kind==='injection') run.requirements.forEach((r,index)=>progress[`material_evidence[${index}][measured_quantity]`]=r.planned_quantity);await submit('/progress',progress);
  const outputPost=requests.findLast(r=>r.url.endsWith('/progress'));assert(outputPost?.postData?.includes('_submission_token'),'Native progress idempotency token missing');
  const replay=await evaluate(`fetch(${JSON.stringify(outputPost.url)},{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:${JSON.stringify(outputPost.postData)}}).then(async r=>({status:r.status,type:r.headers.get('content-type'),body:await r.text()}))`);assert(replay.status===200&&replay.body.includes(run.run_number),'Native cached redirect replay failed');
  await navigate('/admin/production/quality/create?run='+run.id);await submit('/quality',{production_run_id:run.id,quality_inspection_type_id:fixture.quality_type_id,affected_base_quantity:run.quantity,source_reference:'SYNTHETIC browser actual output batch',notes:'SYNTHETIC local quantity QA, not customer approval',submit_action:'save_view'});
  assert((await evaluate('location.pathname')).includes('/quality/'),'Native quality document show missing');
  for(const action of ['receive','start']){const nonce=await evaluate(`(() => {const b=document.querySelector('[data-action="post"][data-url$="/${action}"]');if(!b)throw new Error('Native QA action missing ${action}');const nonce=crypto.randomUUID();window.__gateNonce=nonce;b.click();return nonce;})()`);await waitUntil(()=>evaluate(`window.__gateNonce!==${JSON.stringify(nonce)}`),'Native QA action '+action);await waitUntil(()=>evaluate('document.readyState==="complete"'),'QA action load');await delay(350);}
  const qualityPath=await evaluate('location.pathname');await submit('/submit',{result:'passed',disposition:'release',affected_base_quantity:run.quantity,accepted_base_quantity:run.quantity,notes:'SYNTHETIC local browser approval of exactly recorded quantity; not customer signoff'});
  await navigate(run.path);await submit('/receive',{branch_store_id:run.finished_store,base_quantity:run.quantity});
  await submit('/shifts/close',{entry_id:entryId,ended_at:await localTime(),downtime_minutes:'0'});
  if(run.kind==='injection')await submit('/complete',{});
  const states=[];for(const locale of ['ar','en']){await evaluate(`fetch('/lang/${locale}',{headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json())`);await navigate(run.path);const state=await evaluate(`({lang:document.documentElement.lang,dir:document.documentElement.dir,text:document.querySelector('[data-production-shift-report]')?.innerText})`);assert(state.lang===locale&&state.dir===(locale==='ar'?'rtl':'ltr'),'Locale/RTL');assert(state.text?.includes(run.machine)&&state.text.includes(run.crew[0].name)&&!state.text.includes('production_execution.shift_evidence.'),'Shift report machine/crew/translations');await screenshot(`${run.kind}-${locale}`);const pdf=await evaluate(`fetch('${run.path}/shifts/print').then(async r=>{const a=new Uint8Array(await r.arrayBuffer());let b='';for(let i=0;i<a.length;i+=32768)b+=String.fromCharCode(...a.subarray(i,i+32768));return {status:r.status,type:r.headers.get('content-type'),base64:btoa(b)};})`);assert(pdf.status===200&&pdf.type.includes('application/pdf'),'Native report PDF');await writeFile(`${directory}/${run.kind}-${locale}.pdf`,Buffer.from(pdf.base64,'base64'));states.push({locale,direction:state.dir,native_report_pdf:true});}
  manifest.runs.push({id:run.id,kind:run.kind,native_data_entry_locale:run.locale,output:run.quantity,quality_quantity:run.quantity,receipt_quantity:run.quantity,shift_entry_id:entryId,quality_path:qualityPath,duplicate_progress_replay:true,requested_completion:run.kind==='injection'?'completed':'remains running because actual stock shortage limits75/80',report_locales:states});await writeFile(`${directory}/checkpoint.json`,JSON.stringify(manifest,null,2));
 }
 assert(errors.length===0,'Browser errors: '+JSON.stringify(errors));manifest.passed=true;
}catch(error){manifest.passed=false;manifest.failure=error.message;await screenshot('failure').catch(()=>{});throw error;}finally{manifest.completed_at=new Date().toISOString();manifest.requests=requests.map(r=>({url:r.url,method:r.method}));await writeFile(`${directory}/manifest.json`,JSON.stringify(manifest,null,2));socket.close();await fetch(`${debug}/json/close/${target.id}`).catch(()=>{});}
