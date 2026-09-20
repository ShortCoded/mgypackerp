# §31 كتالوج شاشات النظام — System Screen Catalog

> **نطاق الملف:** Auth · Dashboard · Core · Sales · Purchases · Inventory  
> **تاريخ الإنشاء:** 2026-09-20  
> **مصدر البيانات:** الكود المرجعي من routes/web.php و config/menu/*.php و modules/*/Routes/web.php

---

## ▸ الوحدة 1: المصادقة — Auth

### 1. تسجيل الدخول — Login
- **المسار:** `login` (GET/POST)
- **الصلاحية:** — (عامة)
- **القائمة:** — (شاشة مستقلة)
- **الإجراءات:** —
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Auth/Routes/web.php` · `AuthenticatedSessionController`

### 2. نسيت كلمة المرور — Forgot Password
- **المسار:** `forgot-password` (GET/POST)
- **الصلاحية:** —
- **القائمة:** — (شاشة مستقلة)
- **الإجراءات:** —
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Auth/Routes/web.php`

### 3. إعادة تعيين كلمة المرور — Reset Password
- **المسار:** `reset-password/{token}` (GET/POST)
- **الصلاحية:** —
- **القائمة:** — (شاشة مستقلة)
- **الإجراءات:** —
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Auth/Routes/web.php`

### 4. قفل الشاشة — Lock Screen
- **المسار:** `lock-screen` (GET/POST)
- **الصلاحية:** —
- **القائمة:** — (شاشة مستقلة)
- **الإجراءات:** —
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Auth/Routes/web.php` · `LockScreenService`

### 5. تسجيل الخروج — Logout
- **المسار:** `logout` (POST)
- **الصلاحية:** —
- **القائمة:** — (إجراء، ليست شاشة مستقلة)
- **الإجراءات:** —
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Auth/Routes/web.php`

### 6. الملف الشخصي — Profile
- **المسار:** `profile.show` (GET) / `profile.update` (PUT) / `profile.password.update` (PUT)
- **الصلاحية:** `profile.view`
- **القائمة:** `config/menu/core.php` → basic_data > profile (مخفية)
- **الإجراءات:** عرض الملف · تعديل الملف · تحديث كلمة المرور · عرض الجلسات · عرض سجلات المصادقة · حذف الحساب
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Auth/Routes/web.php` · `config/menu/core.php` (basic_data.children)

### 7. المستخدمون — Users
- **المسار:** `admin.users.index` (GET) / `admin.users.create` / `admin.users.store` / `admin.users.edit` / `admin.users.destroy`
- **الصلاحية:** `users.view`
- **القائمة:** `config/menu/core.php` → basic_data > users
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · إدارة الأدوار · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Auth/Routes/web.php` · `config/menu/core.php`

### 8. مجموعات المستخدمين — User Groups (Roles)
- **المسار:** `admin.roles.index` (GET) / `admin.roles.create` / `admin.roles.store` / `admin.roles.edit` / `admin.roles.destroy`
- **الصلاحية:** `roles.view`
- **القائمة:** `config/menu/core.php` → basic_data > roles
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · إدارة النطاق التشغيلي · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Auth/Routes/web.php` · `config/menu/core.php` · `PermissionRegistryService`

### 9. سياسات رؤية البيانات — Screen Data Visibility Rules
- **المسار:** `admin.screen-data-visibility-rules.index` (GET)
- **الصلاحية:** `screen_data_visibility_rules.view`
- **القائمة:** `config/menu/core.php` → basic_data > screen_data_visibility_rules (مشروط بالميزة)
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · تجاوز
- **الحالة:** ✅ مُنفّذ (مُفعّل بشرط تفعيل الميزة `erp_features.screen_data_visibility_rules.enabled`)
- **الكود المرجعي:** `modules/Auth/Routes/web.php` · `config/menu/core.php` (条件付き)

