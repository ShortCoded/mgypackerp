<?php

declare(strict_types=1);

use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$projectRoot = dirname(__DIR__, 2);
$outputDirectory = $projectRoot.'/output/pdf';
$outputPath = $outputDirectory.'/mgypack-client-acceptance-guide-20261014.pdf';
$inventoryMenuImage = $projectRoot.'/tmp/pdfs/library_refs/image(20261012-025445).png';
$productionMenuImage = $projectRoot.'/tmp/pdfs/library_refs/image(20261012-025447).png';
$dashboardImage = $projectRoot.'/output/review/mgypack-release-candidate-20261014/screenshots/mgypack-dashboard-debt-summaries-light-20261014.jpg';
$productionOrderUiImage = $projectRoot.'/output/review/mgypack-release-candidate-20261014/screenshots/mgypack-production-order-prod-00018-light-20261014.jpg';
$productionOrderImage = $projectRoot.'/tmp/pdfs/production-order-prod-00018/page-1.png';
$bomMaterialsImage = $projectRoot.'/output/review/bom-alternatives-20261013/visual-fidelity/png/run-materials-ar-1.png';

if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0775, true) && ! is_dir($outputDirectory)) {
    throw new RuntimeException('Unable to create the PDF output directory.');
}

$requiredImages = [$inventoryMenuImage, $productionMenuImage, $dashboardImage, $productionOrderUiImage, $productionOrderImage, $bomMaterialsImage];

foreach ($requiredImages as $requiredImage) {
    if (! is_file($requiredImage)) {
        throw new RuntimeException('Required guide image is missing: '.$requiredImage);
    }
}

$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'orientation' => 'P',
    'default_font' => 'dejavusans',
    'margin_top' => 12,
    'margin_bottom' => 14,
    'margin_left' => 12,
    'margin_right' => 12,
    'tempDir' => $projectRoot.'/storage/framework/cache/mpdf',
]);

$mpdf->SetTitle('MgyPack ERP - دليل التشغيل والقبول');
$mpdf->SetAuthor('MgyPack ERP acceptance review');
$mpdf->SetHTMLFooter('<table class="footer"><tr><td>دليل التشغيل والقبول - MgyPack ERP</td><td>14 أكتوبر 2026</td><td>{PAGENO} / {nbpg}</td></tr></table>');

