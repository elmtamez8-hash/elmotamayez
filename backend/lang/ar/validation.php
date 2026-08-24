<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| رسائل التحقّق بالعربية
|--------------------------------------------------------------------------
|
| المنتج عربي فقط، فالرسائل تُترجَم هنا عند مصدرها بدل مطابقة نصوص إنجليزية في
| الواجهة — راجع specs/002-arabic-rtl-app-shell/contracts/error-messages.md.
|
| شكل الاستجابة لم يتغيّر: `{message, errors:{field:[…]}}` كما هو. المتغيّر هو
| نصّ الرسالة وحده، وهو نصّ معروض للمستخدم لا حقل يُعتمَد عليه برمجياً.
|
| مصفوفة `attributes` في آخر الملف هي جوهر FR-016: بها يصير `:attribute` عربياً
| في كل رسالة تلقائياً. حقل بلا مدخل هناك يظهر باسمه البرمجي — عطل صامت.
|
*/

return [
    'accepted' => 'يجب قبول :attribute.',
    'accepted_if' => 'يجب قبول :attribute عندما يكون :other هو :value.',
    'active_url' => ':attribute ليس رابطاً صحيحاً.',
    'after' => 'يجب أن يكون :attribute تاريخاً بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute تاريخاً بعد أو يساوي :date.',
    'alpha' => 'يجب ألا يحتوي :attribute إلا على حروف.',
    'alpha_dash' => 'يجب ألا يحتوي :attribute إلا على حروف وأرقام وشرطات.',
    'alpha_num' => 'يجب ألا يحتوي :attribute إلا على حروف وأرقام.',
    'array' => 'يجب أن يكون :attribute مصفوفة.',
    'ascii' => 'يجب ألا يحتوي :attribute إلا على حروف ورموز لاتينية.',
    'before' => 'يجب أن يكون :attribute تاريخاً قبل :date.',
    'before_or_equal' => 'يجب أن يكون :attribute تاريخاً قبل أو يساوي :date.',

    'between' => [
        'array' => 'يجب أن يحتوي :attribute على ما بين :min و:max عنصراً.',
        'file' => 'يجب أن يكون حجم :attribute بين :min و:max كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و:max.',
        'string' => 'يجب أن يكون طول :attribute بين :min و:max حرفاً.',
    ],

    'boolean' => 'يجب أن تكون قيمة :attribute إما صحيحة أو خاطئة.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => ':attribute ليس تاريخاً صحيحاً.',
    'date_equals' => 'يجب أن يكون :attribute تاريخاً يساوي :date.',
    'date_format' => 'صيغة :attribute غير مطابقة للصيغة :format.',
    'decimal' => 'يجب أن يحتوي :attribute على :decimal منزلة عشرية.',
    'declined' => 'يجب رفض :attribute.',
    'different' => 'يجب أن يختلف :attribute عن :other.',
    'digits' => 'يجب أن يتكوّن :attribute من :digits رقماً.',
    'digits_between' => 'يجب أن يتكوّن :attribute من :min إلى :max رقماً.',
    'dimensions' => 'أبعاد صورة :attribute غير صالحة.',
    'distinct' => 'قيمة :attribute مكرّرة.',
    'doesnt_end_with' => 'يجب ألا ينتهي :attribute بأحد التالي: :values.',
    'doesnt_start_with' => 'يجب ألا يبدأ :attribute بأحد التالي: :values.',
    'email' => 'يجب أن يكون :attribute بريداً إلكترونياً صحيحاً.',
    'ends_with' => 'يجب أن ينتهي :attribute بأحد التالي: :values.',
    'enum' => 'القيمة المختارة في :attribute غير صالحة.',
    'exists' => 'القيمة المختارة في :attribute غير موجودة.',
    'file' => 'يجب أن يكون :attribute ملفاً.',
    'filled' => 'حقل :attribute مطلوب.',

    'gt' => [
        'array' => 'يجب أن يحتوي :attribute على أكثر من :value عنصراً.',
        'file' => 'يجب أن يكون حجم :attribute أكبر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أكبر من :value.',
        'string' => 'يجب أن يكون طول :attribute أكبر من :value حرفاً.',
    ],

    'gte' => [
        'array' => 'يجب أن يحتوي :attribute على :value عنصراً أو أكثر.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أكبر.',
        'numeric' => 'يجب أن تكون قيمة :attribute :value أو أكبر.',
        'string' => 'يجب أن يكون طول :attribute :value حرفاً أو أكثر.',
    ],

    'image' => 'يجب أن يكون :attribute صورة.',
    'in' => 'القيمة المختارة في :attribute غير صالحة.',
    'in_array' => 'قيمة :attribute غير موجودة في :other.',
    'integer' => 'يجب أن يكون :attribute رقماً صحيحاً.',
    'ip' => 'يجب أن يكون :attribute عنوان IP صحيحاً.',
    'ipv4' => 'يجب أن يكون :attribute عنوان IPv4 صحيحاً.',
    'ipv6' => 'يجب أن يكون :attribute عنوان IPv6 صحيحاً.',
    'json' => 'يجب أن يكون :attribute نصّ JSON صحيحاً.',

    'lt' => [
        'array' => 'يجب أن يحتوي :attribute على أقل من :value عنصراً.',
        'file' => 'يجب أن يكون حجم :attribute أصغر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أصغر من :value.',
        'string' => 'يجب أن يكون طول :attribute أقل من :value حرفاً.',
    ],

    'lte' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :value عنصراً.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أصغر.',
        'numeric' => 'يجب أن تكون قيمة :attribute :value أو أقل.',
        'string' => 'يجب أن يكون طول :attribute :value حرفاً أو أقل.',
    ],

    'lowercase' => 'يجب أن يكون :attribute بحروف صغيرة.',
    'mac_address' => 'يجب أن يكون :attribute عنوان MAC صحيحاً.',

    'max' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :max عنصراً.',
        'file' => 'يجب ألا يزيد حجم :attribute على :max كيلوبايت.',
        'numeric' => 'يجب ألا تزيد قيمة :attribute على :max.',
        'string' => 'يجب ألا يزيد طول :attribute على :max حرفاً.',
    ],

    'max_digits' => 'يجب ألا يزيد :attribute على :max رقماً.',
    'mimes' => 'يجب أن يكون :attribute ملفاً من نوع: :values.',
    'mimetypes' => 'يجب أن يكون :attribute ملفاً من نوع: :values.',

    'min' => [
        'array' => 'يجب أن يحتوي :attribute على :min عنصراً على الأقل.',
        'file' => 'يجب ألا يقل حجم :attribute عن :min كيلوبايت.',
        'numeric' => 'يجب ألا تقل قيمة :attribute عن :min.',
        'string' => 'يجب ألا يقل طول :attribute عن :min حرفاً.',
    ],

    'min_digits' => 'يجب ألا يقل :attribute عن :min رقماً.',
    'missing' => 'يجب ألا يوجد حقل :attribute.',
    'multiple_of' => 'يجب أن تكون قيمة :attribute من مضاعفات :value.',
    'not_in' => 'القيمة المختارة في :attribute غير صالحة.',
    'not_regex' => 'صيغة :attribute غير صالحة.',
    'numeric' => 'يجب أن يكون :attribute رقماً.',

    'password' => [
        'letters' => 'يجب أن تحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن تحتوي :attribute على حرف كبير وحرف صغير.',
        'numbers' => 'يجب أن تحتوي :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن تحتوي :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهرت :attribute في تسريب بيانات معروف. اختر كلمة مرور أخرى.',
    ],

    'present' => 'يجب إرسال حقل :attribute.',
    'prohibited' => 'حقل :attribute ممنوع.',
    'prohibited_if' => 'حقل :attribute ممنوع عندما يكون :other هو :value.',
    'prohibited_unless' => 'حقل :attribute ممنوع ما لم يكن :other ضمن :values.',
    'prohibits' => 'وجود :attribute يمنع إرسال :other.',
    'regex' => 'صيغة :attribute غير صالحة.',
    'required' => 'حقل :attribute مطلوب.',
    'required_array_keys' => 'يجب أن يحتوي :attribute على المفاتيح: :values.',
    'required_if' => 'حقل :attribute مطلوب عندما يكون :other هو :value.',
    'required_if_accepted' => 'حقل :attribute مطلوب عند قبول :other.',
    'required_unless' => 'حقل :attribute مطلوب ما لم يكن :other ضمن :values.',
    'required_with' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_with_all' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_without' => 'حقل :attribute مطلوب عند غياب :values.',
    'required_without_all' => 'حقل :attribute مطلوب عند غياب :values جميعاً.',
    'same' => 'يجب أن يتطابق :attribute مع :other.',

    'size' => [
        'array' => 'يجب أن يحتوي :attribute على :size عنصراً بالضبط.',
        'file' => 'يجب أن يكون حجم :attribute :size كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute :size.',
        'string' => 'يجب أن يكون طول :attribute :size حرفاً.',
    ],

    'starts_with' => 'يجب أن يبدأ :attribute بأحد التالي: :values.',
    'string' => 'يجب أن يكون :attribute نصّاً.',
    'timezone' => 'يجب أن يكون :attribute منطقة زمنية صحيحة.',
    'unique' => ':attribute مستخدَم من قبل.',
    'uploaded' => 'فشل رفع :attribute.',
    'uppercase' => 'يجب أن يكون :attribute بحروف كبيرة.',
    'url' => 'يجب أن يكون :attribute رابطاً صحيحاً.',
    'ulid' => 'يجب أن يكون :attribute معرّف ULID صحيحاً.',
    'uuid' => 'يجب أن يكون :attribute معرّف UUID صحيحاً.',

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'رسالة مخصّصة',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | مسمّيات الحقول (FR-016)
    |--------------------------------------------------------------------------
    |
    | كل حقل يظهر في أي FormRequest تحت مجلدات الوحدات يجب أن يكون له مدخل هنا.
    | حقل ناقص يظهر للمستخدم باسمه البرمجي — عطل صامت لا تكشفه بوابة.
    |
    */

    'attributes' => [
        // الهوية والحساب
        'first_name' => 'الاسم الأول',
        'last_name' => 'اسم العائلة',
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'current_password' => 'كلمة المرور الحالية',
        'phone' => 'رقم الهاتف',
        'country' => 'الدولة',
        'age' => 'العمر',
        'token' => 'الرمز',
        'code' => 'رمز التحقق',
        'recovery_code' => 'رمز الاسترداد',
        'challenge' => 'طلب التحقق',
        'role' => 'الدور',
        'slug' => 'الرابط',
        'terms_accepted' => 'الموافقة على الشروط',
        'registered_by_parent' => 'التسجيل بواسطة وليّ الأمر',
        'child_uuid' => 'الابن',
        'student_uuid' => 'الطالب',
        'student_name' => 'اسم الطالب',
        'relation_type' => 'صفة الارتباط',
        'permissions' => 'الصلاحيات',
        'permissions.*' => 'الصلاحيات',
        'grade_level' => 'المرحلة الدراسية',
        'grade_level_slug' => 'المرحلة الدراسية',
        'grade_levels' => 'المراحل الدراسية',

        // التلعيب (٠٠٩)
        'minutes' => 'مدة الجلسة',
        'title' => 'العنوان',
        'price_coins' => 'السعر بالعملات',
        'stock' => 'المخزون',
        'monthly_cap' => 'السقف الشهري',
        'reward_type' => 'نوع المكافأة',
        'scope' => 'النطاق',
        'period' => 'الفترة',
        'workspace' => 'المدرّس',
        'xp' => 'الخبرة',
        'coins' => 'العملات',
        'daily_cap' => 'السقف اليومي',
        'name_ar' => 'الاسم',
        'xp_threshold' => 'عتبة الخبرة',
        'rule_type' => 'نوع القاعدة',
        'rule_value' => 'قيمة القاعدة',

        // الحصص المباشرة
        'teacher_profile_id' => 'المدرّس',
        'starts_at' => 'موعد البدء',
        'duration_minutes' => 'مدة الحصة',
        'seats_total' => 'عدد المقاعد',
        'slot_uuids' => 'أوقات التوفّر',
        'slot_uuids.*' => 'وقت التوفّر',
        'from' => 'من تاريخ',
        'to' => 'إلى تاريخ',
        'reason' => 'السبب',
        'entries' => 'التقييمات',
        'entries.*.student_uuid' => 'الطالب',
        'entries.*.rating' => 'التقييم',
        'entries.*.note' => 'الملاحظة',

        // مساحة العمل
        'slug' => 'المُعرِّف في الرابط',
        'type' => 'النوع',
        'settings' => 'الإعدادات',
        'workspace_id' => 'مساحة العمل',
        'defaults' => 'القيم الافتراضية',

        // الكورسات والدروس
        'title' => 'العنوان',
        'description' => 'الوصف',
        'price' => 'السعر',
        'price_minor' => 'السعر',
        'currency' => 'العملة',
        'language' => 'اللغة',
        'status' => 'الحالة',
        // سجلّ التحصيل (007): وسيلة الدفع مُصفٍّ لا حقل إدخال، لكن رسالة رفضه
        // تصل للمسؤول كما تصل أي رسالة تحقّق — وبلا هذا السطر تُقرأ «method».
        'method' => 'وسيلة الدفع',
        'order' => 'الترتيب',
        'course_id' => 'الكورس',
        'section_id' => 'القسم',
        'chapter_id' => 'الفصل',
        'media' => 'الملف',
        'duration_seconds' => 'المدة بالثواني',
        'is_free' => 'مجاني',
        'is_preview' => 'معاينة مجانية',
        'is_published' => 'منشور',
        'is_sequential' => 'التسلسل الإجباري',

        // الوسائط (spec 004)
        'file' => 'الملف',
        'original_filename' => 'اسم الملف',
        'size_bytes' => 'حجم الملف',
        'position_seconds' => 'موضع التشغيل',
        'kind' => 'نوع النصّ',
        'is_default' => 'الافتراضي',

        // الاختبارات
        'duration_minutes' => 'المدة بالدقائق',
        'passing_score' => 'درجة النجاح',
        'max_attempts' => 'أقصى عدد محاولات',
        'shuffle_questions' => 'خلط الأسئلة',
        'shuffle_answers' => 'خلط الإجابات',
        'content' => 'المحتوى',
        'explanation' => 'شرح الإجابة',
        'points' => 'الدرجة',
        'difficulty' => 'مستوى الصعوبة',
        'options' => 'الخيارات',
        'is_correct' => 'الإجابة الصحيحة',
        'answers' => 'الإجابات',
        'question_id' => 'السؤال',
        'selected_option_ids' => 'الخيارات المختارة',

        // بنك الأسئلة (‏008)
        'concept_id' => 'الفكرة',
        'lesson_id' => 'الدرس',
        'bloom_level' => 'المستوى المعرفي',
        'subject_id' => 'المادة',
        // `file` و`is_active` مُعرَّفان أصلاً أعلاه وأسفله؛ وتكرار المفتاح في
        // مصفوفة PHP يدهس الأول بالأخير بلا خطأ، فالمكرَّر هنا محذوف عمداً.
        'duplicate_policy' => 'سياسة التكرار',

        // السوق العام وملف المدرّس
        'headline' => 'العنوان التعريفي',
        'bio' => 'نبذة تعريفية',
        'subject' => 'المادة',
        // Spec 010 — الإشراف على الشات. ⚠️ `subject_uuid` وليس `subject`: الاسم
        // الثاني مأخوذٌ أعلاه لمادةٍ دراسيّة، ورسالةُ خطأٍ واحدةٌ لا يمكن أن تعني
        // «المادة» و«الشخص المعنيّ» معاً.
        'verdict' => 'القرار',
        'subject_type' => 'نوع الموضوع',
        'subject_uuid' => 'الشخص أو الرسالة',
        'expires_at' => 'تاريخ الانتهاء',
        /*
        | Spec 010 · US4 — التقييم الدوري وتقييم الطالب لمدرّسه.
        |
        | ⚠️ بلا مفتاحٍ اسمُه `rating`: نجمةُ التقييم مشتقّةٌ من المحاورِ الثلاثة ولا
        | تُرسَل، فمفتاحٌ لها هنا اسمُ حقلٍ لا يستطيع أحدٌ إرساله.
        */
        'student_uuid' => 'الطالب',
        'period_start' => 'بداية الفترة',
        'period_end' => 'نهاية الفترة',
        'commitment' => 'الالتزام',
        'participation' => 'المشاركة',
        'homework' => 'الواجبات',
        'improvement' => 'التحسّن',
        // أوزان التقدير (٠١٠ · US5). `weights.*` يُصيَّر بمفتاحِ المكوّن، ولذلك
        // للمكوّناتِ الأربعةِ أسماؤها هنا — و`participation` مذكورٌ سلفاً أعلاه.
        'weights' => 'الأوزان',
        'exams' => 'الاختبارات',
        'attendance' => 'الحضور',
        'course_uuid' => 'الكورس',

        // الإعلانات (٠١٠ · US6). `body` و`scope` مذكوران سلفاً في هذا الملفّ،
        // وهذان وحدَهما جديدان — وبلا مدخلٍ هنا يُصيَّر الحقلُ `scope_uuid` كما هو
        // في وجهِ المدرّس.
        'scope_uuid' => 'الكورس أو الحصّة',
        'is_urgent' => 'إعلان عاجل',
        'punctuality' => 'الالتزام بالمواعيد',
        'clarity' => 'جودة الشرح',
        'engagement' => 'التفاعل',
        'subjects' => 'المواد',
        'qualifications' => 'المؤهّلات',
        'years_experience' => 'سنوات الخبرة',
        'hourly_rate' => 'سعر الساعة',
        'teaching_languages' => 'لغات التدريس',
        'availability' => 'أوقات التوفّر',
        'available_now' => 'متاح الآن',
        'day_of_week' => 'اليوم',
        'start_time' => 'وقت البدء',
        'end_time' => 'وقت الانتهاء',
        'documents_acknowledged' => 'إقرار المستندات',
        'teacher' => 'المدرّس',
        'rating' => 'التقييم',
        'comment' => 'التعليق',

        // البحث والتصفية
        'q' => 'كلمة البحث',
        'page' => 'رقم الصفحة',
        'per_page' => 'عدد النتائج في الصفحة',
        'sort' => 'الترتيب',
        'price_min' => 'أقل سعر',
        'price_max' => 'أعلى سعر',
        'min_rating' => 'أقل تقييم',
        'min_trust_score' => 'أقل درجة ثقة',
        'category_id' => 'التصنيف',
        'tag_ids' => 'الوسوم',

        // مرفقات المحادثة (010 · FR-060)
        'kind' => 'نوع المرفق',
        'filename' => 'اسم الملف',
        'size_bytes' => 'حجم الملف',
        'duration_seconds' => 'مدّة التسجيل',
        'attachment' => 'المرفق',

        // المحتوى والمدوّنة
        'body' => 'النصّ',
        'excerpt' => 'المقتطف',
        'seo_title' => 'عنوان محرّكات البحث',
        'seo_description' => 'وصف محرّكات البحث',
        'canonical_url' => 'الرابط الأساسي',
        'html_template' => 'قالب HTML',

        // المدفوعات
        'receipt' => 'الإيصال',
        'rejection_reason' => 'سبب الرفض',
        'amount' => 'المبلغ',
        'amount_minor' => 'المبلغ',
        'provider' => 'مزوّد الدفع',

        // الشهادات
        'verification_code' => 'رمز التحقّق',

        // الإشعارات
        'preferences' => 'تفضيلات الإشعارات',
        'preferences.*.type' => 'نوع الإشعار',
        'preferences.*.channels' => 'قنوات الإشعار',
        'preferences.*.channels.*' => 'قناة الإشعار',
        'preferences.*.digest_window_minutes' => 'فترة التجميع',
        'channels' => 'القنوات',
        'channel' => 'القناة',
        'quiet_hours_start' => 'بداية فترة الهدوء',
        'quiet_hours_end' => 'نهاية فترة الهدوء',
        'timezone' => 'المنطقة الزمنية',
        'contact_value' => 'وسيلة التواصل',
        'code' => 'رمز التحقّق',

        // الفوترة والأرصدة (006). حقل بلا مدخل هنا يُعرَض للمستخدم باسمه البرمجي.
        'mode' => 'نمط الفوترة',
        'cadence' => 'دورة الدفع',
        // ⚠️ الأسماء هي أسماء الحقول في `UpdateBillingSettingsRequest` حرفياً.
        // كانت `zero_behavior` و`thresholds` وهما لا يطابقان أي حقل، فكانت
        // الرسالة تُعرَض بالاسم البرمجي — وهو العطل الصامت الذي تحذّر منه ترويسة
        // هذا الملف.
        'zero_balance_behavior' => 'السلوك عند نفاد الرصيد',
        'alert_thresholds' => 'عتبات التنبيه',
        'alert_thresholds.*' => 'عتبة التنبيه',
        'course' => 'الكورس',
        'student' => 'الطالب',
        'credit_package' => 'حزمة الأرصدة',
        'package' => 'الحزمة',
        'credits' => 'عدد الأرصدة',
        'session_type' => 'نوع الحصة',
        'validity_days' => 'مدة الصلاحية بالأيام',
        'is_active' => 'حالة التفعيل',
        'sort_order' => 'ترتيب العرض',
        'reason' => 'السبب',
        'idempotency_key' => 'مفتاح التعامد',
        'credit_limit_credits' => 'الحد الائتماني',
        'starts_on' => 'تاريخ البداية',
        'ends_on' => 'تاريخ النهاية',
        'document' => 'وثيقة الشروط',
        'version' => 'نسخة الشروط',
        'accepted' => 'الموافقة على الشروط',
        'operating_fee_minor' => 'رسم التشغيل',
        'operating_fee_minor.individual' => 'رسم تشغيل الحصة الفردية',
        'operating_fee_minor.group' => 'رسم تشغيل الحصة الجماعية',
        'gateway_fee_bps' => 'نسبة رسوم البوابة',
        'gateway_fixed_fee_minor' => 'الرسم الثابت للبوابة',
        'is_high_value' => 'أصل عالي القيمة',

        // حماية البيانات (013). أربعةٌ منها شُحنت في ح-١ بلا سطرٍ هنا، فكانت رسالةُ
        // رفضِ تاريخِ الميلاد تُقرأ «date_of_birth» على شاشةِ طفلٍ يسجّل بنفسه.
        'date_of_birth' => 'تاريخ الميلاد',
        'guardian_contact' => 'رقم جوّال وليّ الأمر',
        'registered_by_parent' => 'التسجيل بواسطة وليّ الأمر',
        'terms_accepted' => 'الموافقة على الشروط',
        'categories' => 'أصناف البيانات',
        'categories.*' => 'صنف البيانات',
        'reporter_contact' => 'وسيلة التواصل مع المُبلِّغ',
        'affected_categories' => 'الأصناف المتأثّرة',
        'affected_categories.*' => 'الصنف المتأثّر',
        'affected_subject_count' => 'عدد المتأثّرين',
        'authority_notified' => 'إخطار الجهة المختصّة',
        'subjects_notified' => 'إخطار المعنيّين',
    ],
];