### 10. سجل النشاط — Activity Log
- **المسار:** `admin.activity-logs.index` (GET)
- **الصلاحية:** `activity.logs.view`
- **القائمة:** `config/menu/core.php` → basic_data > activity_logs
- **الإجراءات:** عرض · تفاصيل · تصدير · PDF
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Auth/Routes/web.php` · `config/menu/core.php`

### 11. سجل تسجيل الدخول — Auth Logs
- **المسار:** `admin.auth-logs.index` (GET)
- **الصلاحية:** `auth.logs.view`
- **القائمة:** `config/menu/core.php` → basic_data > auth_logs
- **الإجراءات:** عرض · تفاصيل · تصدير · PDF
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Auth/Routes/web.php` · `config/menu/core.php`

### 12. الجلسات النشطة — Active Sessions
- **المسار:** `admin.auth-sessions.index` (GET)
- **الصلاحية:** `auth.sessions.view`
- **القائمة:** `config/menu/core.php` → basic_data > auth_sessions
- **الإجراءات:** عرض · تفاصيل · تصدير · PDF · إ-force logout
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Auth/Routes/web.php` · `config/menu/core.php` · `OnlineSeatLimitService`

---

## ▸ الوحدة 2: لوحة التحكم — Dashboard

### 13. لوحة التحكم — Dashboard
- **المسار:** `dashboard` (GET)
- **الصلاحية:** `dashboard.view`
- **القائمة:** `config/menu/core.php` → dashboard
- **الإجراءات:** عرض لوحة التحكم · ملخص المبيعات (`dashboard.summaries.sales.view`) · ملخص المشتريات (`dashboard.summaries.purchases.view`)
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `routes/web.php` · `config/menu/core.php`

---

## ▸ الوحدة 3: البيانات الأساسية — Core (Basic Data)

### 14. الشركات — Companies
- **المسار:** `admin.companies.index` (GET)
- **الصلاحية:** `companies.view`
- **القائمة:** `config/menu/core.php` → basic_data > companies
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · التحكم برقم المستند · التحكم الرئيسي
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/core.php` · `OperatingContextService`

### 15. الفروع — Branches
- **المسار:** `admin.branches.index` (GET)
- **الصلاحية:** `branches.view`
- **القائمة:** `config/menu/core.php` → basic_data > branches
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/core.php`

### 16. الفترات المالية — Financial Periods
- **المسار:** `admin.financial-periods.index` (GET)
- **الصلاحية:** `financial_periods.view`
- **القائمة:** `config/menu/core.php` → basic_data > financial_periods
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · إغلاق · إعادة فتح · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/core.php`

---

## ▸ الوحدة 3 (تتمة): بيانات الأصناف — Core (Item Data)

### 17. المنتجات — Products
- **المسار:** `admin.products.index` (GET)
- **الصلاحية:** `products.view`
- **القائمة:** `config/menu/core.php` → inventory > products
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · استيراد · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/core.php`

### 18. الخامات — Raw Materials
- **المسار:** `admin.raw-materials.index` (GET)
- **الصلاحية:** `raw_materials.view`
- **القائمة:** `config/menu/core.php` → inventory > raw_materials
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/core.php`

### 19. مواد التعبئة والتغليف — Packaging Materials
- **المسار:** `admin.packaging-materials.index` (GET)
- **الصلاحية:** `packaging_materials.view`
- **القائمة:** `config/menu/core.php` → inventory > packaging_materials
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/core.php`

### 20. الفئات — Item Categories
- **المسار:** `admin.item-categories.index` (GET)
- **الصلاحية:** `item_categories.view`
- **القائمة:** `config/menu/core.php` → inventory > item_categories
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/core.php`

### 21. الوحدات — Item Units
- **المسار:** `admin.item-units.index` (GET)
- **الصلاحية:** `item_units.view`
- **القائمة:** `config/menu/core.php` → inventory > item_units
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/core.php`

### 22. المقاسات — Item Sizes
- **المسار:** `admin.item-sizes.index` (GET)
- **الصلاحية:** `item_sizes.view`
- **القائمة:** `config/menu/core.php` → inventory > item_sizes
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/core.php`

### 23. الألوان — Item Colors
- **المسار:** `admin.item-colors.index` (GET)
- **الصلاحية:** `item_colors.view`
- **القائمة:** `config/menu/core.php` → inventory > item_colors
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/core.php`

### 24. النقشات / الديكالات — Item Patterns / Decals
- **المسار:** `admin.item-decals.index` (GET)
- **الصلاحية:** `item_decals.view`
- **القائمة:** `config/menu/core.php` → inventory > item_decals
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/core.php`

