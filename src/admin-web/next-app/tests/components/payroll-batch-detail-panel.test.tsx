import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { PayrollBatchDetailPanel } from "@/components/dashboard-sections/payroll/payroll-batch-detail-panel";
import type { PayrollSectionProps } from "@/components/dashboard-sections/payroll/payroll-section-types";

function createProps() {
  const onDeletePayrollBatch = vi.fn().mockResolvedValue(undefined);
  return {
    data: {
      selectedPayrollBatchDetail: {
        id: 20,
        statementType: "PAYROLL",
        statementTypeLabel: "給与明細",
        definitionName: "給与CSV",
        targetYearMonth: "2026-07",
        periodStartOn: "2026-07-01",
        periodEndOn: "2026-07-31",
        payDate: "2026-07-25",
        publishDate: "2026-07-25",
        sourceFileName: "payroll.csv",
        processedCount: 1,
        successCount: 1,
        errorCount: 0,
        status: "COMPLETED",
        createdAt: "2026-07-17T00:00:00Z",
        templateVersion: 1,
        fieldCount: 2,
        items: [],
        errors: [],
      },
    } as unknown as PayrollSectionProps["data"],
    form: {} as PayrollSectionProps["form"],
    actions: {
      onDeletePayrollBatch,
      onExportPayrollBatch: vi.fn(),
      onPayrollBatchEmployeeCodeFilterChange: vi.fn(),
      onPayrollBatchEmployeeNameFilterChange: vi.fn(),
      onOpenPayrollBatchDetail: vi.fn(),
      onSearchPayrollBatchDetail: vi.fn(),
      onLoadAdminPayrollDetail: vi.fn(),
      onAdminPayrollDownload: vi.fn(),
    } as unknown as PayrollSectionProps["actions"],
    formatters: {
      formatDateOnly: (value?: string | null) => value ?? "-",
      formatMonthDay: (value?: string | null) => value ?? "-",
    } as PayrollSectionProps["formatters"],
  };
}

describe("PayrollBatchDetailPanel", () => {
  it("取込バッチ削除は確認後にだけ実行される", async () => {
    const user = userEvent.setup();
    const props = createProps();
    render(<PayrollBatchDetailPanel {...props} />);

    await user.click(screen.getByRole("button", { name: "取込バッチ削除" }));

    expect(screen.getByRole("dialog", { name: "取込バッチを削除しますか？" })).toHaveTextContent("関連する給与明細も削除され");
    expect(props.actions.onDeletePayrollBatch).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "取込バッチを削除" }));

    expect(props.actions.onDeletePayrollBatch).toHaveBeenCalledWith(20);
  });
});