$styles = <<<'CSS'
body { color: #17324d; font-family: dejavusans; font-size: 9.1pt; line-height: 1.42; }
h1 { color: #11375f; font-size: 24pt; line-height: 1.15; margin: 0 0 7px; }
h2 { color: #0f6471; font-size: 15.5pt; margin: 0 0 8px; }
h3 { color: #17324d; font-size: 10.8pt; margin: 0 0 4px; }
p { margin: 0 0 6px; }
.rtl { direction: rtl; text-align: right; }
.ltr { direction: ltr; text-align: left; }
.eyebrow { color: #0f8b8d; font-size: 9pt; font-weight: bold; letter-spacing: .5px; }
.subtitle { color: #587088; font-size: 10.7pt; }
.rule { border-top: 3px solid #0f8b8d; margin: 10px 0 16px; }
.hero { background: #f2f8fa; border: 1px solid #cde3e8; border-radius: 8px; padding: 12px; margin: 9px 0; }
.card { border: 1px solid #d7e2eb; border-radius: 7px; padding: 8px; margin: 0 0 6px; background: #fff; }
.soft { background: #f8fbfc; }
.callout { border-right: 4px solid #0f8b8d; background: #f5fafb; padding: 8px 10px; margin: 8px 0; }
.warning { border-right-color: #dc8c1b; background: #fff9ed; }
.danger { border-right-color: #b84444; background: #fff2f2; }
.status { display: inline-block; color: #0c6b49; background: #e8f7f0; border: 1px solid #a9ddc6; border-radius: 12px; font-size: 8pt; font-weight: bold; padding: 2px 8px; }
.status.source { color: #215b80; background: #edf6fb; border-color: #b9d6e8; }
.status.isolated { color: #66521a; background: #fff8df; border-color: #ead58b; }
.status.caveat { color: #8a3c31; background: #fff1ee; border-color: #e5b5ad; }
.two-col { width: 100%; border-collapse: collapse; }
.two-col td { width: 50%; vertical-align: top; padding: 0 6px 0 0; }
.two-col td + td { padding: 0 0 0 6px; }
.three-col { width: 100%; border-collapse: collapse; }
.three-col td { width: 33.33%; vertical-align: top; padding: 0 4px; }
.step { border-right: 3px solid #b8dce1; padding: 2px 10px 7px 0; margin: 0 0 6px; }
.step-number { color: #0f8b8d; font-weight: bold; }
.flow { border-collapse: collapse; width: 100%; margin: 7px 0; }
.flow td { border: 1px solid #d7e2eb; padding: 7px 5px; text-align: center; vertical-align: middle; font-size: 8.2pt; background: #f8fbfc; }
.flow .arrow { width: 4%; border: 0; background: #fff; color: #0f8b8d; font-weight: bold; }
.matrix { border-collapse: collapse; width: 100%; margin-top: 7px; }
.matrix th { background: #163d63; color: #fff; padding: 6px; text-align: right; font-size: 8.1pt; }
.matrix td { border: 1px solid #d6e1e9; padding: 6px; vertical-align: top; font-size: 8pt; }
.matrix tr:nth-child(even) td { background: #f7fafc; }
.evidence { border: 1px solid #cdd9e2; background: #f8fbfc; padding: 6px; text-align: center; margin-bottom: 7px; }
.evidence img { width: 100%; height: auto; }
.menu-shot { border: 1px solid #d5e0e8; background: #f8fbfc; padding: 6px; text-align: center; }
.menu-shot img { height: 94mm; width: auto; }
.menu-shot.short img { height: 64mm; }
.caption { color: #60778c; font-size: 7.8pt; margin-top: 4px; text-align: right; }
.mini { color: #60778c; font-size: 8pt; }
.metric { color: #11375f; font-size: 15pt; font-weight: bold; }
.footer { border-top: 1px solid #cdd9e2; border-collapse: collapse; color: #60778c; font-size: 7.2pt; padding-top: 5px; width: 100%; direction: rtl; }
.footer td { width: 33%; padding-top: 5px; }
.footer td:nth-child(2) { text-align: center; }
.footer td:nth-child(3) { direction: ltr; text-align: left; }
ul, ol { margin: 2px 0 6px; padding-right: 18px; }
li { margin-bottom: 3px; }
code { color: #123f5f; background: #edf3f7; font-family: dejavusansmono; font-size: 8.2pt; padding: 1px 3px; direction: ltr; }
CSS;

$html = <<<'HTML'
<div class="rtl">
<div class="eyebrow">MgyPack ERP - إصدار مراجعة محلي</div>
<h1>دليل التشغيل والقبول<br>لدورة الإنتاج والمخزون</h1>
<div class="subtitle">خطوات الاستخدام الفعلية، ضوابط التصحيح، بدائل قائمة المكونات، والطباعة والتقارير</div>
<div class="rule"></div>

<div class="hero">
  <span class="status">متحقق في متصفح مصادق سابقاً</span>
  <h2 style="margin-top:9px">طباعة أمر الإنتاج القياسي عادت للعمل</h2>
  <p>أمر الإنتاج <strong>PROD-00018</strong> أعاد استجابة مباشرة بحالة <strong>200</strong> ونوع <strong>application/pdf</strong> وحجم <strong>49,507 بايت</strong>. جرى فحص توقيع PDF واستخراج النص وتحويل الصفحة إلى صورة، وظهرت بيانات الأمر وقائمة المكونات كاملة.</p>
</div>

<table class="three-col"><tr>
  <td><div class="card soft"><div class="metric">تشغيلي</div><p>المسارات والقوائم والخطوات الموصى بها للمستخدم.</p></div></td>
  <td><div class="card soft"><div class="metric">رقابي</div><p>فصل الإعداد عن الاعتماد ومنع الحذف المدمر.</p></div></td>
  <td><div class="card soft"><div class="metric">قبول</div><p>دليل متصفح سابق، وفحص مصدر، وسيناريوهات معزولة.</p></div></td>
</tr></table>

<h2 style="margin-top:12px">طريقة قراءة هذا الدليل</h2>
<table class="matrix">
  <tr><td><span class="status">تحقق متصفح</span></td><td>لقطة فعلية من جلسة مصادق عليها أو استجابة فعلية تم تنزيلها وفحصها.</td></tr>
  <tr><td><span class="status source">مدعوم بالمصدر</span></td><td>مسار تشغيل وصلاحيات وقواعد أعمال مؤكدة من القوائم والمسارات والخدمات الحالية.</td></tr>
  <tr><td><span class="status isolated">قبول معزول</span></td><td>سيناريو نُفذ داخل معاملة أو قاعدة معزولة مع استعادة بصمات الصفوف المتأثرة.</td></tr>
  <tr><td><span class="status caveat">قيد قبول</span></td><td>نقطة لم تُقبل تشغيلياً في متصفح مصادق ضمن الجلسة الحالية، أو ما زالت خارج نطاق الإصلاح الضيق.</td></tr>
</table>

<div class="callout warning"><strong>حد الطباعة.</strong> الإصلاح المثبت يغطي مسار أمر الإنتاج القياسي المنتهي بـ <code>/print</code>. لا يفترض هذا الدليل أن كل المسارات المنتهية بـ <code>/pdf</code> أو مسار طباعة المشتريات الديناميكي محمية بالطريقة نفسها؛ تبقى هذه المسارات ضمن فحص قبول منفصل.</div>

<pagebreak />
<div class="eyebrow">01 - أين توجد الوظائف</div>
<h2>خريطة القوائم الفعلية</h2>
<table class="two-col"><tr>
  <td><div class="menu-shot"><img src="INVENTORY_MENU_IMAGE" alt="قائمة المخزون في الواجهة الفعلية"></div><div class="caption">لقطة واجهة فعلية - قائمة المخزون في الوضع الفاتح.</div></td>
  <td>
    <div class="menu-shot short"><img src="PRODUCTION_MENU_IMAGE" alt="قائمة الإنتاج في الواجهة الفعلية"></div>
    <div class="caption">لقطة واجهة فعلية - قائمة التصنيع والإنتاج في الوضع الفاتح.</div>
    <div class="card" style="margin-top:8px"><h3>داخل التصنيع والإنتاج</h3><ul>
      <li><strong>أوامر الإنتاج:</strong> إنشاء الأمر، إطلاقه، ومراجعة الطباعة.</li>
      <li><strong>تشغيلات الإنتاج:</strong> الحجز والصرف والتسوية والاستلام والإقفال والتصحيح.</li>
      <li><strong>طلبات خامات التشغيل:</strong> طلب الخامة الفعلية واعتمادها وصرفها وطباعتها.</li>
      <li><strong>إذن تسليم إنتاج للمخزن:</strong> تسليم الناتج من الإنتاج إلى المخزن.</li>
      <li><strong>تقارير عمليات الإنتاج:</strong> الأوامر والتشغيلات والخامات والاستلامات والجودة.</li>
    </ul></div>
  </td>
</tr></table>

<div class="card"><h3>داخل المخزون</h3><ul>
  <li><strong>استلام مخزني من الإنتاج:</strong> مراجعة إذن استلام المنتج التام واعتماده أو تصحيحه.</li>
  <li><strong>حركات المخزون:</strong> مراجعة مستندات الصرف والمرتجع والاستلام وحالاتها.</li>
  <li><strong>استكمال تكلفة الاستلامات السابقة:</strong> اقتراح تكلفة إذن غير مسعر ثم اعتمادها أو رفضها بمستخدم مستقل.</li>
  <li><strong>تقارير المخزون / استلامات الإنتاج التام:</strong> المتابعة بعد الترحيل.</li>
</ul></div>
<div class="callout"><span class="status source">مدعوم بالمصدر</span> ظهور كل شاشة وإجراء يعتمد على الشركة والفرع والفترة المالية والصلاحية. عدم ظهور زر لا يعني حذف المستند؛ راجع نطاق المستخدم وحالة المستند أولاً.</div>

<pagebreak />
<div class="eyebrow">02 - الصرف والاستلام والتكلفة</div>
<h2>الدورة الصحيحة للمنتج التام</h2>
<table class="flow"><tr>
  <td>طلب خامات التشغيل</td><td class="arrow">←</td><td>حجز واعتماد وصرف</td><td class="arrow">←</td><td>استهلاك / مرتجع / هالك</td><td class="arrow">←</td><td>فحص الناتج وتسليمه</td><td class="arrow">←</td><td>استلام التام وتكلفته</td>
</tr></table>

<div class="step"><span class="step-number">1 - الصرف من طلب خامات التشغيل</span><br>من <strong>التصنيع والإنتاج ← طلبات خامات التشغيل</strong> افتح الطلب المرتبط بالتشغيلة. راجع التشغيلة، الخامة الأصلية، الخامة الفعلية، معامل التحويل، الكمية، المخزن، وأي مستند صرف سابق. يعتمد الطلب صاحب صلاحية مستقلة ثم يُنفذ الصرف من الإجراء المخصص.</div>
<div class="step"><span class="step-number">2 - تسوية كل كمية مصروفة</span><br>قبل استلام التام يجب أن تكون الكمية المصروفة موزعة بالكامل إلى <strong>مستهلك</strong> أو <strong>مرتجع</strong> أو <strong>هالك</strong>. لا تُترك كمية مصروفة بلا تفسير محاسبي ومخزني.</div>
<div class="step"><span class="step-number">3 - تسليم الإنتاج</span><br>من التشغيلة الجارية استخدم <strong>إذن تسليم إنتاج للمخزن</strong>. راجع الكمية المقبولة والدفعة وتاريخي الإنتاج والصلاحية عند انطباقهما. الاستلام النهائي يتطلب اكتمال محاسبة الخامات وتكلفة تحت التشغيل واجتياز الجودة النهائية.</div>
<div class="step"><span class="step-number">4 - الاستلام المخزني</span><br>من <strong>المخزون ← استلام مخزني من الإنتاج</strong> افتح مستند الاستلام. راجع المخزن والصنف والوحدة والكمية، ثم اعتمد المستند حسب الصلاحية. المستند المرحل لا يُعدل بالكتابة فوقه؛ يظهر له مسار تصحيح مستقل.</div>
<div class="step"><span class="step-number">5 - استكمال تكلفة استلام غير مسعر</span><br>استخدم <strong>استكمال تكلفة الاستلامات السابقة</strong> فقط عندما يكون إذن الاستلام بانتظار مصدر تكلفة صالح. يقترح مستخدم التكلفة ومصدرها وأثرها، ثم يعتمد أو يرفض مستخدم آخر. لا تستخدم هذه الشاشة لإعادة تسعير استلام مكتمل صحيح.</div>

<table class="two-col"><tr>
  <td><div class="card"><h3>تحقق قبل الاعتماد</h3><ul><li>نفس الشركة والفرع والفترة المفتوحة.</li><li>ارتباط المستند بالتشغيلة الصحيحة.</li><li>اكتمال الصرف والمرتجع والهالك.</li><li>اجتياز الجودة ووجود تكلفة قابلة للإثبات.</li></ul></div></td>
  <td><div class="card"><h3>بعد الاعتماد</h3><ul><li>راجع حركة المخزون وقيدها المرتبط.</li><li>راجع تقرير استلامات الإنتاج التام.</li><li>قارن الكمية والقيمة بالتشغيلة.</li><li>استخدم مسار التصحيح عند الخطأ، لا الحذف.</li></ul></div></td>
</tr></table>
<div class="callout warning"><span class="status isolated">قبول معزول</span> رفض السيناريو استلاماً بلا تكلفة، ثم بعد استكمال تكلفة معزول رحّل استلاماً بقيمة <strong>534.91222068</strong>. استُعيدت بصمات الصفوف المتأثرة؛ لم تُعد قيم التسلسل ولم تُلمس بيانات التشغيل.</div>

<pagebreak />
<div class="eyebrow">03 - تصحيح التشغيلات القديمة والمكتملة</div>
<h2>صحح بالأثر العكسي، ولا تحذف التاريخ</h2>
<div class="hero"><span class="status source">مدعوم بالمصدر</span><p style="margin-top:7px">المسار: <strong>التصنيع والإنتاج ← تشغيلات الإنتاج ← فتح التشغيلة المكتملة ← الإجراءات ← تصحيح تشغيلة مكتملة</strong>. يحتفظ النظام بالتشغيلة والمستندات الأصلية وسجل الاقتراح والاعتماد، وينشئ آثار العكس وإعادة الاستلام وفق الفترة المسموحة.</p></div>

<div class="step"><span class="step-number">1 - افتح شاشة التصحيح</span><br>راجع ملخص المستندات، أثر المخزون، أثر القيود، وكميات الناتج الحالية. أدخل <strong>إجمالي</strong> الناتج الصحيح، وليس الفرق فقط، وسجل سبباً واضحاً.</div>
<div class="step"><span class="step-number">2 - اختر فترة الترحيل</span><br>إن كانت الفترة الأصلية مفتوحة ومسموحة استخدمها. التصحيح في فترة لاحقة مفتوحة يحتاج صلاحية مستقلة؛ يبقى تاريخ التشغيل ودفعاته الأصلية محفوظاً وتُرحل العكوس والاستلام الجديد في الفترة المختارة.</div>
<div class="step"><span class="step-number">3 - عالج التبعيات بالترتيب</span><br>إذا ظهرت قائمة <strong>تصحيحات التبعيات المطلوبة</strong> افتح كل مستند من رابطه ونفذ مسار تصحيحه الأصلي، ثم حدّث الصفحة. لا تتجاوز التبعية بحذف المستند أو تغيير قاعدة البيانات يدوياً.</div>
<div class="step"><span class="step-number">4 - إعداد ثم اعتماد مستقل</span><br>يجهز مستخدم مقترح التصحيح، ويعتمده مستخدم آخر لديه صلاحية <strong>اعتماد تصحيح التشغيلات</strong>. عند الاعتماد تُعكس استلامات التام واستهلاك الخامات والهالك المرتبط، ويُعاد فتح التشغيل للمطابقة وفحص جودة جديد واستلام صحيح.</div>

<table class="matrix"><thead><tr><th>التبعية المحتملة</th><th>التصرف الآمن</th><th>سبب المنع</th></tr></thead><tbody>
  <tr><td>مخزون التام استُخدم في بيع أو تحويل أو استهلاك</td><td>صحح المستند التابع من مساره أولاً</td><td>منع عكس رصيد لم يعد متاحاً</td></tr>
  <tr><td>مرحلة إنتاج لاحقة تعتمد على الناتج</td><td>صحح التشغيلة اللاحقة أولاً</td><td>حفظ تسلسل المراحل والتكلفة</td></tr>
  <tr><td>فاتورة عميل أو إذن تسليم مرتبط</td><td>استخدم تصحيح الفاتورة والتسليم المرتبط</td><td>حفظ أثر المبيعات والمخزون</td></tr>
  <tr><td>توزيع تكاليف غير مباشرة أو رواتب مرحلة</td><td>نفذ العكس أو التصحيح المعتمد في نفس الوحدة</td><td>حفظ دليل القيد العكسي والاستقلال</td></tr>
  <tr><td>فترة أصلية مغلقة</td><td>استخدم فترة لاحقة مفتوحة بصلاحية مخصصة</td><td>عدم تغيير فترة مالية مغلقة</td></tr>
</tbody></table>

<div class="callout danger"><strong>ممنوع تشغيلياً:</strong> حذف التشغيلة المكتملة، حذف الأذون التابعة، تصفير التسلسلات، فك القيود يدوياً، أو تعديل الكميات مباشرة في قاعدة البيانات. التصحيح الصحيح يترك الأصل ودليل العكس وسجل الاعتماد.</div>
<div class="callout warning">إلغاء إذن إضافة تام واحد له مسار أضيق: يعكس الإذن والقيد بدقة مع بقاء أدلة الاستهلاك والناتج. إذا استُخدم المخزون أو وُجدت مرحلة لاحقة، يعالج التابع أولاً.</div>

<pagebreak />
<div class="eyebrow">04 - قائمة المكونات والبدائل</div>
<h2>الأصل، البديل، والكمية المعادلة</h2>
<table class="two-col"><tr>
  <td><div class="card"><h3>المكون الأساسي</h3><p>يمكن أن يكون خامة أو تعبئة أو منتجاً تاماً أو <strong>نصف مصنع</strong>، بشرط نشاط الصنف والوحدة وانتمائهما للشركة. تُجمّد هويته ووحدته وكمية الوصفة عند إطلاق الأمر.</p></div></td>
  <td><div class="card"><h3>البديل المعتمد</h3><p>يبقى البديل <strong>خامة فقط</strong> في المسار الحالي. تُجمّد هويته ووحدته ومعامل تحويله داخل لقطة الأمر، ولا يجوز اختيار بديل خارج هذه اللقطة.</p></div></td>
</tr></table>
<div class="callout"><strong>مثال المعادلة:</strong> إذا كان المتبقي من الأصل <strong>40 كجم معادل</strong> ومعامل البديل <strong>1.5</strong>، فإن الحد المقابل للبديل هو <strong>60 كجم فعلي</strong>. يقارن النظام الطلبات على أساس مكافئ الأصل حتى لا يتجاوز إجمالي الأصل والبدائل الاحتياج المجمد.</div>
<div class="evidence"><img src="BOM_MATERIALS_IMAGE" alt="تقرير عربي مجسد لخامات تشغيل ببديل ومعامل تحويل"><div class="caption"><span class="status isolated">عرض قبول معزول</span> تقرير عربي مجسد يوضح الأصل والبديل والكميات المخططة والمحجوزة والمصروفة والمرتجعة والمستهلكة والهالك ومعامل التحويل. هذه لقطة تقرير قبول، وليست سجلاً تشغيلياً حياً.</div></div>
<table class="matrix"><thead><tr><th>القاعدة</th><th>ما يراه المستخدم</th></tr></thead><tbody>
  <tr><td>الهوية المجمدة</td><td>الخامة الأصلية والخامة الفعلية ومعامل التحويل محفوظة مع السطر.</td></tr>
  <tr><td>الحد المكافئ</td><td>لا يسمح طلب جديد بتجاوز المتبقي بوحدة مكافئ الأصل.</td></tr>
  <tr><td>إعادة التخصيص</td><td>تُعاد فقط الكمية غير المصروفة؛ الصرف السابق لا يُنقل ولا يُكتب فوقه.</td></tr>
  <tr><td>التسوية</td><td>المصروف يوزع إلى مستهلك أو مرتجع أو هالك قبل الاستلام والإقفال.</td></tr>
</tbody></table>

<pagebreak />
<div class="eyebrow">05 - الحجز والصرف والمرتجع</div>
<h2>تشغيل البدائل بأثر يمكن تتبعه</h2>
<div class="step"><span class="step-number">1 - راجع وصفة الصنف قبل إطلاق الأمر</span><br>حدد المكون الأساسي، وحدته، وكمية الأساس. أضف البدائل المسموحة بمعامل تحويل موجب ومفهوم للمستخدم. بعد إطلاق الأمر تصبح لقطة الوصفة هي المرجع للتشغيلة.</div>
<div class="step"><span class="step-number">2 - أنشئ طلب الخامة من التشغيلة</span><br>اختر السطر الأصلي ثم الأصل أو أحد بدائله المجمدة. تظهر الكمية الفعلية ومعادلها بالأصل والمتبقي. الطلب الأعلى من الحد يُرفض بالكامل بدلاً من إنشاء صرف جزئي مبهم.</div>
<div class="step"><span class="step-number">3 - احجز ثم اعتمد</span><br>الحجز يربط الكمية المتاحة بالمخزن والتشغيلة والمتطلب. عند العجز يمكن إنشاء طلب شراء مرتبط أو معالجة العجز حسب الصلاحية. لا تغير المنتج أو الوحدة بعد وجود صرف فعلي.</div>
<div class="step"><span class="step-number">4 - اصرف من المستند المعتمد</span><br>راجع رقم إذن الصرف والدفعة والكمية والتكلفة. يظل السطر مرتبطاً بالمتطلب الأصلي حتى لو كانت الخامة الفعلية بديلاً.</div>
<div class="step"><span class="step-number">5 - أرجع غير المستخدم</span><br>استخدم إجراء المرتجع من التشغيلة، ثم تحقق من مستند المرتجع ورصيد الحجز. لا تخفض المصروف يدوياً. بعد ذلك سجل الاستهلاك والهالك حتى يتساوى إجمالي التسوية مع المصروف الصافي.</div>
<div class="step"><span class="step-number">6 - أعد تخصيص غير المصروف فقط عند الحاجة</span><br>إذا تغيرت الحاجة قبل الصرف استخدم إعادة التخصيص. الكمية المصروفة والوثائق والتكلفة تبقى بهويتها المجمدة؛ إعادة التخصيص ليست أداة لتغيير تاريخ صرف سابق.</div>

<table class="two-col"><tr>
  <td><div class="card"><h3>فحص قبل الصرف</h3><ul><li>الطلب معتمد وغير ملغى.</li><li>الخامة والوحدة ومعامل التحويل صحيحان.</li><li>المعادِل لا يتجاوز المتبقي.</li><li>الحجز نشط والمخزون متاح.</li></ul></div></td>
  <td><div class="card"><h3>فحص بعد المرتجع</h3><ul><li>ظهر مستند المرتجع ورقمه.</li><li>تحدث الصرف الصافي.</li><li>اكتملت قيمة الحركة أو ظهر سبب عدم التسعير.</li><li>تقرير الخامات يعرض الأصل والفعلي معاً.</li></ul></div></td>
</tr></table>
<div class="callout warning"><span class="status isolated">قبول معزول</span> رُفض طلب أعلى من الحد المكافئ دون أثر جزئي. ثم حُجز وصُرف وأُرجع بديل بقيمة <strong>3.00</strong>، كما صُرف وأُرجع نصف مصنع بقيمة <strong>2.00</strong>. استُعيدت الصفوف المتأثرة.</div>
<div class="callout danger"><strong>الاستبدال بعد الصرف مسار استثنائي أضيق.</strong> يتطلب متطلباً مصروفاً بالكامل وغير مستهلك، بلا حجز أو طلب خامات تابع أو أثر جودة أو استلام أو مصروف لاحق، ويحتاج اعتماداً فنياً ومستخدماً مستقلاً. لا تستخدمه بديلاً عن الاختيار الصحيح قبل الصرف.</div>

<pagebreak />
<div class="eyebrow">06 - الطباعة والتقارير</div>
<h2>ما الذي يطبع، وأين تراجع الأثر</h2>
<table class="two-col"><tr>
  <td><div class="evidence"><img src="PRODUCTION_ORDER_UI_IMAGE" alt="صفحة أمر الإنتاج الفعلية في الوضع الفاتح"><div class="caption">لقطة متصفح فعلية سابقة لصفحة PROD-00018؛ زر الطباعة ظاهر في الوضع الفاتح.</div></div></td>
  <td><div class="evidence"><img src="PRODUCTION_ORDER_IMAGE" alt="صفحة PDF الفعلية لأمر الإنتاج"><div class="caption">تنزيل PDF الفعلي بعد تحويل الصفحة إلى صورة؛ بيانات الأمر والمكونات ظاهرة.</div></div></td>
</tr></table>
<table class="matrix"><thead><tr><th>الوثيقة أو التقرير</th><th>الاستخدام العملي</th><th>قبل الاعتماد أو التسليم</th></tr></thead><tbody>
  <tr><td>طباعة أمر الإنتاج</td><td>تعليمات التنفيذ وقائمة المكونات المجمدة</td><td>رقم الأمر، الصنف، الكمية، المصنع، التواريخ، والمكونات</td></tr>
  <tr><td>طباعة طلب خامات التشغيل</td><td>تسليم الطلب للمخزن ومراجعة الخامة الفعلية</td><td>الأصل، الفعلي، معامل التحويل، الكمية، ومستندات الصرف</td></tr>
  <tr><td>بطاقة التشغيلة / متطلبات الخامة</td><td>متابعة التنفيذ والخامات على مستوى التشغيلة</td><td>المخطط، المحجوز، المصروف، المستهلك، الهالك، والمرتجع</td></tr>
  <tr><td>تقرير مطابقة خامات الإنتاج</td><td>تحليل الانحراف والأصل مقابل البديل</td><td>الفلاتر، الوحدة، المعادل، والقيم الفعلية</td></tr>
  <tr><td>تقرير استلامات الإنتاج التام</td><td>مطابقة التسليم والاستلام والتكلفة</td><td>الفترة، المخزن، التشغيلة، الكمية، والحالة</td></tr>
  <tr><td>تقارير الأوامر والتشغيلات والجودة</td><td>المتابعة الإدارية والتصدير</td><td>نفس الفلاتر في الشاشة وCSV وXLSX ونسخة الطباعة</td></tr>
</tbody></table>
<div class="callout"><strong>تسلسل قبول الطباعة:</strong> افتح المستند من الواجهة، اضغط طباعة، تحقق من استجابة PDF مباشرة، ثم راجع صفحة فعلية لا مجرد امتداد الملف. عند تحديث PWA فعّل النسخة الجديدة مرة واحدة قبل إعادة المحاولة.</div>
<div class="callout warning"><span class="status caveat">قيد قبول</span> عارض PDF المدمج في متصفح Codex قد يعرض غلافاً داكناً فارغاً رغم سلامة الملف. يعتمد القبول على الاستجابة والتنزيل وتوقيع الملف واستخراج النص وتحويل الصفحة. يُفضل Chrome أو Edge للمستخدم النهائي. كما يجب فحص مسارات <code>/pdf</code> وطباعة المشتريات الديناميكية على حدة.</div>

<pagebreak />
<div class="eyebrow">07 - دليل الواجهة وحدود القبول</div>
<h2>ملخصات العملاء والموردين في الوضع الفاتح</h2>
<div class="evidence"><img src="DASHBOARD_IMAGE" alt="لوحة المعلومات وملخصات مديونيات العملاء والموردين"><div class="caption"><span class="status">تحقق متصفح سابق</span> لقطة مصادق عليها للفترة من 01/10/2026 إلى 14/10/2026. تظهر أرصدة أول المدة، حركة الفترة، والأرصدة الختامية للعملاء والموردين.</div></div>
<table class="two-col"><tr>
  <td><div class="card"><h3>العملاء - القيم المشاهدة</h3><p>مدين أول المدة: <strong>3,329,343.3155</strong><br>دائن أول المدة: <strong>118,793.75</strong><br>حركة مدينة: <strong>152,637.5</strong><br>مدين ختامي: <strong>3,481,980.8155</strong><br>دائن ختامي: <strong>118,793.75</strong></p></div></td>
  <td><div class="card"><h3>الموردون - القيم المشاهدة</h3><p>دائن أول المدة: <strong>75,900</strong><br>دفعات مقدمة مدينة: <strong>0</strong><br>حركة الفترة: <strong>0</strong><br>دائن ختامي: <strong>75,900</strong></p></div></td>
</tr></table>
<div class="callout warning"><strong>حد الجلسة الحالية.</strong> المتصفح المتاح حالياً وصل إلى صفحة تسجيل الدخول فقط، ولا توجد علامة تبويب Chrome أو Edge مصادق عليها. لم تُدخل بيانات اعتماد. لذلك تعتمد هذه الصفحة على لقطة القبول السابقة، ولا تدعي إعادة قبول جديدة بعد آخر تشغيل للخادم.</div>

<h2 style="margin-top:12px">ما هو مثبت وما يبقى مطلوباً</h2>
<table class="matrix"><thead><tr><th>البند</th><th>الحالة</th><th>الحد</th></tr></thead><tbody>
  <tr><td>ملخصات المديونية مع أول المدة وفلاتر التاريخ</td><td><span class="status">لقطة متصفح سابقة</span></td><td>إعادة قبول جديدة تتطلب جلسة مصادق عليها</td></tr>
  <tr><td>طباعة PROD-00018 القياسية</td><td><span class="status">استجابة وتنزيل ورندر</span></td><td>خاص بالمسار القياسي المنتهي بـ /print</td></tr>
  <tr><td>نصف المصنع كمكون أساسي</td><td><span class="status source">مصدر + قبول معزول</span></td><td>لا يوجد مثال تشغيلي حي محفوظ</td></tr>
  <tr><td>دورة البديل والحجز والصرف والمرتجع</td><td><span class="status isolated">قبول معزول</span></td><td>لا يوجد مثال تشغيلي حي معتمد محفوظ</td></tr>
  <tr><td>تصحيح التشغيلة المكتملة</td><td><span class="status source">مصدر + قبول معزول</span></td><td>لا يحل محل اختبار الفريق المستهدف</td></tr>
</tbody></table>

<pagebreak />
<div class="eyebrow">08 - بوابة الإصدار</div>
<h2>ما يجب إقفاله قبل الإنتاج</h2>
<div class="card"><h3>1 - مراجعة التغيير</h3><p>اعزل الملفات المطلوبة فقط في فرع مراجعة واضح، راجع الفروقات، شغّل الفحوص المستهدفة في بيئة مناسبة، وثبت نتائج القبول. لا تنشر شجرة العمل الحالية كما هي لأنها تحتوي تغييرات كثيرة غير مفهرسة وغير مرتبطة بنطاق واحد.</p></div>
<div class="card"><h3>2 - النسخ الاحتياطي وخطة الاستعادة</h3><p>حدد قاعدة الإنتاج والجهة المسؤولة ووجهة النسخة، ثم نفذ إثبات استعادة مستقل. وجود تعليمات مكتوبة لا يساوي نسخة قابلة للاستعادة.</p></div>
<div class="card"><h3>3 - الترحيل والبناء والكاش</h3><p>طبق الترحيلات الناقصة فقط بعد إثبات هوية البيئة. ابن أصول الواجهة، حدّث كاش الإعداد والمسارات والقوالب، ثم أعد تحميل Octane بطريقة النظام الفعلية.</p></div>
<div class="card"><h3>4 - المصالحات والقبول</h3><p>صالح أرصدة العملاء والموردين ودفتر الأستاذ والمخزون وتكلفة الإنتاج، ثم نفذ قبولاً مصادقاً لمسارات الصرف والاستلام والتكلفة والتصحيح والطباعة والتقارير.</p></div>
<div class="card"><h3>5 - قرار الإصدار والرجوع</h3><p>لا يوجد في المستودع الحالي مسار نشر ذري أو رابط إصدارات أو مهمة رجوع تنفيذية مثبتة. يلزم تحديد آلية النشر الفعلية والرجوع ومشغلها قبل أي إصدار.</p></div>

<table class="matrix"><thead><tr><th>مثبت محلياً</th><th>غير مثبت بعد</th></tr></thead><tbody>
  <tr><td>الفرع الحالي main ويتتبع origin/main</td><td>الحالة الحية للفرع البعيد والمصادقة عليه</td></tr>
  <tr><td>الرأس المحلي متقدم 9 التزامات عن المرجع البعيد المحلي</td><td>هوية خادم الإنتاج وقاعدة البيانات وحالة الترحيلات</td></tr>
  <tr><td>لا توجد GitHub Actions أو إعداد نشر متتبع ظاهر</td><td>مهمة نشر معتمدة وآلية إصدار ذري ورجوع تنفيذي</td></tr>
  <tr><td>تعليمات إصدار ومصالحة مكتوبة في المستودع</td><td>مشغل النسخ الاحتياطي ووجهته وإثبات الاستعادة</td></tr>
</tbody></table>
<div class="callout danger"><strong>لا تستخدم <code>composer setup</code> كأمر نشر.</strong> هذا السكربت ينسخ ملف البيئة ويولد مفتاحاً ويطبق ترحيلات إجبارية ويثبت ويبني تبعيات؛ هو إعداد محلي وليس آلية إصدار آمنة.</div>
<div class="callout"><strong>المسار الآمن غير القسري:</strong> نسخة عمل نظيفة من أساس تمت مراجعته ← قائمة ملفات دقيقة ← التزام في فرع مراجعة مسمى ← جلب وإثبات السلف والمصادقة ← دفع غير قسري لفرع المراجعة ← مراجعة ودمج ← نشر منفصل مصرح به مع نسخة احتياطية وخطة رجوع.</div>

<h2 style="margin-top:13px">حدود هذا التسليم</h2>
<ul>
  <li>لم يُشغل منفذ اختبارات في هذه المهمة، ولم تُدخل بيانات اعتماد أو تُمنح صلاحيات.</li>
  <li>لم يحدث دفع أو نشر أو اتصال بالمستودع البعيد أو تعديل لبيانات تشغيلية.</li>
  <li>الدليل يفصل بوضوح بين إرشاد الاستخدام ودليل القبول والقيود الباقية.</li>
  <li>نسخة الدليل السابقة محفوظة محلياً للاسترجاع قبل هذا الاستبدال.</li>
</ul>
</div>
HTML;

$imageReplacements = [
    'INVENTORY_MENU_IMAGE' => $inventoryMenuImage,
    'PRODUCTION_MENU_IMAGE' => $productionMenuImage,
    'DASHBOARD_IMAGE' => $dashboardImage,
    'PRODUCTION_ORDER_UI_IMAGE' => $productionOrderUiImage,
    'PRODUCTION_ORDER_IMAGE' => $productionOrderImage,
    'BOM_MATERIALS_IMAGE' => $bomMaterialsImage,
];

foreach ($imageReplacements as $placeholder => $imagePath) {
    $html = str_replace($placeholder, str_replace('\\', '/', $imagePath), $html);
}

$mpdf->WriteHTML($styles, HTMLParserMode::HEADER_CSS);
$mpdf->WriteHTML($html, HTMLParserMode::HTML_BODY);
$mpdf->Output($outputPath, Destination::FILE);

echo $outputPath.PHP_EOL;
