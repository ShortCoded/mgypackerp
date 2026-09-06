const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');

test('new depreciable asset is created recognized previewed and posted through the browser',{skip:process.env.FA_BROWSER_REVIEW!=='1',timeout:300000},async()=>{
 const {chromium}=require('playwright');
 const base=process.env.FA_BROWSER_URL;
 assert.equal(base,'http://localhost:8011');
 const fixture=JSON.parse(fs.readFileSync('/tmp/fa-release/prerequisites-browser-fixtures.json','utf8'));
 const output='/tmp/fa-release/usability-visual';fs.mkdirSync(output,{recursive:true});
 const browser=await chromium.launch({executablePath:'/usr/bin/google-chrome',headless:true,args:['--no-sandbox']});
 const context=await browser.newContext({storageState:'/tmp/fa-release/browser-state.json',viewport:{width:1440,height:1000}});
 const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const resultsFile=path.join(output,'results.json');const results=fs.existsSync(resultsFile)?JSON.parse(fs.readFileSync(resultsFile,'utf8')):{};
 const save=()=>fs.writeFileSync(resultsFile,JSON.stringify(results,null,2));
 const go=async url=>{const r=await page.goto(base+url);assert.equal(r.status(),200);await page.waitForLoadState('networkidle');assert.ok(!page.url().includes('/login'));};
 const capture=async name=>{assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+2),false);await page.screenshot({path:path.join(output,name+'.png')});};
 const select=async(id,query,label)=>{await page.locator('#select2-'+id+'-container').click();await page.getByRole('searchbox',{name:/^(Search|بحث)$/}).fill(query);await page.getByRole('option',{name:label}).click();await page.waitForTimeout(600);};
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
  await switchCompany('FA Classified Chart Acceptance','FA Acceptance Branch','2026');
  for(const type of ['new_asset']){
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
    await preview(results[type]);assert.equal(await page.locator('[data-asset="'+results[type]+'"]').count(),0);assert.match(await page.locator('main').innerText(),/الأصل لم يتم إثباته بعد/);
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
  await go('/admin/fixed-assets/assets/'+results.new_asset);
  for(const tab of ['المستندات والقيود','سجل التتبع']) {
    await page.getByRole('tab',{name:tab,exact:true}).click();await page.waitForTimeout(300);
    assert.doesNotMatch(await page.locator('.tab-pane.active').innerText(),/fixed_assets\.[a-z_]+\.success/);
    assert.equal(await page.locator('.tab-pane.active').evaluate(el=>getComputedStyle(el).paddingTop),'0px');
    await capture(tab==='سجل التتبع'?'audit-after':'documents-after');
  }
  await switchCompany('Short Coded','Main Branch','2026');
  await go('/admin/fixed-assets/assets');
  assert.match(await page.locator('main').innerText(),/ابدأ من هنا: أضف الأصل/);
  await capture('ar-asset-register-desktop');
  await go('/admin/fixed-assets/movements');
  const movementText=await page.locator('main').innerText();
  assert.match(movementText,/سجل واحد يوضح كل ما حدث للأصل/);
  assert.doesNotMatch(movementText,/pagination\.(previous|next)/);
  assert.equal(await page.locator('nav[aria-label="صفحات حركات الأصول"] svg').count(),0);
  assert.equal(await page.locator('article').count()>0,true);
  await capture('ar-movements-desktop');
  await page.setViewportSize({width:390,height:844});
  await page.reload();await page.waitForLoadState('networkidle');
  assert.equal(await page.locator('.d-none.d-lg-block').first().isHidden(),true);
  assert.equal(await page.locator('article').first().isVisible(),true);
  await capture('ar-movements-mobile');
  await page.locator('article').first().scrollIntoViewIfNeeded();
  await capture('ar-movements-mobile-history');
  await go('/admin/fixed-assets/assets');
  assert.match(await page.locator('main').innerText(),/ابدأ من هنا: أضف الأصل/);
  await capture('ar-asset-register-mobile');
  await go('/admin/fixed-assets/reports?type=net_book_value');
  assert.equal(await page.locator('#fixed-asset-report-type option[value="transfers"]').count(),1);
  assert.equal(await page.locator('#fixed-asset-report-type option[value="custody"]').count(),1);
  assert.equal(await page.locator('#fixed-asset-report-type option[value="fully_depreciated"]').count(),1);
  assert.equal(await page.locator('.fa-report-mobile-row').first().isVisible(),true);
  assert.equal(await page.locator('.table-responsive.d-none.d-md-block').first().isHidden(),true);
  assert.doesNotMatch(await page.locator('main').innerText(),/fixed_assets\.[a-z_.]+/);
  await capture('ar-reports-mobile');
  await go('/admin/fixed-assets/depreciation');
  assert.match(await page.locator('main').innerText(),/آخر تشغيلات الإهلاك/);
  assert.equal(await page.locator('.select2-search__field').last().getAttribute('aria-label'),'بحث');
  assert.equal(await page.locator('.card .d-md-none article').first().isVisible(),true);
  await capture('ar-depreciation-mobile');
  await go('/admin/fixed-assets/assets/FA-00002');
  assert.match(await page.locator('main').innerText(),/وضع الإهلاك/);
  assert.doesNotMatch(await page.locator('main').innerText(),/fixed_assets\.[a-z_.]+/);
  await capture('ar-asset-360-mobile');
  await page.getByRole('button',{name:'استبعاد',exact:true}).click();
  const disposal=page.locator('#disposal-modal');
  assert.equal(await disposal.locator('[data-disposal-field="invoice"]').filter({has:page.locator('[name="customer_doc_num"]')}).isHidden(),true);
  assert.equal(await disposal.locator('[data-disposal-field="expenses"]').isHidden(),true);
  await disposal.locator('[name="disposition_type"]').selectOption('scrap');
  assert.equal(await disposal.locator('[data-disposal-field="sale"]').first().isHidden(),true);
  const neutral=await disposal.locator('form').evaluate(form=>Object.fromEntries(new FormData(form).entries()));
  assert.equal(neutral.proceeds,'0');assert.equal(neutral.settlement_path,'direct_settlement');assert.equal(neutral.tax_rate,'0');
  await page.waitForTimeout(500);
  await capture('ar-disposal-scrap-mobile');
  await disposal.getByRole('button',{name:'إغلاق',exact:true}).click();
  await page.setViewportSize({width:1440,height:1000});
  await go('/lang/en');
  await go('/admin/fixed-assets/reports?type=net_book_value');
  assert.match(await page.locator('main').innerText(),/Fixed Asset Reports/);
  assert.match(await page.locator('#fixed-asset-report-description').innerText(),/Asset cost, accumulated depreciation/);
  assert.doesNotMatch(await page.locator('main').innerText(),/fixed_assets\.[a-z_.]+/);
  await capture('en-reports-desktop');
  await go('/admin/fixed-assets/depreciation');
  assert.match(await page.locator('main').innerText(),/Recent Depreciation Runs/);
  assert.equal(await page.locator('.select2-search__field').last().getAttribute('aria-label'),'Search');
  await capture('en-depreciation-desktop');
  assert.deepEqual(errors,[]);results.errors=errors;results.company=fixture.company;save();console.log(JSON.stringify(results));
 }catch(e){console.error('URL',page.url());console.error((await page.locator('body').innerText()).slice(-3000));await page.screenshot({path:path.join(output,'failure.png')});throw e;
 }finally{await context.storageState({path:'/tmp/fa-release/browser-state.json'});fs.chmodSync('/tmp/fa-release/browser-state.json',0o600);await browser.close();}
});
