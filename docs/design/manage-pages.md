# Staff screens (`/manage/*`) — the design contract

One contract for every staff list screen, so `/manage/blog` and `/manage/store/shipments` read as one product. It builds on the kit in `frontend/src/components/ui/` and adds four pieces: `RecordList`/`RecordRow`, `StatStrip`, `FilterBar`, and the shared stagger. Nothing here adds a colour, a font or an npm package.

**Worked example:** `frontend/src/app/(app)/(shell)/manage/grading-schemes/page.tsx`. **Reference redesign:** `manage/assistants/page.tsx`. Copy them.

Read `docs/gotchas/frontend.md` first. The rules it lists still apply here, and several of them are repeated in the don't list below.

---

## 1. Which primitive for what

| The screen shows… | Use | Why |
|---|---|---|
| Records with a body: a title, a status, a line or two, actions (an announcement, a post, an assignment, an accommodation, a scheme) | `RecordList` + `RecordRow` | Each record is read as a whole. A table would squeeze the body into one cell. |
| Rows that are really **columns** you compare down the page (a shipment's carrier/weight/date, a question's difficulty/discrimination, a certificate's number/date) | `Table` (`ui/Table.tsx`) | Its wrapper scrolls sideways so the page at 375px does not, and it already has the three states. |
| 2–4 headline figures above a list | `StatStrip` of `StatTile` | Two across on a phone, up to four on a desk, and the tiles arrive staggered. |
| A list someone narrows by typing or by status | `FilterBar` (search, chips, count) + `matchesSearch()` | One bar shape everywhere, and Arabic spellings folded (`lib/search-text.ts`). |
| Separate views of one page (each with its own content) | `Tabs` + `TabPanel` | Tabs switch **panels** and keep `?tab=`. Use chips, not tabs, to filter one list. |
| A form that creates a record | `Card as="section"` + `SectionHeading` + the `Field` family | As on the example page. |
| A question asked while nothing else is happening (delete a post) | `Modal` | See section 8 on *the moment*. |
| A destructive control on a row | `ConfirmButton` (two presses) | It arms in place and does not open a window. |

Suggested mapping for the ten pages. This is a starting point, and you may depart from it if you can say why:

| Page | Header strip | Filter | Body |
|---|---|---|---|
| `analytics/questions` | `StatStrip` | `FilterBar` (search) | `Table` |
| `grading` | `StatStrip` (waiting / graded) | chips by state | `Table` or `RecordList` (one row per submission) |
| `accommodations` | — | `FilterBar` (search) | `RecordList` (a student + what they get) |
| `assignments` | `StatStrip` | chips by state | `RecordList` |
| `grading-schemes` | — | — | `RecordList` ✅ done |
| `announcements` | — | chips (active / hidden) | `RecordList` |
| `blog` | — | `FilterBar` (search + published/draft) | `RecordList` with `href` |
| `store/shipments` | `StatStrip` (by state) | `FilterBar` | `Table` |
| `plans` | — | chips if there are more than ~6 | `RecordList` (a plan is a record with a price and terms) |
| `certificates` | `StatStrip` | `FilterBar` (search by name/number) | `Table` |

---

## 2. The APIs

```tsx
import { RecordList, RecordRow } from "@/components/ui/RecordList";
import { StatStrip } from "@/components/ui/StatStrip";
import { StatTile } from "@/components/ui/StatTile";
import { FilterBar } from "@/components/ui/FilterBar";
import { staggerStyle, STAGGER_CLASS } from "@/components/ui/stagger";
import { matchesSearch } from "@/lib/search-text";
```

### `RecordRow`

| Prop | Type | Notes |
|---|---|---|
| `title` | `ReactNode` | Rendered as a heading. Required. |
| `level` | `3 \| 4` (default 3) | 3 directly under `PageHeader` (h2); **4 when the list sits under a `SectionHeading`** (h3). |
| `Icon` | icon component | **Required.** The screen's own nav icon, or one for the record's kind. The chip always takes its column, so leaving the icon out would draw an empty square. |
| `tone` | `StatusTone` (default `"info"`) | The chip's colour, from `TONE_CLASSES`. Use `"neutral"` for archived or ended records. |
| `status` | `ReactNode` | A `Badge` or `StatusBadge`, shown beside the title. |
| `description` | `ReactNode` | One or two lines, muted. |
| `meta` | `{ key, label, value, Icon?, labelHidden? }[]` | Rendered as a `<dl>`. `label` is required; `labelHidden` hides it from the eye but not from a screen reader. Put the value through `arabicNumber()` or `counted()` yourself. |
| `actions` | `ReactNode` | Buttons for this record. At ≥640px they sit at the end of the row; at 375px they wrap under a hairline. They are rendered **once**. |
| `href` | `string` | The title becomes the link and its overlay makes the whole card clickable (a stretched link). `actions` stay pressable above it. |
| `children` | `ReactNode` | Full-width content under the row, such as an expandable editor. |

```tsx
// Not in `NOUNS` yet. Add it there if a second screen needs it. `other` is required
// (see the counted() entry in docs/gotchas/frontend.md).
const POSTS = { one: "مقال واحد", two: "مقالان", few: "مقالات", many: "مقالاً", other: "مقال" };

<section aria-labelledby="posts" className="space-y-4">
  <SectionHeading id="posts" Icon={DocumentIcon} title="المقالات" description={counted(rows.length, POSTS)} />
  <RecordList labelledBy="posts">
    {rows.map((post) => (
      <RecordRow
        key={post.uuid}
        level={4}
        Icon={DocumentIcon}
        tone={post.status === "archived" ? "neutral" : "info"}
        title={post.title}
        href={`/manage/blog/${post.uuid}`}
        status={<StatusBadge status={post.status} />}
        description={post.excerpt}
        meta={[
          { key: "views", label: "المشاهدات", Icon: EyeIcon, value: arabicNumber(post.views) },
          { key: "date", label: "نُشر", Icon: ScheduleIcon, value: formatDate(post.published_at) },
        ]}
        actions={
          <>
            <Button size="sm" variant="ghost" href={`/manage/blog/${post.uuid}/edit`}>تعديل</Button>
            <ConfirmButton size="sm" variant="secondary" confirmLabel="اضغط مجدداً للحذف" onConfirm={() => remove(post.uuid)}>
              حذف
            </ConfirmButton>
          </>
        }
      />
    ))}
  </RecordList>
</section>
```

`RecordList` takes `label` (when no heading is visible) or `labelledBy` (the `SectionHeading` id, which is preferred). It wraps each child in an `<li>` carrying the stagger. Do not wrap rows in `<li>` yourself.

### `StatStrip` / `StatTile`

```tsx
<StatStrip label="ملخّص الشحنات" columns={4}>
  <StatTile label="قيد التجهيز" value={counts.pending} Icon={ClockIcon} />
  <StatTile label="في الطريق" value={counts.shipped} Icon={ShipmentIcon} />
  <StatTile label="سُلّمت" value={counts.delivered} Icon={CheckIcon} emphasis />
  <StatTile label="نسبة التسليم" value={`${arabicNumber(rate)}٪`} Icon={ProgressIcon} />
</StatStrip>
```

- A `number` value counts up once through `AnimatedNumber`, in Arabic digits and respecting reduced motion. A `string` is shown exactly as given. Use a string for money, percentages and dates.
- Use `emphasis` on at most **one** tile per strip.
- `columns` is `2 | 3 | 4`. It is always two across on a phone.
- Show only figures the server sent. Do not compute a total in the browser that the server does not know (PRODUCT.md principle 1). Money never appears on a teacher's screen.

### `FilterBar`

```tsx
const [query, setQuery] = useState("");
const [status, setStatus] = useState("all");
const shown = rows.filter(
  (row) => (status === "all" || row.status === status) && matchesSearch(query, row.title, row.author?.name),
);

<FilterBar
  search={{ id: "blog-search", label: "ابحث في المقالات", value: query, onChange: setQuery, placeholder: "عنوان أو كاتب" }}
  filters={{
    label: "حالة المقال",
    value: status,
    onChange: setStatus,
    options: [
      { key: "all", label: "الكل", count: rows.length },
      { key: "published", label: "المنشورة", count: published },
      { key: "draft", label: "المسودّات", count: drafts },
    ],
  }}
  summary={counted(shown.length, POSTS)}
/>
```

- Every part is optional. The chips are `aria-pressed` toggle buttons with exactly one pressed, and they **wrap** instead of scrolling.
- The filter's empty result is an `EmptyState` **without** `Icon`, since its default magnifier means "no match", plus a "مسح البحث" action that resets both controls.
- A server-paginated list filters **on the server** (send `q` and `status`). `matchesSearch` is only for a list already fully on screen.

### Stagger, outside the list components

```tsx
<div className="grid gap-4 sm:grid-cols-2">
  {cards.map((card, i) => (
    <div key={card.uuid} className={STAGGER_CLASS} style={staggerStyle(i)}>…</div>
  ))}
</div>
```

---

## 3. Page skeleton and spacing

```tsx
<div className="space-y-8">                {/* the page: 32px between blocks */}
  <PageHeader Icon={NavIcon} title="…" description="…" actions={<Button iconStart={<SparkIcon />}>…</Button>} />
  <StatStrip …/>                            {/* optional */}
  <section aria-labelledby="x" className="space-y-4">   {/* a section: 16px inside */}
    <SectionHeading id="x" Icon={…} title="…" description={counted(…)} />
    <FilterBar …/>                          {/* optional */}
    {loading / error / empty / RecordList-or-Table}
  </section>
</div>
```

| Gap | Class |
|---|---|
| Between page blocks | `space-y-8` |
| Inside a section (heading, bar, list) | `space-y-4` |
| Between rows | `space-y-3` (built into `RecordList`) |
| Inside a row | `p-4` on a phone and `sm:p-5` above (built in) |
| Inside a form card | `Card` default `p-6`, `mb-4` under its `SectionHeading` |
| Width | Keep the shell's width. Use `mx-auto max-w-3xl` only for a single-column reading list like the assistants page, and never on a `Table` page. |

Radii follow the kit: cards, rows and bars use `rounded-3xl`, icon chips use `rounded-2xl` (40px) or `rounded-lg` (32px), and buttons and chips use `rounded-full`. Do not add a radius of your own.

---

## 4. Icons

- **Only from `@/components/icons`.** They are Tabler icons behind names that describe their meaning. If a meaning is missing, add a named export there. Do not import `@tabler/icons-react` in a page, and never use an emoji as an icon.
- **`PageHeader`'s `Icon` is the same one the nav shows** for that screen (`lib/panel-nav.tsx`).
- There are two sizes:
  - `h-5 w-5` inside a 40–44px chip (`PageHeader` and the `RecordRow` chip).
  - `h-4 w-4` everywhere else: section chips, meta, tabs, chips, and button `iconStart`.
- Icons are decorative (`aria-hidden`) and sit **beside** a word. An icon-only control needs an `aria-label`, as the `FilterBar` clear button has.
- Direction lives in the icon name (`ChevronStartIcon`). Never flip an icon with CSS.

---

## 5. Hover, focus and motion

| What | Rule | Where it lives |
|---|---|---|
| Card/row hover | The border warms to `primary/40`, the fill tints `primary-soft/30`, and the element lifts `-translate-y-0.5` **only under `motion-safe:`**. There is no shadow. | `CARD_INTERACTIVE` in `Card.tsx`, used by `Card interactive`, `RecordRow` and `StatTile` |
| Icon chip on hover | `motion-safe:group-hover:scale-105` (`StatTile`: `110`) | built in |
| Keyboard focus | `focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary`. A row also warms its border on `focus-within`. A linked row draws the ring on the overlay (the whole card). | built in |
| Press | `active:scale-[0.97]` | `Button` and the chips |
| Transition | `duration-200`, `ease-out` | Keep it within 150–250ms, because the person is in the middle of a task. |
| Arrival | `.stagger-item` is a 0.36s rise with `cubic-bezier(0.16,1,0.3,1)` and a 40ms step, capped at the 9th item, so the whole set lands in about 0.7s | `globals.css` and `stagger.ts` |
| Numbers | `AnimatedNumber` counts up **once**, over 900ms | `StatTile` with a numeric `value` |
| Reduced motion | The global `@media (prefers-reduced-motion: reduce)` block zeroes duration **and delay**, and `AnimatedNumber` checks the query itself. Add nothing. | `globals.css` |

Motion shows a state change or an arrival and nothing else. Only lists, strips and panels that open are staggered. Do not stagger the header, the form or sections. Do not add a new keyframe, and do not play an animation in a loop.

---

## 6. The three states and the empty result

```tsx
{state === "loading" && <RowsSkeleton count={3} />}              {/* a Table does this itself */}
{state === "error" && <ErrorState onRetry={load} />}               {/* never a raw message */}
{state === "ready" && rows.length === 0 && (
  <EmptyState Icon={NavIcon} title="لا مقالات بعد" description="اكتب أوّل مقال من «مقال جديد» أعلاه." action={…} />
)}
```

- **Put the error in the section, not in place of the page.** The header and any create form stay, because the teacher can still use them. The example page moved to this rule.
- An empty state names the **next action**, as FR-078 requires. "لا بيانات" on its own reads as a broken page.
- A write that fails shows an `Alert tone="danger"` above the list with `userMessage(error)`. A 422 error goes under its field through `fieldErrors()`.
- `.catch(() => undefined)` is forbidden, because an error swallowed in silence is worse than a raw one.

---

## 7. Phone (375px)

- `PageHeader` actions wrap under the title. Keep them to one primary action and put the rest on the rows.
- `StatStrip` shows two tiles per row.
- `FilterBar` stacks search, then chips, then count. Chips wrap, and none are hidden behind a scrollbar.
- On `RecordRow` the chip and title stay side by side, and the **actions drop under a hairline**. A long title wraps (`break-words`) and is never cut with an ellipsis.
- `Table` scrolls inside its own wrapper, and **the page must never scroll sideways.** Check this at 375px.
- Touch targets: `Button size="md"` and above give 44px. `size="sm"` is for row actions, where the row itself is also a target.
- jsdom has **no layout**, so no vitest can see any of this. Open the screen at 375px and at desk width once before you finish, and do a hard reload (`Ctrl+Shift+R`) so you are not looking at a stale chunk.

---

## 8. Do / don't

**Do**
- Take every colour from `@theme` tokens. For a status, use `Badge` or the `tone` prop, which read `TONE_CLASSES`. `npm test` runs `theme-tokens.test.ts`, and it fails on `success`, `warning`, `border` and `surface-muted`.
- Write every counted Arabic noun with `counted(n, NOUNS.x)`, and add a form to `NOUNS` if it is missing. Write every bare number with `arabicNumber()`. Never call `toLocaleString("ar…")` on a number.
- Use `ConfirmButton` for an irreversible action on a row. Use `Modal` only for a question asked while nothing else is going on. **The moment decides, not how serious the action is.**
- Wrap Latin text and digits inside Arabic in `<bdi>`. Meta values and tile values already are.
- Use logical properties only: `ms-`, `pe-`, `start-`, `text-start`.
- Label each `<section>` with its `SectionHeading` id.

**Don't**
- Don't pass a free-form `className` into a `ui/` component. If a variant is missing, add it to the component.
- Don't write a second card, row or hover string in a page. Import it, or extend the kit.
- Don't use shadows, gradient text, a coloured side border on rows, or glass.
- Don't write «مساحة العمل» / «مساحتك» in any user-facing text (`workspace-vocabulary.test.ts`). Say «فريقك», «أكاديميتك» or the teacher's name.
- Don't use `Tabs` as a filter, and don't let a chip row scroll sideways.
- Don't render the same controls twice for two breakpoints.
- Don't use `window.confirm`, since `no-native-dialogs.test.ts` fails on it.
- Don't run `npm run build` while the dev server is up.

---

## 9. Before you finish a page

```bash
cd frontend
TZ=UTC npx vitest run src/lib/theme-tokens.test.ts src/lib/workspace-vocabulary.test.ts src/lib/no-unpinned-numerals.test.ts <your page's tests>
npx tsc --noEmit
```

Then open the page at 375px and at desk width, check keyboard Tab through a row (the focus ring is visible and the actions come after the title), and check it once with reduced motion switched on.
