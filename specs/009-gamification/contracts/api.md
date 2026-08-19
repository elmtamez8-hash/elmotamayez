# Contracts — ٠٠٩ التلعيب

كلُّ مسارٍ تحت `/api/v1`، مصادَقٌ عليه بـSanctum، ويكشف **الـuuid وحدَه**. كلُّ استجابةٍ عبر
API Resource، وكلُّ مسارِ كتابةٍ محدودُ المعدّل بمحدِّدٍ **مُسمّى** (`throttle:gamification`
يُعرَّف في `AppServiceProvider::registerRateLimiters()`) — ⚠️ `throttle:5,1` سطريّاً ممنوع:
`ThrottleRequests` يفهرس الضيوفَ على `domain|ip` بلا المسارِ في التجزئة، فكلُّ حدٍّ سطريٍّ
يتشارك عدّاداً واحداً وأشدُّها يفوز.

---

## قراءةُ الطالب

### `GET /gamification/me`

`FR-041` — كلُّ شيءٍ في مكانٍ واحد. الخبرةُ والمستوى والسلسلةُ والشاراتُ **واحدةٌ عبر كلّ
مدرّسيه**، والعملاتُ **قائمةٌ لكلّ مساحةِ عمل**.

```json
{
  "xp": 4820, "level": 12, "level_name_ar": "متمكّن",
  "next_level_xp": 5200,
  "current_streak": 9, "best_streak": 23, "shields": 1,
  "badges": [{ "key": "first_perfect", "name_ar": "…", "awarded_at": "…" }],
  "coin_balances": [{ "workspace_uuid": "…", "teacher_name": "…", "coins": 310 }]
}
```

⚠️ **ولا حقلَ `coins_total`.** لا مجموعَ صحيحاً — عملاتُ مدرّسٍ لا تُنفَق عند غيره، ومجموعٌ
معروضٌ يَعِد بشيءٍ يرفضه المتجرُ عند أوّلِ محاولة. نفسُ قاعدةِ ٠٠٦ في الأرصدة.

### `GET /gamification/leaderboard?scope=…&period=week`

`scope` واحدٌ من: `lesson:{uuid}` · `course:{uuid}` · `teacher:{uuid}` · `subject:{id}` ·
`grade:{id}` · `platform`.

```json
{
  "scope": "grade:12", "period": "w:2026-W34",
  "level_band": 2,
  "my_rank": 7, "my_points": 340,
  "entries": [{ "rank": 1, "display_name": "خالد ك.", "points": 610, "level": 11 }]
}
```

**ثلاثةُ قيودٍ يفشل البناءُ على كسر أيٍّ منها**:

1. ⚠️ `entries` **لا تتجاوز خمسين أبداً** (`SC-009`) — والتشريحُ **بنطاقِ المستوى أوّلاً** ثمّ
   نافذةٌ داخله، لا بالرتبة وحدَها (§R5).
2. ⚠️ `display_name` في النطاقات **العابرةِ لمساحات العمل** (`subject` · `grade` · `platform`)
   هو `Review::studentDisplayName()` القائمة — «خالد ك.» — لا الاسمُ الكامل (`Q6` · `FR-027`).
3. ⚠️ **صفرُ حقلٍ خارج `GamificationFieldAllowlist`.** و`LeaderboardExposureTest` يقارن
   الحمولةَ **مُعادَ ترميزِها** بـ`JSON_UNESCAPED_UNICODE`: `getContent()` يهرّب غيرَ الـASCII،
   فدعوى تسريبٍ بحجّةٍ عربيةٍ تمرّ **فارغةً** مهما حملت الاستجابة.

`FR-028`: نطاقٌ مقيَّدٌ (‏درسٌ أو كورسٌ أو مدرّس) لا ينتمي إليه القارئُ ⇒ **403**. والعابرةُ
مفتوحةٌ له بحكم التصميم.

---

## المتجر