### 25. الموديلات — Item Models
- **المسار:** `admin.item-models.index` (GET)
- **الصلاحية:** `item_models.view`
- **القائمة:** `config/menu/core.php` → inventory > item_models
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/core.php`

### 26. المجموعات — Item Groups
- **المسار:** `admin.item-groups.index` (GET)
- **الصلاحية:** `item_groups.view`
- **القائمة:** `config/menu/core.php` → inventory > item_groups
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/core.php`

### 27. بلد المنشأ — Country of Origin
- **المسار:** `admin.item-origin-countries.index` (GET)
- **الصلاحية:** `item_origin_countries.view`
- **القائمة:** `config/menu/core.php` → inventory > item_origin_countries
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/core.php`

---

## ▸ الوحدة 3 (تتمة): الأدوات — Core (Tools)

### 28. فتح المستندات — Open Documents
- **المسار:** `admin.tools.open-documents.index` (GET)
- **الصلاحية:** `tools.open_documents.view`
- **القائمة:** `config/menu/tools.php` → tools > open_documents
- **الإجراءات:** عرض · تنفيذ (إعادة فتح)
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/tools.php`

### 29. مدير الملفات — File Manager
- **المسار:** `admin.file-manager.index` (GET)
- **الصلاحية:** `file_manager.view`
- **القائمة:** `config/menu/tools.php` → tools > file_manager
- **الإجراءات:** عرض · عرض المحذوفات · رفع · تحديث رؤية المنتقي · نقل · تحميل · حذف · استعادة · إعدادات رقم المستند · إنشاء مجلدات · إعادة تسمية مجلدات · حذف مجلدات · استعادة مجلدات · إنشاء روابط عامة · عرض روابط عامة · إلغاء روابط عامة
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/tools.php`

### 30. التقويم — Calendar
- **المسار:** `admin.calendar.index` (GET)
- **الصلاحية:** `calendar.view`
- **القائمة:** `config/menu/tools.php` → tools > calendar
- **الإجراءات:** عرض · إنشاء · تعديل · حذف · إتمام
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/tools.php`

### 31. لوحتي — My Board
- **المسار:** `admin.my-board.index` (GET)
- **الصلاحية:** `my_board.view`
- **القائمة:** `config/menu/tools.php` → tools > my_board
- **الإجراءات:** عرض · إنشاء · تعديل · حذف · استنساخ · عرض المحذوفات · استعادة · إعادة ترتيب · تعيين · عرض للجميع · إدارة للجميع · إدارة القوائم · إدارة التعليقات · عرض جميع المهام · عرض جميع الملاحظات
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/tools.php`

### 32. لوحات المهام — Task Boards
- **المسار:** `admin.task-boards.index` (GET)
- **الصلاحية:** `task_boards.view`
- **القائمة:** `config/menu/tools.php` → tools > task_boards
- **الإجراءات:** عرض · إنشاء · تعديل · حذف · عرض المحذوفات · استعادة · تفعيل جماعي · تعطيل جماعي · عرض شاشة · الإعدادات العامة · إعادة إنشاء الرابط العام
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/tools.php`

### 33. إدارة المهام — Team Board / Task Management
- **المسار:** `admin.tools.team-board.index` (GET)
- **الصلاحية:** `my_board.tasks.view_all` أو `my_board.notes.view_all`
- **القائمة:** `config/menu/tools.php` → tools > team_board
- **الإجراءات:** عرض المهام · عرض الملاحظات · إنشاء · تعديل · حذف · عرض المحذوفات · استعادة · إدارة المرفقات · بدء المهمة السريعة · تحديد جاهز · تحديد منجز
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/tools.php`

### 34. المحادثات — Chat
- **المسار:** `admin.chat.index` (GET)
- **الصلاحية:** `chat.view`
- **القائمة:** `config/menu/tools.php` → tools > chat > chat
- **الإجراءات:** عرض · إنشاء محادثة · إرسال رسالة
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/tools.php`

