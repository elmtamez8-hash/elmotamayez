# Marketplace image licences

كل صورة تُعرض على صفحة عامة **يجب** أن يكون لها سطر هنا قبل استخدامها. الاستضافة
ذاتية عمداً — روابط المصادر الخارجية الحيّة تنكسر أو تُحذف، والاعتماد عليها يجعل
الصفحة العامة رهينة لخدمة طرف ثالث.

الرخص المقبولة: **Unsplash License** و **Pexels License** (استخدام تجاري، بلا نسب
إجباري). النسب في الجدول أدناه ليس شرطاً قانونياً، بل هو ما يجعل السطر قابلاً
للتحقّق: اسم مصوّر ورابط صفحة أصلية يمكن فتحهما.

> ⚠️ **`plus.unsplash.com` ليس Unsplash License.** بحث Unsplash يخلط صور
> **Unsplash+** المدفوعة في نفس قائمة النتائج، ولا شيء في وصف الصورة يميّزها —
> النطاق وحده هو الدليل. خمس صور من أول اختيار في هذه الدفعة كانت مدفوعة
> واستُبدلت. أي تنزيل جديد يجب أن يبدأ رابطه بـ `images.unsplash.com`.

## الصفحات العامة — `frontend/public/marketplace/`

| الملف | الموضع | المقاس | الحجم | المصوّر | الصفحة الأصلية |
|---|---|---|---|---|---|
| `hero-study.webp` | البطل في الصفحة الرئيسية | 1600×1200 | 156 KB | Deddy Yoga Pratama | https://unsplash.com/photos/woman-in-gray-traditional-dress-reading-book-FgN3JKkW3hI |

نُزّلت جميعها في **٢٠٢٦-٠٨-١٢** بصيغة WebP وجودة 80، مقصوصة على مقاس الحاوية
النهائي (`fit=crop`) حتى لا يقفز التخطيط أثناء التحميل.

## بيانات العرض التجريبية — `backend/database/seeders/assets/`

هذه ليست أصول واجهة: `MarketplaceSeeder` ينسخها إلى `storage/app/public/marketplace`
ويكتب مسارها في `teacher_profiles.photo_path` و `courses.cover_path`، فتصل إلى
المتصفّح كأنها رفعُ مدرّسٍ حقيقي. وهي تخضع لنفس القاعدة تماماً — بل أكثر: صورة
منسوبة إلى **شخص مُخترَع** يجب ألّا تخرج من بيئة التطوير. الحارس أن السيدر
لا يعمل في `production` أصلاً (`seedDemoMarketplace`).

### صور المدرّسين — `assets/teachers/` (640×640، `crop=faces`)

| الملف | المدرّس التجريبي | المصوّر | الصفحة الأصلية |
|---|---|---|---|
| `ahmed-almansouri.webp` | أحمد المنصوري — رياضيات وفيزياء | Levi Meir Clancy | https://unsplash.com/photos/man-in-white-dress-shirt-wearing-red-and-white-hijab-ruWf1KGPPsY |
| `fatima-alhashimi.webp` | فاطمة الهاشمي — لغة عربية | Damon Zaidmus | https://unsplash.com/photos/woman-wearing-white-dress-and-white-hijab-scarf-7ncPcGL60-s |
| `sara-alotaibi.webp` | سارة العتيبي — كيمياء وأحياء | irwan wahyudi | https://unsplash.com/photos/woman-in-blue-hijab-smiling-O1z8oNDidaM |
| `khaled-aldosari.webp` | خالد الدوسري — لغة إنجليزية | Vitaly Gariev | https://unsplash.com/photos/a-smiling-man-with-a-beard-wearing-a-collared-shirt-4HNK1yce-Vg |
| `mona-albalushi.webp` | منى البلوشي — حاسب آلي | Lisa Marie Theck | https://unsplash.com/photos/a-woman-in-a-headscarf-smiles-at-the-camera-6awpFbYse3o |

### أغلفة الكورسات — `assets/courses/` (1280×720)

| الملف | الكورس | المصوّر | الصفحة الأصلية |
|---|---|---|---|
| `mathematics.webp` | الرياضيات للثانوية العامة | Erwan Hesry | https://unsplash.com/photos/blackboard-with-complex-mathematical-formulas-and-symbols-aTpgq-1PdrU |
| `arabic-grammar.webp` | النحو العربي المبسّط | The Cleveland Museum of Art | https://unsplash.com/photos/an-ornate-manuscript-with-gold-arabic-calligraphy-on-parchment-DeY0NCS4BpE |
| `chemistry.webp` | الكيمياء العضوية من الصفر | Egor Myznik | https://unsplash.com/photos/laboratory-glassware-sits-on-a-dark-illuminated-table-C5pAjKmUSJE |
| `english.webp` | اللغة الإنجليزية للمحادثة | Priscilla Du Preez | https://unsplash.com/photos/brown-and-red-books-on-white-surface-ZkR9yT1cR7g |
| `python.webp` | أساسيات البرمجة بلغة بايثون | Mohammad Rahmani | https://unsplash.com/photos/laptop-screen-displaying-colorful-code-8qEB0fTe9Vw |

## لماذا لا توجد صور للطلاب المراجِعين

نُزّلت ستّ صور شخصية لهذا الغرض ثم **حُذفت قبل الإيداع**. السبب مكتوب في
`PublicFieldAllowlist::REVIEW`: `Review::studentDisplayName()` يقصّ اسم العائلة
عمداً حتى لا يتعرّف المدرّس على من قيّمه للتوّ (FR-021)، وصورةُ وجهٍ بجانب الاسم
المقصوص تُلغي ذلك القصّ بالكامل. الوجه الذي يظهر بجانب الاقتباس هو وجه **المدرّس**
الذي تتحدّث عنه المراجعة — وهو منشور أصلاً على بطاقته وملفّه.

## الأيقونات والعلامة

أيقونات Heroicons (رخصة MIT) منسوخة كمسارات SVG داخل `src/components/icons/`.
`brand/wordmark-mask.png` مشتقّ من ملف اللوجو الذي زوّده مالك المنتج — ملكيّته له،
وليست له علاقة بهذا الجدول.
