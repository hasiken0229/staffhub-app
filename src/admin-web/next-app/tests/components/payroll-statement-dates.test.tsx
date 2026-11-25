import { render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { PayrollStatementDetailCard } from "@/components/payroll-statement-detail-card";
import { PayrollStatementListPanel } from "@/components/dashboard-sections/payroll/payroll-statement-list-panel";
import type { PayrollSectionProps } from "@/components/dashboard-sections/payroll/payroll-section-types";
import type { PayrollStatementDetail } from "@/types";

const statement: PayrollStatementDetail = {
  id: 3, employeeId: 1, employeeCode: "T01", employeeName: "確認職員",
  statementType: "PAYROLL", statementTypeLabel: "給与明細", targetYearMonth: "2026-05",
  originalFileName: "test.pdf", publishedAt: "2026-06-05", payDate: null,
  grossAmount: 0, deductionAmount: 0, netAmount: 0, legacyMode: false,
  lines: [], sections: { pay: [], deduction: [], summary: [], other: [] },
};
const plainDate = (value?: string | null) => value ?? "-";

describe("給与明細の支給日と公開日", () => {
  it.each([
    [null, "未登録"],
    ["2026-05-28", "5/28"],
  ] as const)("公開済みの明細で登録済みの支給日だけを表示する (%s)", (payDate, expected) => {
    const detail = { ...statement, payDate };
    const data: PayrollSectionProps["data"] = {
      payrollTypeLabel: "給与明細", filteredPayrollStatements: [detail], activePanel: "payroll-statements",
      filteredPayrollDefinitions: [], filteredPayrollBatches: [], filteredPayrollHistory: [],
      selectedPayrollBatchDetail: null, selectedAdminPayrollDetail: null,
    };
    render(<>
      <PayrollStatementListPanel data={data} actions={{} as PayrollSectionProps["actions"]}
        formatters={{ formatDateTime: plainDate } as PayrollSectionProps["formatters"]} />
      <PayrollStatementDetailCard detail={detail} mode="admin" onAdminPayrollDownload={vi.fn()}
        onPayrollDownload={vi.fn()} onDeletePayrollStatement={vi.fn()} formatDateOnly={plainDate} />
    </>);

    const row = screen.getByRole("row", { name: /確認職員/ });
    expect(within(row).getByRole("cell", { name: expected })).toBeInTheDocument();
    expect(within(row).getByRole("cell", { name: "2026-06-05" })).toBeInTheDocument();
    expect(screen.getByText("支給日", { selector: "span" }).nextElementSibling).toHaveTextContent(expected);
    expect(screen.getByText("公開日", { selector: "span" }).nextElementSibling).toHaveTextContent("2026-06-05");
  });
});

