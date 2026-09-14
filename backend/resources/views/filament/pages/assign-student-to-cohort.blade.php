<x-filament-panels::page>
    {{--
        ⚠️ الاستمارةُ فوقَ القائمةِ عمداً، عكسَ جارتِها `grant-credit-subscription`.
        هناكَ الطابورُ هو السؤالُ والاستمارةُ حالةٌ نادرة؛ هنا الكورسُ **شرطٌ**
        للقائمةِ نفسِها — فوضعُه أسفلَها يجعلُ أوّلَ ما يراهُ الموظَّفُ جدولاً
        فارغاً لا يقولُ لماذا.
    --}}
    <section class="fi-section">
        {{ $this->form }}
    </section>

    <section class="fi-section">
        <h2 class="fi-section-header-heading">مَن ينتظرُ إسناداً</h2>
        <p class="fi-section-header-description">
            مسجَّلونَ في هذا الكورسِ ولا مجموعةَ لهم. منهجُهم مفتوحٌ في أثناءِ الانتظار
            (٠٣٤ · FR-015) — الإسنادُ يفتحُ لهم المواعيدَ والمقاعد، لا المحتوى.
        </p>

        {{ $this->table }}
    </section>
</x-filament-panels::page>
