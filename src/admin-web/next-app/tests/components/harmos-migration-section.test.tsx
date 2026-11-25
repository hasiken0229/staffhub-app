import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { HarmosMigrationSection } from "@/components/dashboard-sections/harmos-migration-section";
import { importHarmosMigration, previewHarmosMigration } from "@/lib/api";
import type { HarmosMigrationResult } from "@/types";

vi.mock("@/lib/api", () => ({
  importHarmosMigration: vi.fn(),
  previewHarmosMigration: vi.fn(),
}));

const previewResult: HarmosMigrationResult = {
  dryRun: true,
  importType: "HARMOS_EMPLOYEE_CSV",
  sourceFileName: "employees.csv",
  headers: ["職員番号", "氏名"],
  processedCount: 2,
  successCount: 1,
  errorCount: 1,
  summary: { createdCount: 1, updatedCount: 0, skippedCount: 0, matchedEmployeeCount: 1, unmatchedEmployeeCount: 1, duplicateCount: 0 },
  items: [{ line: 2, action: "CREATE", employeeCode: "E0001", employeeName: "確認職員" }],
  errors: [{ line: 3, message: "職員番号がありません" }],
};

describe("HarmosMigrationSection", () => {
  beforeEach(() => {
    vi.mocked(previewHarmosMigration).mockResolvedValue(previewResult);
    vi.mocked(importHarmosMigration).mockResolvedValue({ ...previewResult, dryRun: false });
  });

  it("確定取込は件数確認後にだけ実行される", async () => {
    const user = userEvent.setup();
    const onRefresh = vi.fn();
    render(<HarmosMigrationSection data={{ activePanel: "harmos-upload", importHistory: [] }} actions={{ onRefresh, onFileHistoryDownload: vi.fn() }} />);

    await user.upload(screen.getByLabelText("CSVファイル"), new File(["職員番号,氏名\nE0001,確認職員"], "employees.csv", { type: "text/csv" }));
    await user.click(screen.getByRole("button", { name: "CSVをプレビュー" }));
    await screen.findByText("プレビュー完了: 1件取込可能、エラー1件。");

    await user.click(screen.getByRole("button", { name: "確定取込" }));

    expect(screen.getByRole("dialog", { name: "HARMOS CSVを確定取込しますか？" })).toHaveTextContent("取込可能 1件、エラー 1件");
    expect(importHarmosMigration).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "確定取込を実行" }));

    await waitFor(() => expect(importHarmosMigration).toHaveBeenCalledOnce());
    expect(onRefresh).toHaveBeenCalledOnce();
  });

  it("取込履歴の種別を日本語で表示する", () => {
    const importTypes = [
      ["HARMOS_EMPLOYEE_CSV", "職員マスタ（拠点・雇用形態同期）"],
      ["HARMOS_WORK_TYPE_CSV", "勤務区分"],
      ["HARMOS_ATTENDANCE_DAILY_CSV", "日次勤怠"],
      ["HARMOS_ATTENDANCE_MONTHLY_CSV", "月次勤怠"],
      ["HARMOS_PAID_LEAVE_BALANCE_CSV", "有給残数"],
    ] as const;
    const importHistory = importTypes.map(([importType], index) => ({
      id: index,
      importType,
      sourceFileName: `import-${index}.csv`,
      processedCount: 1,
      successCount: 1,
      errorCount: 0,
      createdAt: "2026-08-27T10:00:00",
    }));

    render(<HarmosMigrationSection data={{ activePanel: "harmos-history", importHistory }} actions={{ onRefresh: vi.fn(), onFileHistoryDownload: vi.fn() }} />);

    for (const [, label] of importTypes) {
      expect(screen.getByText(label)).toBeInTheDocument();
    }
    for (const [importType] of importTypes) {
      expect(screen.queryByText(importType)).not.toBeInTheDocument();
    }
  });
});