### 35. تقرير المحادثات — Chat Report
- **المسار:** `admin.chat.reports.index` (GET)
- **الصلاحية:** `chat.reports.view`
- **القائمة:** `config/menu/tools.php` → tools > chat > chat_report
- **الإجراءات:** عرض · تصدير · PDF · طباعة
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/tools.php`

### 36. إعدادات تطبيق الويب — PWA Settings
- **المسار:** `admin.settings.pwa` (GET/POST)
- **الصلاحية:** `settings.pwa.view`
- **القائمة:** `config/menu/tools.php` → tools > pwa_settings
- **الإجراءات:** عرض · تحديث
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `config/menu/tools.php`

---

## ▸ الوحدة 3 (تتمة): شاشات مشتركة — Core (Shared Screens)

### 37. العملات — Currencies
- **المسار:** `admin.currencies.index` (GET)
- **الصلاحية:** — (يُراجع)
- **القائمة:** — (يُراجع ترتيب القائمة)
- **الإجراءات:** — (يُراجع)
- **الحالة:** ⚠️ مُنفّذ لكنه يتطلب مراجعة (يُراجع)
- **الكود المرجعي:** `modules/Core/Routes/web.php`

### 38. البحث في التنقل — Navigation Search
- **المسار:** مدمج في واجهة ERP (مسار مدمج)
- **الصلاحية:** — (عامة للمستخدمين المسجّلين)
- **القائمة:** — (أداة مدمجة في الشريط العلوي)
- **الإجراءات:** —
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php`

### 39. الإشعارات — Notifications
- **المسار:** مدمج في واجهة ERP (مسار مدمج)
- **الصلاحية:** — (عامة للمستخدمين المسجّلين)
- **القائمة:** — (أداة مدمجة في الشريط العلوي)
- **الإجراءات:** —
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php`

### 40. السياق التشغيلي — Operating Context
- **المسار:** مدمج في واجهة ERP (تبديل الشركة/الفرع/الفترة)
- **الصلاحية:** — (مُحكم بالصلاحيات عبر `OperatingScopeAccessService`)
- **القائمة:** — (أداة مدمجة في الشريط العلوي)
- **الإجراءات:** —
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php` · `OperatingContextService` · `OperatingScopeAccessService`

### 41. تقرير بيانات المنتجات — Product Data Report
- **المسار:** `admin.core.product-data-report` (GET)
- **الصلاحية:** — (يُراجع)
- **القائمة:** — (يُراجع)
- **الإجراءات:** — (يُراجع)
- **الحالة:** ⚠️ مُنفّذ لكنه يتطلب مراجعة (يُراجع)
- **الكود المرجعي:** `modules/Core/Routes/web.php`

### 42. الأرشيف العام — Public Archive
- **المسار:** `public.archive` (GET)
- **الصلاحية:** — (عامة)
- **القائمة:** — (شاشة مستقلة)
- **الإجراءات:** —
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Core/Routes/web.php`

---

## ▸ الوحدة 4: المبيعات — Sales

### 43. العملاء — Customers
- **المسار:** `admin.sales.customers.index` (GET)
- **الصلاحية:** `customers.view`
- **القائمة:** `config/menu/sales.php` → sales > customers
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Sales/Routes/web.php` · `config/menu/sales.php`

### 44. شروط وأحكام العملاء — Customer Terms and Conditions
- **المسار:** `admin.sales.customer-terms.index` (GET)
- **الصلاحية:** `customers.view`
- **القائمة:** `config/menu/sales.php` → sales > customer_terms
- **الإجراءات:** عرض · تعديل
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Sales/Routes/web.php` · `config/menu/sales.php`

### 45. قوائم الأسعار — Price Lists
- **المسار:** `admin.sales.price-lists.index` (GET)
- **الصلاحية:** `price_lists.view`
- **القائمة:** `config/menu/sales.php` → sales > price_lists
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · طباعة · تصدير · مراجعة · موافقة
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Sales/Routes/web.php` · `config/menu/sales.php`

