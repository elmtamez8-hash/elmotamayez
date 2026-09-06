import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { AccountMenu } from "./AccountMenu";
import { HomeIcon } from "@/components/icons";

/*
| قائمةُ الحسابِ — طلبُ المستخدِمِ ٢٠٢٦-٠٩-٠٦: «قائمة منسدلة في الهيدر فيها صورته
| واول اسم منه واسفلها قائمة اعدادات وبعض الصفحات المهمة للطالب … للوصول السريع»،
| ثمّ «عايزه يظهر في كامل الصفحات وليس في داشبورد فقط».
|
| ⚠️ ولذلك هي مكوّنٌ واحدٌ تستعملُه الترويستان — ترويسةُ اللوحةِ وترويسةُ الموقعِ
| العامّة — لا نسختان. والصفحاتُ التي يعيشُ فيها الطالبُ فعلاً عامّةٌ: `homePathFor`
| يُنزِلُه على `‎/teachers`.
*/

const LINKS = [
  { href: "/schedule", label: "جدولي", Icon: HomeIcon },
  { href: "/assignments", label: "واجباتي", Icon: HomeIcon },
];

function open(props: Partial<React.ComponentProps<typeof AccountMenu>> = {}) {
  const onLogout = vi.fn();

  render(
    <AccountMenu
      name="خالد المنصوري"
      firstName="خالد"
      photoUrl={null}
      quickLinks={LINKS}
      onLogout={onLogout}
      {...props}
    />,
  );

  return { onLogout, toggle: screen.getByRole("button", { name: "حساب خالد المنصوري" }) };
}

describe("AccountMenu", () => {
  it("shows the first name and stays shut until it is asked", () => {
    const { toggle } = open();

    expect(toggle.getAttribute("aria-expanded")).toBe("false");
    expect(screen.queryByRole("menu")).toBeNull();

    fireEvent.click(toggle);

    expect(toggle.getAttribute("aria-expanded")).toBe("true");
    expect(screen.getByText("جدولي")).toBeDefined();
    expect(screen.getByText("الإعدادات")).toBeDefined();
  });

  it("renders the quick links it was GIVEN and invents none", () => {
    // ⚠️ التوكيدُ الحاسم. لو بُنيَتِ القائمةُ داخلَ المكوّنِ لظهرَتْ «لوحة الصدارة»
    // هنا — ولظهرتْ لمدرّسٍ وموظّفٍ ماليٍّ في المنتَج، لأنّ الحارسَ الذي يُخفيها
    // يعيشُ في `allowedNav` لا هنا.
    open();

    fireEvent.click(screen.getByRole("button", { name: "حساب خالد المنصوري" }));

    expect(screen.getByText("واجباتي")).toBeDefined();
    expect(screen.queryByText("لوحة الصدارة")).toBeNull();
  });

  it("closes on Escape, so a keyboard is not trapped in it", () => {
    const { toggle } = open();

    fireEvent.click(toggle);
    fireEvent.keyDown(document, { key: "Escape" });

    expect(screen.queryByRole("menu")).toBeNull();
  });

  it("closes on a press outside itself", () => {
    const { toggle } = open();

    fireEvent.click(toggle);
    // `pointerdown` وليس `click`: الضغطةُ التي تفتحُ شيئاً آخرَ تبدأُ به.
    fireEvent.pointerDown(document.body);

    expect(screen.queryByRole("menu")).toBeNull();
  });

  it("offers the panel link only where one was handed over", () => {
    // الترويسةُ العامّةُ تمرّرُه؛ وداخلَ اللوحةِ يكونُ رابطاً إلى المكانِ الذي
    // يقفُ فيه قارئُه أصلاً.
    const { toggle } = open({ panelHref: "/enrollments" });

    fireEvent.click(toggle);

    expect(screen.getByText("لوحتي")).toBeDefined();
  });

  it("hides the panel link when none was given", () => {
    const { toggle } = open();

    fireEvent.click(toggle);

    expect(screen.queryByText("لوحتي")).toBeNull();
  });

  it("signs out through the caller, which owns the redirect", () => {
    const { onLogout, toggle } = open();

    fireEvent.click(toggle);
    fireEvent.click(screen.getByRole("menuitem", { name: "تسجيل الخروج" }));

    expect(onLogout).toHaveBeenCalledTimes(1);
  });
});
