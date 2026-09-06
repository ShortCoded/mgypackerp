const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');

test('legacy and chart-resolved asset prerequisites work through authenticated screens',{skip:process.env.FA_BROWSER_REVIEW!=='1',timeout:300000},async()=>{
 const {chromium}=require('playwright');
 const base=process.env.FA_BROWSER_URL;
 assert.equal(base,'http://localhost:8011');
 const fixture=JSON.parse(fs.readFileSync('/tmp/fa-release/prerequisites-browser-fixtures.json','utf8'));
 const output='/tmp/fa-release/prerequisites-visual';fs.mkdirSync(output,{recursive:true});
 const browser=await chromium.launch({executablePath:'/usr/bin/google-chrome',headless:true,args:['--no-sandbox']});
 const context=await browser.newContext({storageState:'/tmp/fa-release/browser-state.json',viewport:{width:1440,height:1000}});
 const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const resultsFile=path.join(output,'results.json');const results=fs.existsSync(resultsFile)?JSON.parse(fs.readFileSync(resultsFile,'utf8')):{};
 const save=()=>fs.writeFileSync(resultsFile,JSON.stringify(results,null,2));
 const go=async url=>{const r=await page.goto(base+url);assert.equal(r.status(),200);await page.waitForLoadState('networkidle');assert.ok(!page.url().includes('/login'));};
 const capture=async name=>{assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false);await page.screenshot({path:path.join(output,name+'.png')});};
 const select=async(id,query,label)=>{await page.locator('#select2-'+id+'-container').click();await page.getByRole('searchbox',{name:'Search',exact:true}).fill(query);await page.getByRole('option',{name:label}).click();await page.waitForTimeout(600);};
 const switchCompany=async(company,branch,period)=>{
  await go('/dashboard');await page.waitForTimeout(1000);
  if(!await page.locator('#operatingContextModal').isVisible())await page.locator('[data-operating-context-trigger]').first().click();
  await page.locator('#operatingContextModal').waitFor({state:'visible'});
  await select('operating-context-company',company,new RegExp(company));
  await select('operating-context-branch',branch,new RegExp(branch));
  await select('operating-context-financial-period',period,new RegExp(period));
  await page.locator('#operating-context-form button[type=submit]').click();
  await page.locator('#operatingContextModal').waitFor({state:'hidden'});await page.waitForTimeout(1500);await page.waitForLoadState('networkidle');
 };
 const preview=async(doc,date='31/01/2026')=>{
  await go('/admin/fixed-assets/depreciation?asset_doc_nums[]='+doc);
  await page.locator('input[name="posting_date"]').fill(date);
  await page.locator('button[type=submit]').filter({hasText:/معاينة|Preview/}).first().click();
  await page.waitForLoadState('networkidle');
 };
 try{
  await go('/lang/ar');
  await switchCompany('Short Coded','Main Branch','2026');
  await go('/admin/fixed-assets/assets/FA-00003');
  assert.match(await page.locator('.fixed-asset-360').innerText(),/أصل مثبت قبل/);
  assert.equal(await page.locator('#recognition-modal').count(),0);await capture('ar-legacy-show');
  await preview('FA-00003');assert.equal(await page.locator('[data-asset="FA-00003"]').count(),1);await capture('ar-legacy-preview');
  await preview('FA-00007');assert.match(await page.locator('main').innerText(),/غير قابل للإهلاك/);assert.doesNotMatch(await page.locator('main').innerText(),/اعتمد أو اربط إثبات/);
  await preview('FA-00006');assert.match(await page.locator('main').innerText(),/مهلك بالكامل/);await capture('ar-fully-depreciated-reason');
  await switchCompany('FA Classified Chart Acceptance','FA Acceptance Branch','2026');
  for(const type of ['new_asset','opening_asset']){
   if(!results[type]){
    await go('/admin/fixed-assets/assets/create');
    await page.locator('#entry_type').selectOption(type);
    await page.locator('#asset_name').fill('Prerequisites '+type+' '+Date.now());
    await page.locator('#asset_date').fill('01/01/2026');
    await page.locator('#purchase_date').fill(type==='opening_asset'?'01/01/2025':'01/01/2026');
    await page.locator('#operation_date').fill(type==='opening_asset'?'01/01/2025':'01/01/2026');
    await select('asset_group_account_doc_num','Release Acceptance',fixture.category_label);
    await select('credit_account_doc_num',type==='opening_asset'?'رأس المال':'1111901',type==='opening_asset'?fixture.equity_label:fixture.cash_label);
    await page.getByRole('tab',{name:'البيانات المالية',exact:true}).click();
    await page.locator('#purchase_value').fill('10000');await page.locator('#salvage_value').fill('0');await page.locator('#useful_life').fill('5');
    await page.locator('#depreciation_start_date').fill(type==='opening_asset'?'01/01/2025':'01/01/2026');
    if(type==='opening_asset'){
     await page.locator('#previous_depreciation').fill('2000');await page.locator('#previous_depreciation_until_date').fill('31/12/2025');
    }else assert.equal(await page.locator('#previous_depreciation').isVisible(),false);
    await page.getByRole('tab',{name:'الموقع والملاحظات',exact:true}).click();
    await select('branch_doc_num','FA Acceptance',/FA Acceptance Branch/);
    await page.locator('#description').fill('Authenticated acceptance without accounting overrides.');
    await page.getByRole('button',{name:'حفظ البيانات',exact:true}).first().click();
    await page.waitForURL(/assets\/FA-\d+$/);
    results[type]=page.url().match(/FA-\d+/)[0];save();
   }
   await go('/admin/fixed-assets/assets/'+results[type]);
   if(await page.locator('#recognition-modal').count()){
    await preview(results[type]);assert.equal(await page.locator('[data-asset="'+results[type]+'"]').count(),0);assert.match(await page.locator('main').innerText(),/مسودة/);
    await go('/admin/fixed-assets/assets/'+results[type]);await page.locator('[data-bs-target="#recognition-modal"]').click();
    await page.locator('#recognition-modal [name="activation_date"]').fill('01/01/2026');
    await page.locator('#recognition-modal button[type=submit]').click();await page.locator('#recognition-modal').waitFor({state:'detached'});
   }
   assert.doesNotMatch(await page.locator('.fixed-asset-360').innerText(),/أصل مثبت قبل/);
   await page.getByRole('tab',{name:'البيانات المالية',exact:true}).click();assert.equal(await page.locator('#asset-financial .alert-warning').count(),0);await capture('ar-'+type+'-show');
   if(type==='opening_asset'||!results.newDepreciation){
    await preview(results[type]);assert.equal(await page.locator('[data-asset="'+results[type]+'"]').count(),1);
    if(type==='opening_asset')assert.match(await page.locator('[data-asset="'+results[type]+'"]').innerText(),/2,000/);
    await capture('ar-'+type+'-preview');
    if(type==='new_asset'){
     await page.getByRole('button',{name:'ترحيل الإهلاك',exact:true}).click();await page.waitForURL(/depreciation\/runs\//);results.newDepreciation=page.url();save();
    }
   }
  }
  await go('/admin/fixed-assets/assets/'+results.new_asset);await page.locator('[data-bs-target="#disposal-modal"]').click();
  await page.locator('#disposal-modal [name="disposal_date"]').fill('01/02/2026');await page.locator('#disposal-modal [name="disposition_type"]').selectOption('write_off');
  await page.locator('#disposal-modal [name="reason"]').fill('Missing disposal account is checked at disposal only.');
  await page.getByRole('button',{name:'إعادة حساب المعاينة',exact:true}).click();
  await page.getByText(/الحساب المطلوب غير متاح.*خسائر/).waitFor();await capture('ar-disposal-only-account-required');
  for(const locale of ['ar','en']){
   await go('/lang/'+locale);
   for(const width of [1440,390]){
    await page.setViewportSize({width,height:width===390?844:1000});
    await go('/admin/fixed-assets/assets/'+results.opening_asset);await capture(locale+'-'+width+'-opening-show');
    await preview(results.opening_asset);await capture(locale+'-'+width+'-opening-preview');
   }
  }
  assert.deepEqual(errors,[]);results.errors=errors;results.company=fixture.company;save();console.log(JSON.stringify(results));
 }catch(e){console.error('URL',page.url());console.error((await page.locator('body').innerText()).slice(-3000));await page.screenshot({path:path.join(output,'failure.png')});throw e;
 }finally{await context.storageState({path:'/tmp/fa-release/browser-state.json'});fs.chmodSync('/tmp/fa-release/browser-state.json',0o600);await browser.close();}
});