| المسار | الفاعل | ملاحظة |
|---|---|---|
| `GET /gamification/shop?workspace={uuid}` | طالب | متجرُ مدرّسِه وحدَه (`FR-037`) |
| `POST /gamification/rewards/{reward}/redeem` | طالب | ⚠️ الخصمُ والمخزونُ والسقفُ في **جملةٍ واحدة** |
| `GET /gamification/redemptions` | كلاهما | الطالبُ طلباتِه؛ المدرّسُ طلباتِ متجرِه |
| `POST /gamification/redemptions/{r}/fulfill` | مدرّس | `REDEMPTIONS_FULFILL` |
| `POST /gamification/redemptions/{r}/reject` | مدرّس | ⚠️ يردّ العملاتِ **ويفكّ حجزَ المخزونِ وعدّادِ الشهر** (§R7) |
| `GET·POST·PATCH /gamification/manage/rewards` | مدرّس | `REWARDS_MANAGE` |

**الرفضُ ينطق بسببه** (`FR-033`): `insufficient_coins` · `out_of_stock` · `monthly_cap_reached`
· `reward_inactive` — بأربعِ جملٍ عربيةٍ مختلفة، لأن «تعذّر الاستبدال» تترك الطالبَ يعيد
المحاولةَ إلى الأبد على شرطٍ لن يتغيّر اليوم.

---

## مؤقّت التركيز

`POST /gamification/focus` (`{minutes}`) · `POST /gamification/focus/{session}/end`.

المنحُ عند الاكتمالِ وحدَه وضمن سقفٍ يوميّ (`FR-040`). ⚠️ **والإنهاءُ المبكرُ ينادي
`FocusWindow::close()`** — وإلا بقيت الإشعاراتُ مكتومةً بعد جلسةٍ لم تعد قائمة، وهو عطلٌ لا
يظهر من الخارج لأن الكتمَ يبدو كأنّ لا شيءَ حدث.

---

## أحداثُ الوحدة

**تُطلِق** `Gamification` ثلاثةً، وتستمع لها `Notifications` — و**يُمنع** أن تنادي أيَّ
`Action` في وحدةٍ أخرى:

| الحدث | النوعُ الجديد في `NotificationType` | لوليّ الأمر؟ |
|---|---|---|
| `LevelReachedUp` | `level_up` | لا |
| `BadgeAwarded` | `badge_awarded` | لا |
| `RewardRedeemed` | `reward_redeemed` | **نعم** — المكافأةُ قد تكون خصماً على حصّة |

⚠️ **و`reward_redeemed` يستهدف وليَّ الأمر، فيصير قالبَ واتساب رقم ٢٠** ويحتاج اعتماداً من
المزوّد قبل أن يصل هاتفاً (٠٢٠). و`level_up` و`badge_awarded` لا يستهدفانه: تهنئةٌ يومية تصل
هاتفَ الأب كلفةٌ عند المزوّد ورسالةٌ يطفئها بعد أسبوع، فتُطفأ معها ما يهمّه.

**وتستهلك** ستّةً قائمةً بلا تعديلِ حرفٍ في أيّ وحدة (§R2): `AttendanceConfirmed` ·
`AttemptFinalized` · `MistakeResolved` · `SubmissionGraded` · `SessionCancelled` ·
`AccessWithheld`.

---

## لوحةُ الإدارة (‏Filament)

ثلاثةُ موارد — الأفعالُ والمستوياتُ والشارات — خلف **صلاحيةٍ منصّيةٍ** واحدة
`GAMIFICATION_CATALOG_MANAGE` (`FR-002` · `FR-007` · `Q4`).

⚠️ **منصّيةٌ لا مستأجرة**: مدرّسٌ يستطيع رفعَ قيمةِ «حضور حصة» يستطيع أن يضع طلابَه في صدارةِ
المادةِ والصفِّ والمنصّة. و`Tenancy\Models\Role` يرمي على منحِ صلاحيةٍ منصّيةٍ لدورٍ يحمل
`team_id`، فالحارسُ على النموذج لا على الشاشة — نموذجٌ مُصفّىً يشكّل طلباً واحداً ولا يشكّل
التالي.

**والتعديلُ يسري على ما بعده فقط** (`FR-003` · `SC-005`): القيمةُ مُجمَّدةٌ في القيد لحظةَ
المنح، فلا يوجد مسارٌ يعيد حسابَ منحٍ سابقة — ولا حتى بقصد.
