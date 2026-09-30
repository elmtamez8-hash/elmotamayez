import { act, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

/*
| Every door of `CertificateDesignPolicy` — the list included — asks
| `certificates.regenerate`, the teacher's permission. An assistant who typed the
| address read «تعذّر التحميل» over an empty gallery; they read who does it now.
*/
const list = vi.fn();

vi.mock("@/lib/certificate-designs", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/certificate-designs")>()),
  certificateDesigns: { list: () => list() },
}));

let mockUser: { permissions: string[] } = { permissions: ["certificates.view.all"] };

vi.mock("@/lib/auth-context", () => ({ useAuth: () => ({ user: mockUser }) }));

const { default: CertificateDesignPage } = await import("./page");

beforeEach(() => {
  vi.clearAllMocks();
  list.mockResolvedValue({ data: [], upload_limit: 3, uploads_used: 0 });
});

describe("the certificate design screen", () => {
  it("refuses a reader without certificates.regenerate, and asks the server nothing", async () => {
    mockUser = { permissions: ["certificates.view.all"] };

    await act(async () => {
      render(<CertificateDesignPage />);
    });

    expect(screen.getByText("تصميم شهادة الكورس يتولّاه المدرّس.")).toBeTruthy();
    expect(list).not.toHaveBeenCalled();
  });

  it("loads the designs for the teacher", async () => {
    mockUser = { permissions: ["certificates.view.all", "certificates.regenerate"] };

    await act(async () => {
      render(<CertificateDesignPage />);
    });

    expect(screen.queryByText("هذه الصفحة ليست لك")).toBeNull();
    expect(list).toHaveBeenCalledTimes(1);
  });
});