### 46. طلبات البيع — Sales Requests
- **المسار:** `admin.sales.customer-requests.index` (GET)
- **الصلاحية:** `sales_requests.view`
- **القائمة:** `config/menu/sales.php` → sales > sales_requests
- **الإجراءات:** — (يُراجع)
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Sales/Routes/web.php` · `config/menu/sales.php`

### 47. عروض الأسعار — Quotations
- **المسار:** `admin.sales.quotations.index` (GET)
- **الصلاحية:** `quotations.view`
- **القائمة:** `config/menu/sales.php` → sales > quotations
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · التحكم برقم المستند · عرض التنقيحات · إنشاء تنقيح · تحديد كمرسل · قبول · رفض · إلغاء · طباعة · إدارة المرفقات
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Sales/Routes/web.php` · `config/menu/sales.php`

### 48. أوامر البيع — Sales Orders
- **المسار:** `admin.sales.sales-orders.index` (GET)
- **الصلاحية:** `sales_orders.view`
- **القائمة:** `config/menu/sales.php` → sales > sales_orders
- **الإجراءات:** — (يُراجع)
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Sales/Routes/web.php` · `config/menu/sales.php`

### 49. فواتير المبيعات — Sales Invoices
- **المسار:** `admin.sales.sales-invoices.index` (GET)
- **الصلاحية:** `customer_invoices.view`
- **القائمة:** `config/menu/sales.php` → sales > sales_invoices
- **الإجراءات:** حذف · تخصيص الرصيد الدائن · استرداد الرصيد الدائن · تقديم الفاتورة الإلكترونية
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Sales/Routes/web.php` · `config/menu/sales.php`

### 50. أوامر الصرف / التسليم — Issue Orders (Deliveries)
- **المسار:** `admin.sales.delivery-notes.index` (GET)
- **الصلاحية:** `sales_deliveries.view`
- **القائمة:** `config/menu/sales.php` → sales > deliveries
- **الإجراءات:** — (يُراجع)
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Sales/Routes/web.php` · `config/menu/sales.php`

### 51. تحصيل العملاء — Customer Collections
- **المسار:** `admin.sales.customer-receipts.index` (GET)
- **الصلاحية:** `customer_receipts.view`
- **القائمة:** `config/menu/sales.php` → sales > customer_collections
- **الإجراءات:** — (يُراجع)
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Sales/Routes/web.php` · `config/menu/sales.php`

### 52. مرتجعات المبيعات — Sales Returns
- **المسار:** `admin.sales.sales-returns.index` (GET)
- **الصلاحية:** `sales_returns.view`
- **القائمة:** `config/menu/sales.php` → sales > sales_returns
- **الإجراءات:** — (يُراجع)
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Sales/Routes/web.php` · `config/menu/sales.php`

### 53. كشف حساب العميل — Customer Statement
- **المسار:** `admin.accounting.reports.customer-statement` (GET)
- **الصلاحية:** `reports.customer_statement.view`
- **القائمة:** `config/menu/sales.php` → sales > sales_reports > customer_statement
- **الإجراءات:** عرض · تصدير
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Sales/Routes/web.php` · `config/menu/sales.php`

### 54. تقارير المبيعات — Sales Reports
- **المسار:** `admin.reports.sales.sales-orders.index` (GET) مع بارامتر `report`
- **الصلاحية:** `reports.sales.sales_orders.view`
- **القائمة:** `config/menu/sales.php` → sales > sales_reports
- **الإجراءات:** عرض · تصدير · طباعة (حسب نوع التقرير)
- **أنواع التقارير:** التحليل المالي · المبيعات حسب الفترة · المبيعات حسب العميل · المبيعات حسب المنتج · تقرير فواتير المبيعات · أرصدة وتقاعس العملاء · توقعات التحصيل · تحليل مرتجعات المبيعات · مسار عروض الأسعار · تنفيذ أوامر البيع · تغطية التسعير · نظرة عامة تشغيلية · تكلفة المبيعات
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Sales/Routes/web.php` · `config/menu/sales.php`

---

## ▸ الوحدة 5: المشتريات — Purchases

### 55. الموردون — Suppliers
- **المسار:** `admin.purchases.suppliers.index` (GET)
- **الصلاحية:** `suppliers.view`
- **القائمة:** `config/menu/purchases.php` → purchases > suppliers
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Purchases/Routes/web.php` · `config/menu/purchases.php`

