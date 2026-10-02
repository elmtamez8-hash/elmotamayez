# البدء السريع: تشغيل السبّورة (039) والتحقّق منها على Windows

**قبل أن تبدأ:** كان القرص C: ممتلئاً أثناء البحث (0 بايت متاحة). وnpm وDocker يكتبان عليه، فحرِّر بضعة غيغابايتات أولاً.

## المرحلة 0: التجربة الاستكشافية (بلا قاعدة بيانات، بلا Laravel)

```powershell
# The branch
git switch 039-teacher-whiteboard

# Install Excalidraw only, with the exact version (after the owner approves)
cd frontend
npm install --save-exact @excalidraw/excalidraw@0.18.1
npm run dev                    # predev copies dist/prod/fonts → public/excalidraw/fonts; never at the same time as npm run build
```

افتح `http://localhost:3000/lab/whiteboard`. هي صفحة تجربة على **فرع تجربة لا يُدمَج**، فلا تُشحَن ولا رابط إليها من التطبيق.

### قائمة الفحص اليدوي للمالك (ما لا يمكن أتمتته)

| # | الاختبار | ينجح حين |
|---|---|---|
| 1 | اكتب «الماء H₂O يغلي عند 100 درجة» | الحروف موصولة · المؤشّر يتحرّك صحيحاً · السطر يلتفّ داخل المربّع |
| 2 | اكتب سطراً **يبدأ** بالإنجليزية تتبعه العربية | الاتّجاه يتبع الكلمة الأولى (السلوك الطبيعي)، وهو واضح |
| 3 | صدِّر PNG وSVG وافتحهما في متصفّح آخر | تظهر العربية تماماً كما على الشاشة |
| 4 | ارسم بقلم اللوح الرقمي، بضغط خفيف ثم ثقيل | يتغيّر سُمك الخطّ |
| 5 | جرِّب الخلفيات الثلاث | لون القلم الافتراضي مقروء على كلٍّ منها |
| 6 | ادخل جلسة تجريبية كمدرّس (صفحة الغرفة) → شارِك **تبويب السبّورة** → افتح الجلسة نفسها كطالب على **هاتف Android متواضع عبر بيانات الجوّال** | كل كلمة بالحجم الافتراضي مقروءة (SC-001) |
| 7 | ارسم متواصلاً 30 دقيقة أثناء المشاركة، ومدير المهامّ في Chrome مفتوح | لا تأخّر · الذاكرة لا تصعد بلا توقّف (SC-006) |
| 8 | تنقّل بين 10 صفحات بسرعة | كل صفحة تملأ الإطار في أقلّ من ثانية |

سجِّل النتائج في تقرير المرحلة 0 (القيد 9).

## المرحلة 1: تشغيل الميزة كاملة

```powershell
# 1. The conversion service (Docker Desktop)
docker compose -f docker/docker-compose.yml up -d gotenberg

# 2. poppler on Windows (for local work only; production installs it inside the image)
winget install --id=oschwartz10612.Poppler -e   # or scoop install poppler
# then set WHITEBOARD_POPPLER_PATH in backend/.env to the folder with pdftoppm.exe

# 3. The backend: a plain migrate (never migrate:fresh)
cd backend
php artisan migrate
php artisan queue:work --queue=whiteboard,default   # local QUEUE_CONNECTION=database; production runs supervisor-whiteboard on redis-long (one process)
```

> سطر `winget` أعلاه: (المعرّف غير مُتحقَّق منه — بديل مؤكَّد: تنزيل إصدار Windows من https://github.com/oschwartz10612/poppler-windows/releases وفكّه)

**الفحوص:**

| السيناريو | الأمر أو الخطوات | النتيجة المتوقّعة |
|---|---|---|
| العزل بين مساحات العمل | `php vendor/bin/pest tests/Feature/Whiteboard/BoardIsolationTest.php` | كل باب للسبّورة يُجيب 404 لمساحة عمل أخرى |
| القفل التفاؤلي | `pest --filter="version conflict"` | الحفظ القديم يتلقّى 409، ولا يُكتَب فوق شيء |
| قفل التحرير | `pest --filter="board lock"` | التبويب الثاني للقراءة فقط · صاحب السبّورة يأخذ التحرير · الانتهاء بعد 120 ثانية بلا نبضة · اختبار السباق يُدخل الكتابة المنافِسة داخل الفجوة |
| الأبواب المتداخلة | `pest --filter="nested"` | صفحة أو ملف أو استيراد من سبّورة أخرى في مساحة العمل نفسها → 404 |
| استيراد PDF من 50 صفحة | ارفع `e2e/fixtures/50-pages.pdf` من السبّورة (المجلّد `e2e/fixtures/` **يُنشأ**، فهو غير موجود اليوم) | 50 صفحة بالترتيب، في أقلّ من دقيقتين (SC-003) |
| ملف من 101 صفحة (PDF أو PPTX) | ارفع واحداً | يُقبَل الرفع، ثم ينتهي الاستيراد `status: failed`، `failure_reason: too_many_pages`، بلا صفحات جزئية. (الـ 422 قبل التذكرة للحجم وحده: `too_large`) |
| انقطاع الشبكة | DevTools → Offline → ارسم → Online | ينتقل المؤشّر إلى «بدون اتصال» ثم يعود إلى «محفوظ»، ولا يضيع شيء |
| إغلاق التبويب | ارسم، ثم أغلقه خلال ثانيتين، ثم أعد فتحه | تُعرَض المسودّة وتُستعاد |
| الإرفاق بدرس مرّتين | اضغط الزرّ مرّتين (كمدرّس الكورس) | مرفق واحد على الدرس، وهو الأحدث |
| استبدال بلا صلاحية حذف | كمساعد بلا `LESSONS_DELETE`، أرفق سبّورة أُرفقت من قبل | 403 «اطلب من مدرّس الكورس»، والمرفق القديم باقٍ |
| Playwright | `npm run test:e2e -- whiteboard.spec.ts` | الحفظ التلقائي/الاستعادة، والاستيراد، والتصدير، والنصّ العربي (SC-008) |

البوّابات المحلّية (بعد التغيير كلّه، مرّة واحدة): `pint --test`، و`phpstan analyse`، و`npx tsc --noEmit`، و`$env:TZ='UTC'; npx vitest run src/lib/whiteboard` (في PowerShell)، ومجلّد `Whiteboard` فقط من pest. والحزمة الكاملة تعمل على GitHub.
