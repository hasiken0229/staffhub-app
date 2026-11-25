import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { EmployeesSection } from "@/components/dashboard-sections/employees-section";
import { previewEmployeesCsv } from "@/lib/api/employees";

vi.mock("@/lib/api/employees", () => ({ previewEmployeesCsv: vi.fn() }));

describe("EmployeesSection", () => {
  beforeEach(() => {
    vi.mocked(previewEmployeesCsv).mockReset();
    vi.mocked(previewEmployeesCsv).mockResolvedValue({ dryRun: true, previewFingerprint: "preview-1", rowCount: 1, processedCount: 1, createdCount: 1, updatedCount: 0, skippedCount: 0, errors: [] });
  });

  it("職員CSV取込は確認後にだけ実行される", async () => {
    const user = userEvent.setup();
    const onEmployeeImport = vi.fn().mockResolvedValue(undefined);
    const file = new File(["社員番号,姓,名\nE0001,確認,職員"], "employees.csv", { type: "text/csv" });
    Object.defineProperty(file, "text", { value: vi.fn().mockResolvedValue("社員番号,姓,名\nE0001,確認,職員") });
    render(
      <EmployeesSection
        data={{ employees: [], activePanel: "employees-import" }}
        form={{ employeeImportResult: "" }}
        actions={{ onEmployeeImport, onTemplateDownload: vi.fn(), onEmployeeUpdate: vi.fn(), onUnsavedEditChange: vi.fn() }}
        formatters={{ formatEmploymentType: (value) => value ?? "-", formatEmployeeStatus: (value) => value ?? "-" }}
      />,
    );

    await user.upload(screen.getByLabelText("CSVファイル"), file);
    await screen.findByText("新規: 1 件 / 更新: 0 件 / スキップ: 0 件");
    await user.click(screen.getByRole("button", { name: "プレビュー内容で取り込む" }));

    expect(screen.getByRole("dialog", { name: "職員CSVを取り込みますか？" })).toBeVisible();
    expect(onEmployeeImport).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "CSVを取り込む" }));
    await waitFor(() => expect(onEmployeeImport).toHaveBeenCalledOnce());
    expect(onEmployeeImport.mock.calls[0][0].get("previewFingerprint")).toBe("preview-1");
    expect(onEmployeeImport.mock.calls[0][0].get("file").name).toBe("employees.csv");
  });

  function renderImport() {
    const onEmployeeImport = vi.fn().mockResolvedValue(undefined);
    render(<EmployeesSection data={{ employees: [], activePanel: "employees-import" }} form={{ employeeImportResult: "" }}
      actions={{ onEmployeeImport, onTemplateDownload: vi.fn(), onEmployeeUpdate: vi.fn(), onUnsavedEditChange: vi.fn() }}
      formatters={{ formatEmploymentType: (value) => value ?? "-", formatEmployeeStatus: (value) => value ?? "-" }} />);
    return onEmployeeImport;
  }

  it("引用符付きCSVとCP932の判定・改行を含む件数はサーバー結果を使用する", async () => {
    const user = userEvent.setup();
    renderImport();
    vi.mocked(previewEmployeesCsv).mockResolvedValue({ dryRun: true, previewFingerprint: "quoted", rowCount: 1, hasLoginEmail: true,
      processedCount: 1, createdCount: 1, updatedCount: 0, skippedCount: 0, errors: [] });
    const file = new File(['"社員番号","姓","名"\nE1,"確認\n職員",名前'], "quoted.csv", { type: "text/csv" });
    const readText = vi.fn().mockRejectedValue(new Error("ブラウザーでは文字コードを判定しない"));
    Object.defineProperty(file, "text", { value: readText });
    await user.upload(screen.getByLabelText("CSVファイル"), file);
    expect(await screen.findByText("想定件数: 1 件")).toBeVisible();
    expect(screen.getByText("メールアドレス列: あり")).toBeVisible();
    expect(readText).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "プレビュー内容で取り込む" })).toBeEnabled();
    const cp932 = new File([new Uint8Array([0x8e, 0xd0, 0x88, 0xf5, 0x94, 0xd4, 0x8d, 0x86])], "cp932.csv", { type: "text/csv" });
    await user.upload(screen.getByLabelText("CSVファイル"), cp932);
    expect(await screen.findByText("ファイル: cp932.csv")).toBeVisible();
    expect(previewEmployeesCsv).toHaveBeenCalledTimes(2);
  });

  it("処理中と失敗時は取込できず、選び直した後の古い応答は反映しない", async () => {
    const user = userEvent.setup();
    renderImport();
    let resolveOld!: (value: Awaited<ReturnType<typeof previewEmployeesCsv>>) => void;
    vi.mocked(previewEmployeesCsv).mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve; }));
    await user.upload(screen.getByLabelText("CSVファイル"), new File(["old"], "old.csv", { type: "text/csv" }));
    expect(screen.getByRole("button", { name: "プレビュー内容で取り込む" })).toBeDisabled();
    vi.mocked(previewEmployeesCsv).mockRejectedValueOnce(new Error("サーバーでヘッダー不足を検出しました"));
    await user.upload(screen.getByLabelText("CSVファイル"), new File(["new"], "new.csv", { type: "text/csv" }));
    expect(await screen.findByText(/サーバーでヘッダー不足を検出しました/)).toBeVisible();
    await act(async () => resolveOld({ dryRun: true, previewFingerprint: "old", processedCount: 99, createdCount: 99, updatedCount: 0, skippedCount: 0, errors: [] }));
    expect(screen.getByText("ファイル: new.csv")).toBeVisible();
    expect(screen.queryByText("新規: 99 件 / 更新: 0 件 / スキップ: 0 件")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "プレビュー内容で取り込む" })).toBeDisabled();
  });

  it("初期値の変更時も再プレビューが完了するまで取込できない", async () => {
    const user = userEvent.setup();
    renderImport();
    await user.upload(screen.getByLabelText("CSVファイル"), new File(["csv"], "employees.csv", { type: "text/csv" }));
    await screen.findByText("想定件数: 1 件");
    vi.mocked(previewEmployeesCsv).mockImplementationOnce(() => new Promise(() => {}));
    await user.selectOptions(screen.getByRole("combobox", { name: "状態" }), "RETIRED");
    expect(screen.getByRole("button", { name: "プレビュー内容で取り込む" })).toBeDisabled();
    expect((vi.mocked(previewEmployeesCsv).mock.calls.at(-1)?.[0] as FormData).get("defaultStatus")).toBe("RETIRED");
  });

  it("職員一覧は在職者を初期表示し、氏名・番号や状態で検索できる", async () => {
    const user = userEvent.setup();
    render(
      <EmployeesSection
        data={{
          activePanel: "employees-list",
          employees: [
            { id: 1, employeeCode: "E0001", name: "青木 花子", kana: "アオキ ハナコ", departmentName: "保育", employmentType: "FULL_TIME", status: "ACTIVE" },
            { id: 2, employeeCode: "E0002", name: "見本姓K 職員J", kana: "ヤマダ タロウ", departmentName: "事務", employmentType: "PART_TIME", status: "RETIRED" },
          ],
        }}
        form={{ employeeImportResult: "" }}
        actions={{ onEmployeeImport: vi.fn(), onTemplateDownload: vi.fn(), onEmployeeUpdate: vi.fn(), onUnsavedEditChange: vi.fn() }}
        formatters={{ formatEmploymentType: (value) => value ?? "-", formatEmployeeStatus: (value) => value ?? "-" }}
      />,
    );

    expect(screen.getByRole("button", { name: "青木 花子" })).toBeVisible();
    expect(screen.queryByRole("button", { name: "見本姓K 職員J" })).not.toBeInTheDocument();
    await user.type(screen.getByRole("searchbox", { name: "氏名・職員番号" }), "E0001");
    expect(screen.getByRole("button", { name: "青木 花子" })).toBeVisible();

    await user.clear(screen.getByRole("searchbox", { name: "氏名・職員番号" }));
    await user.selectOptions(screen.getByRole("combobox", { name: "在職状態" }), "");
    expect(screen.getByRole("button", { name: "見本姓K 職員J" })).toBeVisible();
  });
});
