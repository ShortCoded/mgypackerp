const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const enabled = process.env.FA_BROWSER_REVIEW === '1';

test('authenticated fixed asset product journeys and responsive screens', {skip:!enabled, timeout:480000}, async () => {
    const {chromium} = require('playwright');
    const base = process.env.FA_BROWSER_URL;
    assert.match(base, /^http:\/\/(localhost|127\.0\.0\.1):8011$/);
    const output = process.env.FA_BROWSER_OUTPUT;
    assert.ok(output.startsWith('/tmp/fa-release/'));
    fs.mkdirSync(output, {recursive:true});
    const browser = await chromium.launch({executablePath:process.env.FA_BROWSER_EXECUTABLE, headless:true, args:['--no-sandbox']});
    const context = await browser.newContext({storageState:process.env.FA_BROWSER_STATE,viewport:{width:1440,height:1000}});
    const page = await context.newPage();
    const errors = [];
    const responses = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => {if(response.status() >= 500 && response.url().startsWith(base)) responses.push([response.status(), response.url()]);});
    const go = async (url) => {const response = await page.goto(base+url); assert.equal(response.status(),200); await page.waitForLoadState('networkidle'); assert.ok(!page.url().includes('/login'));};
    const select = async (id, search, option) => {
        await page.locator(`[role="combobox"][aria-labelledby="select2-${id}-container"]`).click();
        await page.getByRole('searchbox',{name:'Search',exact:true}).fill(search);
        await page.getByRole('option',{name:option}).click();
    };
    const capture = async (name) => {await page.screenshot({path:path.join(output,name+'.png'),fullPage:false});};
    const resultFile = path.join(output,'results.json');
    const results = fs.existsSync(resultFile) ? JSON.parse(fs.readFileSync(resultFile,'utf8')) : {};
    try {
        await go('/admin/fixed-assets/assets');
        assert.equal(await page.locator('.js-asset-register-filters select').evaluateAll(els=>els.filter(e=>e.value).length),0);
        if (!results.openingAsset) {
            await page.getByRole('link',{name:/إضافة سجل جديد/}).click();
            await page.waitForURL(/assets\/create$/);
            await page.locator('#entry_type').selectOption('opening_asset');
            await page.locator('#asset_name').fill('أصل قائم — مراجعة المنتج '+Date.now());
            await page.locator('#asset_date').fill('01/01/2026');
            await page.locator('#purchase_date').fill('01/01/2025');
            await page.locator('#operation_date').fill('01/01/2025');
            await select('asset_group_account_doc_num','Release Acceptance',/Release Acceptance Assets/);
            await select('credit_account_doc_num','رأس المال',/^31 \/ رأس المال$/);
            await select('cost_center_doc_num','99001',/99001 \/ Lifecycle Source/);
            await page.getByRole('tab',{name:'البيانات المالية',exact:true}).click();
            await page.locator('#purchase_value').fill('10000');
            await page.locator('#previous_depreciation').fill('2000');
            await page.locator('#previous_depreciation_until_date').fill('31/12/2025');
            await page.locator('#depreciation_start_date').fill('01/01/2025');
            await page.locator('#useful_life').fill('5');
            await capture('opening-financial');
            await page.getByRole('tab',{name:'الموقع والملاحظات',exact:true}).click();
            await select('branch_doc_num','Main Branch',/Branch-00001 \/ Main Branch/);
            await page.locator('#description').fill('Opening asset created entirely through the authenticated browser.');
            await page.getByRole('button',{name:'حفظ البيانات',exact:true}).first().click();
            await page.waitForURL(/assets\/FA-\d+$/);
            results.openingAsset = page.url().match(/FA-\d+/)[0];
            fs.writeFileSync(resultFile,JSON.stringify(results,null,2));
        }
        await go('/admin/fixed-assets/assets/'+results.openingAsset);
        if (await page.locator('#recognition-modal').count()) {
            assert.match(await page.locator('.fixed-asset-360').innerText(),/8,000/);
            await page.getByRole('button',{name:'اعتماد الرصيد الافتتاحي',exact:true}).click();
            await page.getByRole('dialog').getByRole('button',{name:'اعتماد الرصيد الافتتاحي',exact:true}).click();
            await page.waitForLoadState('networkidle');
            await page.locator('#recognition-modal').waitFor({state:'detached'});
        }
        await page.getByRole('tab',{name:'الحركات',exact:true}).click();
        assert.match(await page.locator('#ledger').innerText(),/إثبات افتتاحي/);
        assert.ok(await page.locator('#ledger a[href*="journal-entries"]').count()>0);
        await capture('opening-posted-ledger');
        if (!results.openingDepreciation) {
            await page.getByRole('link',{name:'إهلاك',exact:true}).click();
            await page.waitForURL(/depreciation\?/);
            await page.locator('input[name="posting_date"]').fill('31/01/2026');
            await page.getByRole('button',{name:'معاينة',exact:true}).click();
            await page.locator('[data-asset="'+results.openingAsset+'"]').waitFor();
            results.openingJanuaryPreview = await page.locator('[data-asset="'+results.openingAsset+'"]').innerText();
            await capture('opening-depreciation-preview');
            await page.getByRole('button',{name:'ترحيل الإهلاك',exact:true}).click();
            await page.waitForURL(/depreciation\/runs\//);
            results.openingDepreciation = page.url();
            assert.ok(await page.locator('a[href*="journal-entries"]').count()>0);
            fs.writeFileSync(resultFile,JSON.stringify(results,null,2));
        }
        const screens = [
            ['register','/admin/fixed-assets/assets'],
            ['create','/admin/fixed-assets/assets/create'],
            ['draft-edit','/admin/fixed-assets/assets/FA-00014/edit'],
            ['asset-360','/admin/fixed-assets/assets/FA-00018'],
            ['opening-360','/admin/fixed-assets/assets/'+results.openingAsset],
            ['depreciation','/admin/fixed-assets/depreciation?asset_doc_nums[]='+results.openingAsset],
            ['movements','/admin/fixed-assets/movements?asset_doc_num=FA-00018'],
            ['reports','/admin/fixed-assets/reports?type=register&asset_doc_num='+results.openingAsset],
            ['reconciliation','/admin/fixed-assets/reports?type=reconciliation&asset_doc_num='+results.openingAsset],
            ['mappings','/admin/fixed-assets/accounting-mappings'],
        ];
        results.screens=[];
        for (const locale of ['ar','en']) {
            await page.goto(base+'/lang/'+locale);
            for (const [width,height] of [[1440,1000],[768,1024],[390,844]]) {
                await page.setViewportSize({width,height});
                for (const [name,url] of screens) {
                    await go(url);
                    assert.equal(await page.locator('html').getAttribute('dir'),locale==='ar'?'rtl':'ltr');
                    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth > innerWidth+2);
                    assert.equal(overflow,false,`${locale} ${width} ${name} must fit the viewport`);
                    await capture(`${locale}-${width}-${name}`);
                    results.screens.push({locale,width,name,overflow});
                }
                await go('/admin/fixed-assets/assets/'+results.openingAsset);
                for (const workflow of ['addition','transfer','custody','disposal']) {
                    await page.locator(`[data-bs-target="#${workflow}-modal"]`).click();
                    await page.locator('#'+workflow+'-modal').waitFor({state:'visible'});
                    await page.waitForTimeout(350);
                    await capture(`${locale}-${width}-${workflow}`);
                    if (workflow==='transfer') {
                        const button=page.locator('#transfer-modal button[type="submit"]');
                        assert.equal(await button.isDisabled(),true);
                        const location=page.locator('#transfer-modal [name="destination_location_address"]');
                        const initial=await location.inputValue();
                        await location.fill('Unsaved browser transfer preview');
                        assert.equal(await button.isDisabled(),false);
                        await location.fill(initial);
                        assert.equal(await button.isDisabled(),true);
                    }
                    if (workflow==='disposal') {
                        await page.locator('#disposal-modal [name="disposition_type"]').selectOption('write_off');
                        assert.equal(await page.locator('#disposal-modal [name="proceeds"]').isVisible(),false);
                        await page.locator('#disposal-modal [name="disposition_type"]').selectOption('sale');
                        assert.equal(await page.locator('#disposal-modal [name="proceeds"]').isVisible(),true);
                    }
                    await page.locator('#'+workflow+'-modal [data-bs-dismiss="modal"]').click();
                    await page.locator('#'+workflow+'-modal').waitFor({state:'hidden'});
                }
            }
        }
        await page.goto(base+'/lang/ar');
        assert.deepEqual(errors,[]);
        assert.deepEqual(responses,[]);
        results.errors=errors;results.failedResponses=responses;
        fs.writeFileSync(resultFile,JSON.stringify(results,null,2));
        console.log(JSON.stringify({openingAsset:results.openingAsset,openingDepreciation:results.openingDepreciation,screens:results.screens.length,overflows:results.screens.filter(x=>x.overflow)}));
    } catch(error) {
        await capture('failure');
        console.error('URL',page.url());
        console.error((await page.locator('body').innerText()).slice(-3000));
        throw error;
    } finally {
        await context.storageState({path:process.env.FA_BROWSER_STATE});
        fs.chmodSync(process.env.FA_BROWSER_STATE,0o600);
        await browser.close();
    }
});
