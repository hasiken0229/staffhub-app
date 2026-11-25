import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { NoticesSection } from "@/components/dashboard-sections/notices-section";
import { loadAdminNotice, stopAdminNotice, updateAdminNotice } from "@/lib/api/notices";
import { useState } from "react";

vi.mock("@/lib/api/notices", () => ({ loadAdminNotice: vi.fn(), stopAdminNotice: vi.fn(), updateAdminNotice: vi.fn() }));

describe("NoticesSection", () => {
  it("filters automatic notices by recipient and month and shows both in the list", async () => {
    const user = userEvent.setup();
    render(
      <NoticesSection
        data={{
          activePanel: "notices-list",
          employees: [
            { id: 1, employeeCode: "E0001", name: "青木 花子", employmentType: "FULL_TIME", status: "ACTIVE" },
          ],
          notices: [
            { id: 10, noticeType: "PAYROLL_PUBLISHED", title: "給与明細を公開しました", body: "2026-06 の給与明細を公開しました。", targetEmployeeId: 1, relatedType: "PAYROLL_STATEMENT", relatedId: 20, publishStartAt: "2026-06-25", createdAt: "2026-06-25" },
            { id: 11, noticeType: "GENERAL", title: "園からのお知らせ", body: "行事のお知らせ", publishStartAt: "2026-06-25", createdAt: "2026-06-25" },
          ],
        }}
        form={{ noticeType: "GENERAL", noticeTitle: "", noticeBody: "", noticeStartAt: "", noticeEndAt: "", noticeResult: "" }}
        actions={{ onNoticeTypeChange: vi.fn(), onNoticeTitleChange: vi.fn(), onNoticeBodyChange: vi.fn(), onNoticeStartAtChange: vi.fn(), onNoticeEndAtChange: vi.fn(), onCreateNotice: vi.fn() }}
        formatters={{ formatNoticeType: (value) => value ?? "-", formatDateTime: (value) => value ?? "-" }}
      />,
    );

    await user.selectOptions(screen.getByRole("combobox", { name: "お知らせの種類" }), "AUTOMATIC");
    await user.selectOptions(screen.getByRole("combobox", { name: "対象職員" }), "1");
    await user.selectOptions(screen.getByRole("combobox", { name: "対象月" }), "2026-06");

    const table = screen.getByRole("table");
    expect(within(table).getByText("青木 花子")).toBeVisible();
    expect(within(table).getByText("2026-06")).toBeVisible();
    expect(within(table).queryByText("園からのお知らせ")).not.toBeInTheDocument();
  });

  it("登録中の再クリックを防ぎ、失敗後は再試行できる", async () => {
    const user = userEvent.setup(); let reject!: (error: Error) => void;
    const onCreate = vi.fn().mockImplementationOnce(() => new Promise((_resolve, failed) => { reject = failed; })).mockResolvedValue(undefined);
    function RegisterForm() {
      const [title, setTitle] = useState("件名"); const [body, setBody] = useState("本文");
      return <NoticesSection data={{ activePanel: "notices-register", employees: [], notices: [] }}
        form={{ noticeType: "GENERAL", noticeTitle: title, noticeBody: body, noticeStartAt: "2026-10-11T09:00", noticeEndAt: "", noticeResult: "" }}
        actions={{ onNoticeTypeChange: vi.fn(), onNoticeTitleChange: setTitle, onNoticeBodyChange: setBody, onNoticeStartAtChange: vi.fn(), onNoticeEndAtChange: vi.fn(), onCreateNotice: onCreate }}
        formatters={{ formatNoticeType: (value) => value ?? "-", formatDateTime: (value) => value ?? "-" }} />;
    }
    render(<RegisterForm />);
    await user.click(screen.getByRole("button", { name: "お知らせを登録" }));
    expect(screen.getByRole("button", { name: "登録中…" })).toBeDisabled();
    await user.click(screen.getByRole("button", { name: "登録中…" }));
    expect(onCreate).toHaveBeenCalledOnce();
    reject(new Error("通信に失敗しました。"));
    expect(await screen.findByText("通信に失敗しました。")).toBeVisible();
    await user.click(screen.getByRole("button", { name: "お知らせを登録" }));
    expect(onCreate).toHaveBeenCalledTimes(2);
  });

  it("本文を確認し、最新版番号で編集・公開停止する", async () => {
    const user = userEvent.setup();
    const notice = { id: 12, noticeType: "GENERAL", title: "確認用", body: "詳細本文", publishStartAt: "2026-10-11T09:00:00+09:00", createdAt: "2026-10-10", revision: 1 };
    vi.mocked(loadAdminNotice).mockResolvedValue(notice);
    vi.mocked(updateAdminNotice).mockResolvedValue({ ...notice, body: "変更本文", revision: 2 });
    vi.mocked(stopAdminNotice).mockResolvedValue({ ...notice, body: "変更本文", revision: 3, stoppedAt: "2026-10-10T12:00:00+09:00", publicationStatus: "STOPPED", notificationStatus: "CANCELLED" });
    render(<NoticesSection data={{ activePanel: "notices-list", employees: [], notices: [notice] }}
      form={{ noticeType: "GENERAL", noticeTitle: "", noticeBody: "", noticeStartAt: "", noticeEndAt: "", noticeResult: "" }}
      actions={{ onNoticeTypeChange: vi.fn(), onNoticeTitleChange: vi.fn(), onNoticeBodyChange: vi.fn(), onNoticeStartAtChange: vi.fn(), onNoticeEndAtChange: vi.fn(), onCreateNotice: vi.fn() }}
      formatters={{ formatNoticeType: (value) => value ?? "-", formatDateTime: (value) => value ?? "-" }} />);
    await user.click(screen.getByRole("button", { name: "本文・編集" }));
    expect(await screen.findByText("詳細本文")).toBeVisible();
    await user.click(screen.getByRole("button", { name: "編集する" }));
    await user.clear(screen.getByLabelText("本文")); await user.type(screen.getByLabelText("本文"), "変更本文");
    await user.click(screen.getByRole("button", { name: "変更を保存" }));
    expect(await screen.findByText("変更本文")).toBeVisible();
    expect(updateAdminNotice).toHaveBeenCalledWith(12, expect.objectContaining({ revision: 1, body: "変更本文" }));
    await user.click(screen.getByRole("button", { name: "公開を停止" }));
    expect(stopAdminNotice).not.toHaveBeenCalled();
    await user.click(within(screen.getByRole("dialog")).getByRole("button", { name: "公開を停止" }));
    expect(await screen.findByText("公開を停止しました。職員画面への表示と未送信の通知を停止しました。")).toBeVisible();
    expect(stopAdminNotice).toHaveBeenCalledWith(12, 2);
    expect(screen.queryByRole("button", { name: "編集する" })).not.toBeInTheDocument();
  });
});
