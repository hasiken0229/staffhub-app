import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { PayrollRegisterPanel } from "@/components/dashboard-sections/payroll/payroll-register-panel";
import type { PayrollSectionProps } from "@/components/dashboard-sections/payroll/payroll-section-types";
import type { PayrollImportPreview } from "@/types";

function createProps() {
  const now = new Date();
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
  const onPayrollBatchCreate = vi.fn().mockResolvedValue(undefined);
  const onPayrollBatchPreview = vi.fn().mockResolvedValue({
    dryRun: true,
    statementType: "PAYROLL",
    statementTypeLabel: "給与明細",
    definitionId: 1,
    definitionName: "標準給与テンプレート",
    targetYearMonth: "2026-07",
    periodStartOn: "2026-07-01",
    periodEndOn: "2026-07-31",
    payDate: "2026-07-25",
    publishDate: today,
    processedCount: 1,
    importedCount: 1,
    errorCount: 0,
    items: [{ line: 2, employeeId: 1, employeeCode: "E0001", employeeName: "確認 職員", grossAmount: 250000, deductionAmount: 0, netAmount: 250000 }],
    errors: [],
    preview: {
      sourceFormat: null,
      sourceRowCount: null,
      candidateRowCount: null,
      skippedRowCount: 0,
      skippedRows: [],
      normalizedRowCount: 1,
      headers: ["社員番号", "姓", "名", "総支給額", "駐車場代", "控除額計", "差引支給", "振込支給"],
      rows: [["E0001", "確認", "職員", "250000", "0", "0", "250000", "250000"]],
    },
  } satisfies PayrollImportPreview);
  return {
    data: {
      payrollTypeLabel: "給与明細",
      filteredPayrollDefinitions: [],
    } as unknown as PayrollSectionProps["data"],
    form: {
      payrollStatementType: "PAYROLL",
      payrollDefinitionId: "",
      payrollTargetYearMonth: "2026-07",
      payrollPeriodStartOn: "2026-07-01",
      payrollPeriodEndOn: "2026-07-31",
      payrollPayDate: "2026-07-25",
      payrollPublishDate: today,
      payrollRemarks: "",
    } as PayrollSectionProps["form"],
    actions: {
      onPayrollBatchCreate,
      onPayrollBatchPreview,
      onPayrollDefinitionIdChange: vi.fn(),
      onPayrollTargetYearMonthChange: vi.fn(),
      onPayrollPeriodStartOnChange: vi.fn(),
      onPayrollPeriodEndOnChange: vi.fn(),
      onPayrollPayDateChange: vi.fn(),
      onPayrollPublishDateChange: vi.fn(),
      onPayrollRemarksChange: vi.fn(),
    } as unknown as PayrollSectionProps["actions"],
  };
}

describe("PayrollRegisterPanel", () => {
  it("CSV登録は確認後にだけ実行される", async () => {
    const user = userEvent.setup();
    const props = createProps();
    const file = new File(["職員番号,基本給\nE0001,250000"], "payroll.csv", { type: "text/csv" });
    Object.defineProperty(file, "text", { value: vi.fn().mockResolvedValue("職員番号,基本給\nE0001,250000") });
    render(<PayrollRegisterPanel {...props} />);

    await user.upload(screen.getByLabelText("CSVファイル"), file);
    await user.click(screen.getByRole("button", { name: "加工プレビューを取得" }));
    await screen.findByText("加工後の列順（8列）");
    await user.click(screen.getByRole("button", { name: "この内容で登録する" }));

    expect(screen.getByRole("dialog", { name: "給与明細CSVを登録しますか？" })).toHaveTextContent("新規: 1名／差替: 0名／差引支給合計: 250,000円");
    expect(screen.getByRole("dialog", { name: "給与明細CSVを登録しますか？" })).toHaveTextContent("登録後すぐに公開対象になります");
    expect(props.actions.onPayrollBatchPreview).toHaveBeenCalledOnce();
    expect(props.actions.onPayrollBatchCreate).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "CSVを登録" }));

    await waitFor(() => expect(props.actions.onPayrollBatchCreate).toHaveBeenCalledOnce());
  });

  it("CSVの照合エラーがある場合は正常な行も登録できない", async () => {
    const user = userEvent.setup(); const props = createProps();
    const preview = await props.actions.onPayrollBatchPreview(new FormData());
    vi.mocked(props.actions.onPayrollBatchPreview).mockResolvedValue({ ...preview, errorCount: 1, canImport: false,
      errors: [{ line: 3, employeeCode: "MISSING", message: "該当職員がいません。" }] });
    vi.mocked(props.actions.onPayrollBatchPreview).mockClear();
    const file = new File(["code,amount\nE0001,250000"], "invalid.csv", { type: "text/csv" });
    Object.defineProperty(file, "text", { value: vi.fn().mockResolvedValue("code,amount\nE0001,250000") });
    render(<PayrollRegisterPanel {...props} />);
    await user.upload(screen.getByLabelText("CSVファイル"), file);
    await user.click(screen.getByRole("button", { name: "加工プレビューを取得" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("正常な行もまだ登録していません");
    expect(screen.getByRole("button", { name: "この内容で登録する" })).toBeDisabled();
    expect(props.actions.onPayrollBatchCreate).not.toHaveBeenCalled();
  });

  it("条件を変えた後で到着した古いプレビューは登録に使えない", async () => {
    const user = userEvent.setup(); const props = createProps();
    const preview = await props.actions.onPayrollBatchPreview(new FormData());
    let resolve!: (value: PayrollImportPreview) => void;
    vi.mocked(props.actions.onPayrollBatchPreview).mockReturnValue(new Promise((done) => { resolve = done; }));
    const file = new File(["code,amount\nE0001,250000"], "payroll.csv", { type: "text/csv" });
    Object.defineProperty(file, "text", { value: vi.fn().mockResolvedValue("code,amount\nE0001,250000") });
    render(<PayrollRegisterPanel {...props} />);
    await user.upload(screen.getByLabelText("CSVファイル"), file);
    await user.click(screen.getByRole("button", { name: "加工プレビューを取得" }));
    await user.type(screen.getByLabelText("備考"), "変更");
    resolve(preview);
    await waitFor(() => expect(screen.queryByText("加工プレビューを作成中です…")).not.toBeInTheDocument());
    expect(screen.queryByText("加工後の列順（8列）")).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "この内容で登録する" })).not.toBeInTheDocument();
  });
});
