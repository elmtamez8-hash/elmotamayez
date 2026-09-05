<x-filament-panels::page>
    {{--
        ⚠️ الطابورُ فوقَ الاستمارةِ عمداً (027 · FR-016). الموظّفُ يفتحُ هذه الصفحةَ
        ليراجعَ ما أرسلَه الطلاب؛ المنحُ اليدويُّ هو الحالةُ النادرة، فوضعُه أوّلاً
        يجعلُ الشاشةَ تجيبُ السؤالَ الأقلَّ ورودا.
    --}}
    <section class="fi-section">
        <h2 class="fi-section-header-heading">طلباتُ الاشتراكِ المعلَّقة</h2>
        <p class="fi-section-header-description">
            ما أرسلَه الطلابُ من صفحاتِ الكورسات. افتحِ الإيصالَ وطابقِ المبلغَ قبلَ الاعتماد.
        </p>

        {{ $this->table }}
    </section>

    <section class="fi-section">
        <h2 class="fi-section-header-heading">منحٌ يدويّ</h2>
        <p class="fi-section-header-description">
            لطالبٍ دفعَ خارجَ المنصّة. يَنتهي بطلبٍ معلَّقٍ يُعتمَدُ من شاشةِ الطلبات — لا من هنا.
        </p>

        {{ $this->form }}
    </section>
</x-filament-panels::page>