### 56. طلبات الشراء — Purchase Requisitions
- **المسار:** `admin.purchases.purchase-requisitions.index` (GET)
- **الصلاحية:** `purchases.purchase_requisitions.view`
- **القائمة:** `config/menu/purchases.php` → purchases > purchase_requisitions
- **الإجراءات:** عرض · إنشاء · تعديل · حذف · عرض المحذوفات · استعادة · تقديم · موافقة · رفض · إلغاء · إغلاق · طباعة
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Purchases/Routes/web.php` · `config/menu/purchases.php`

### 57. عروض أسعار الموردين — Supplier Quotations
- **المسار:** `admin.purchases.supplier-quotation-entry.index` (GET)
- **الصلاحية:** `purchases.supplier_quotation_entry.view`
- **القائمة:** `config/menu/purchases.php` → purchases > supplier_quotations
- **الإجراءات:** عرض · إنشاء · تعديل · حذف · عرض المحذوفات · استعادة · طباعة · عرض الأسعار
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Purchases/Routes/web.php` · `config/menu/purchases.php`

### 58. أوامر الشراء — Purchase Orders
- **المسار:** `admin.purchases.purchase-orders.index` (GET)
- **الصلاحية:** `purchase_orders.view`
- **القائمة:** `config/menu/purchases.php` → purchases > purchase_orders
- **الإجراءات:** عرض · إنشاء · تعديل · حذف · عرض المحذوفات · استعادة · تقديم · موافقة · رفض · إرسال · إغلاق · إلغاء · طباعة · عرض الأسعار · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Purchases/Routes/web.php` · `config/menu/purchases.php`

### 59. أوامر التوريد — Supply Orders
- **المسار:** `admin.purchases.supply-orders.index` (GET)
- **الصلاحية:** `purchases.supply_orders.view`
- **القائمة:** `config/menu/purchases.php` → purchases > supply_orders
- **الإجراءات:** عرض · إنشاء · تعديل · حذف · صرف · إلغاء · طباعة
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Purchases/Routes/web.php` · `config/menu/purchases.php`

### 60. فحص المشتريات — Purchase Inspections
- **المسار:** `admin.purchases.goods-receipt-inspection.index` (GET)
- **الصلاحية:** `purchases.goods_receipt_inspection.view`
- **القائمة:** `config/menu/purchases.php` → purchases > purchase_inspections
- **الإجراءات:** عرض · إنشاء · طباعة
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Purchases/Routes/web.php` · `config/menu/purchases.php`

### 61. أذون الاستلام — Goods Receipt Notes
- **المسار:** `admin.purchases.goods-receipt-notes.index` (GET)
- **الصلاحية:** `purchases.goods_receipt_notes.view`
- **القائمة:** `config/menu/purchases.php` → purchases > goods_receipts
- **الإجراءات:** عرض · إنشاء · تعديل · حذف · عرض المحذوفات · استعادة · ترحيل · إلغاء ترحيل · طباعة
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Purchases/Routes/web.php` · `config/menu/purchases.php`

### 62. فواتير المشتريات — Purchase Invoices
- **المسار:** `admin.purchases.purchase-invoices.index` (GET)
- **الصلاحية:** `purchase_invoices.view`
- **القائمة:** `config/menu/purchases.php` → purchases > purchase_invoices
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · موافقة · إغلاق · إلغاء · إلغاء ترحيل · طباعة · تجاوز الشراء المباشر · عرض الأسعار · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Purchases/Routes/web.php` · `config/menu/purchases.php`

### 63. مرتجعات المشتريات — Purchase Returns
- **المسار:** `admin.purchases.purchase-returns.index` (GET)
- **الصلاحية:** `purchases.purchase_returns.view`
- **القائمة:** `config/menu/purchases.php` → purchases > purchase_returns
- **الإجراءات:** عرض · إنشاء · تعديل · حذف · عرض المحذوفات · استعادة · ترحيل · إلغاء ترحيل · طباعة
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Purchases/Routes/web.php` · `config/menu/purchases.php`

### 64. مدفوعات الموردين — Supplier Payments
- **المسار:** `admin.purchases.supplier-payments.index` (GET)
- **الصلاحية:** `supplier_payments.view`
- **القائمة:** `config/menu/purchases.php` → purchases > supplier_payments
- **الإجراءات:** عرض · إنشاء · موافقة · إلغاء · طباعة
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Purchases/Routes/web.php` · `config/menu/purchases.php`

### 65. كشف حساب المورد — Supplier Statement
- **المسار:** `admin.accounting.reports.supplier-statement` (GET)
- **الصلاحية:** `reports.supplier_statement.view`
- **القائمة:** `config/menu/purchases.php` → purchases > purchase_reports > supplier_statement
- **الإجراءات:** عرض · تصدير
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Purchases/Routes/web.php` · `config/menu/purchases.php`

### 66. تقرير الموردين — Suppliers Report
- **المسار:** `admin.reports.suppliers.index` (GET)
- **الصلاحية:** `reports.suppliers.view`
- **القائمة:** `config/menu/purchases.php` → purchases > purchase_reports > suppliers_report
- **الإجراءات:** عرض · تصدير · PDF
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Purchases/Routes/web.php` · `config/menu/purchases.php`

### 67. تقارير المشتريات — Purchase Reports (23+ تقرير)
- **المسار:** `admin.purchases.procurement-cycle-report.index` (GET) مع بارامتر `report_type`
- **الصلاحية:** `reports.purchases.view`
- **القائمة:** `config/menu/purchases.php` → purchases > purchase_reports
- **الإجراءات:** عرض · تصدير (حسب نوع التقرير)
- **أنواع التقارير:** دفتر المشتريات · متطلبات الشراء المفتوحة · تقارير طلبات الشراء · طلبات الشراء المعلقة · المطلوب مقابل المطلوب · حالة طلبات العرض/التسعير · الإجراءات المعلقة · أوامر الشراء المفتوحة · حالة أوامر الشراء · أوامر الاستلام الجزئي · تأخر التوصيل · تقارير أوامر التوريد · جدول التوصيل · المطلوب مقابل المستلم · توصيلات الموردين · نتائج الفحص · الفحص المقبول المعلق · رفض الفحص · أذون الاستلام · فواتير المشتريات · المستلم مقابل المفوتر · مرتجعات المشتريات · purchases by supplier · by item · by category · by warehouse · by period · price history · outstanding · installments · aging · upcoming · GRNI · production analysis
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Purchases/Routes/web.php` · `config/menu/purchases.php`

---

## ▸ الوحدة 6: المخزون — Inventory

### 68. استعلام الأرصدة — Stock Balance Inquiry
- **المسار:** `admin.inventory.stock-balances.index` (GET)
- **الصلاحية:** `inventory.reports.operational`
- **القائمة:** `config/menu/inventory.php` → inventory > inventory_stock_balance_inquiry
- **الإجراءات:** عرض (تشغيلي) · عرض (مالي) · تصدير
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Inventory/Routes/web.php` · `config/menu/inventory.php`

### 69. حركات المخزون — Inventory Movements
- **المسار:** `admin.inventory.documents.index` (GET)
- **الصلاحية:** `inventory.documents.view`
- **القائمة:** `config/menu/inventory.php` → inventory > inventory_movements
- **الإجراءات:** عرض · إنشاء · تعديل · استنساخ · حذف · عرض المحذوفات · استعادة · ترحيل · استلام · صرف · مرتجع · نقل · تقييم · تلف/إتلاف · إلغاء ترحيل · طباعة
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Inventory/Routes/web.php` · `config/menu/inventory.php`

### 70. التقارير التشغيلية للمخزون — Inventory Operational Reports
- **المسار:** `admin.inventory.reports.index` (GET)
- **الصلاحية:** `inventory.reports.operational`
- **القائمة:** `config/menu/inventory.php` → inventory > inventory_operational_reports
- **الإجراءات:** عرض (تشغيلي) · عرض (مالي) · تصدير
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Inventory/Routes/web.php` · `config/menu/inventory.php`

### 71. مقارنة تقييم المخزون — Inventory Valuation Comparison
- **المسار:** `admin.inventory.reports.valuation` (GET)
- **الصلاحية:** `inventory.reports.financial`
- **القائمة:** `config/menu/inventory.php` → inventory > inventory_valuation_report
- **الإجراءات:** عرض (مالي)
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Inventory/Routes/web.php` · `config/menu/inventory.php`

### 72. تقييم المخزون بسعر البيع — Inventory Sales Valuation
- **المسار:** `admin.inventory.sales-valuation` (GET)
- **الصلاحية:** `inventory.reports.operational`
- **القائمة:** `config/menu/inventory.php` → inventory > inventory_sales_valuation_report
- **الإجراءات:** عرض · تصدير
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Inventory/Routes/web.php` · `config/menu/inventory.php`

### 73. تقرير استلام الإنتاج التام — Finished Goods Receipts Report
- **المسار:** `admin.production.reports.receipts` (GET)
- **الصلاحية:** `production.reports.operational`
- **القائمة:** `config/menu/inventory.php` → inventory > production_reports_receipts
- **الإجراءات:** — (يُراجع)
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Inventory/Routes/web.php` · `config/menu/inventory.php`

### 74. جرد المخزون الفعلي — Physical Stock Counts
- **المسار:** `admin.inventory.stock-counts.index` (GET)
- **الصلاحية:** `inventory.stock_counts.view`
- **القائمة:** `config/menu/inventory.php` → inventory > inventory_stock_counts
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · موافقة · طباعة · تصدير · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Inventory/Routes/web.php` · `config/menu/inventory.php`

### 75. مخزون أول المدة — Opening Stock
- **المسار:** `admin.inventory.opening-stocks.index` (GET)
- **الصلاحية:** `inventory.opening_stocks.view`
- **القائمة:** `config/menu/inventory.php` → inventory > opening_stocks
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · موافقة · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Inventory/Routes/web.php` · `config/menu/inventory.php`

### 76. أذون استلام المخزون بدون أسعار — Unpriced Inventory Receipts
- **المسار:** `admin.inventory.unpriced-inventory-receipts.index` (GET)
- **الصلاحية:** `inventory.unpriced_inventory_receipts.view`
- **القائمة:** `config/menu/inventory.php` → inventory > unpriced_inventory_receipts
- **الإجراءات:** عرض · إنشاء · تعديل · حذف · عرض المحذوفات · استعادة · موافقة · إغلاق · إلغاء · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Inventory/Routes/web.php` · `config/menu/inventory.php`

### 77. تسعير مخزون أول المدة — Opening Stock Pricing
- **المسار:** `admin.inventory.opening-stock-pricings.index` (GET)
- **الصلاحية:** `inventory.opening_stock_pricings.view`
- **القائمة:** `config/menu/inventory.php` → inventory > opening_stock_pricings
- **الإجراءات:** عرض · إنشاء · استنساخ · تعديل · حذف · عرض المحذوفات · استعادة · التحكم برقم المستند
- **الحالة:** ✅ مُنفّذ
- **الكود المرجعي:** `modules/Inventory/Routes/web.php` · `config/menu/inventory.php`

---

## ▸ وحدات خارج النطاق — Out-of-Scope Modules (مؤشرات فقط)

| الوحدة | المسار العام | الحالة |
|---|---|---|
| المحاسبة — Accounting | `modules/Accounting` | موجود (خارج نطاق هذا الملف) |
| المالية — Finance | `modules/Finance` | موجود (خارج نطاق هذا الملف) |
| الأصول الثابتة — Fixed Assets | `modules/FixedAssets` | موجود (خارج نطاق هذا الملف) |
| الموارد البشرية — HR | `modules/HR` | موجود (خارج نطاق هذا الملف) |
| الإنتاج — Production | `modules/Production` | موجود (خارج نطاق هذا الملف) |
| الجودة — Quality | `modules/Quality` | موجود (خارج نطاق هذا الملف) |
| الصيانة — Maintenance | `modules/Maintenance` | موجود (خارج نطاق هذا الملف) |

---

## ملخص العدّ — Footer Counts

| الوحدة | عدد الشاشات |
|---|---|
| المصادقة — Auth | 12 |
| لوحة التحكم — Dashboard | 1 |
| البيانات الأساسية والمشتركة — Core (Basic + Shared) | 30 |
| المبيعات — Sales | 12 |
| المشتريات — Purchases | 13 |
| المخزون — Inventory | 10 |
| **المجموع** | **78** |
