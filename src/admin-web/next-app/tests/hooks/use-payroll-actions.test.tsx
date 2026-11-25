import { act, renderHook } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { createPayrollImportBatch, downloadPayrollStatement, loadEmployeePayrollStatementDetail, markPayrollStatementViewed } from "@/lib/api";
import { usePayrollActions } from "@/hooks/use-payroll-actions";
import type { PayrollStatementDetail } from "@/types";

vi.mock("@/lib/api", () => ({
  createPayrollImportBatch: vi.fn(),
  downloadPayrollStatement: vi.fn(),
  loadEmployeePayrollStatementDetail: vi.fn(),
  markPayrollStatementViewed: vi.fn(),
}));

describe("usePayrollActions payroll import", () => {
  beforeEach(() => {
    vi.mocked(createPayrollImportBatch).mockResolvedValue({
      batchId: 42,
      statementType: "PAYROLL",
      statementTypeLabel: "給与",
      targetYearMonth: "2026-08",
      processedCount: 1,
      importedCount: 1,
      errorCount: 0,
    });
  });

  it("keeps successful registration visible when refreshing the list fails", async () => {
    const setPayrollBatchResult = vi.fn();
    const refresh = vi.fn().mockResolvedValue(false);
    const { result } = renderHook(() => usePayrollActions({
      payrollDefinitionId: "",
      payrollStatementType: "PAYROLL",
      payrollDefinitionName: "",
      payrollDefinitionActive: true,
      payrollBatchEmployeeCodeFilter: "",
      payrollBatchEmployeeNameFilter: "",
      selectedPayrollBatchId: null,
      selectedAdminPayrollDetail: null,
      setPayrollResult: vi.fn(),
      setPayrollDefinitionResult: vi.fn(),
      setPayrollBatchResult,
      setPayrollDefinitionId: vi.fn(),
      setPayrollDefinitionName: vi.fn(),
      setPayrollDefinitionActive: vi.fn(),
      setSelectedPayrollBatchId: vi.fn(),
      setSelectedPayrollBatchDetail: vi.fn(),
      setSelectedAdminPayrollDetail: vi.fn(),
      setSelectedEmployeePayrollDetail: vi.fn(),
      setErrorMessage: vi.fn(),
      onRefresh: vi.fn().mockResolvedValue(undefined),
      onRefreshPayroll: refresh,
    }));

    await act(async () => result.current.handlePayrollBatchCreate(new FormData()));

    expect(createPayrollImportBatch).toHaveBeenCalledOnce();
    expect(refresh).toHaveBeenCalledOnce();
    expect(setPayrollBatchResult).toHaveBeenLastCalledWith(expect.stringContaining("登録は完了していますが一覧の更新に失敗しました"));
    expect(setPayrollBatchResult).toHaveBeenLastCalledWith(expect.stringContaining("再登録は不要です"));
  });
});

it("閲覧記録の保存成功後だけ同じ版の一覧を即時更新する", async () => {
  const viewed = vi.fn(); const setErrorMessage = vi.fn(); const setDetail = vi.fn();
  const detail = { id: 12, revision: 3 } as PayrollStatementDetail;
  vi.mocked(loadEmployeePayrollStatementDetail).mockResolvedValue(detail);
  vi.mocked(markPayrollStatementViewed).mockResolvedValue({ success: true, revision: 3 });
  vi.mocked(downloadPayrollStatement).mockResolvedValue({ status: "download_started", fileName: "payroll.pdf", size: 1, contentType: "application/pdf" });
  const { result } = renderHook(() => usePayrollActions({
    payrollDefinitionId: "", payrollStatementType: "PAYROLL", payrollDefinitionName: "", payrollDefinitionActive: true,
    payrollBatchEmployeeCodeFilter: "", payrollBatchEmployeeNameFilter: "", selectedPayrollBatchId: null, selectedAdminPayrollDetail: null,
    setPayrollResult: vi.fn(), setPayrollDefinitionResult: vi.fn(), setPayrollBatchResult: vi.fn(), setPayrollDefinitionId: vi.fn(),
    setPayrollDefinitionName: vi.fn(), setPayrollDefinitionActive: vi.fn(), setSelectedPayrollBatchId: vi.fn(),
    setSelectedPayrollBatchDetail: vi.fn(), setSelectedAdminPayrollDetail: vi.fn(), setSelectedEmployeePayrollDetail: setDetail,
    setErrorMessage, onRefresh: vi.fn(), onRefreshPayroll: vi.fn(), onEmployeePayrollViewed: viewed,
  }));
  await act(async () => result.current.handleLoadEmployeePayrollDetail(12));
  expect(markPayrollStatementViewed).toHaveBeenLastCalledWith(12, 3);
  expect(viewed).toHaveBeenLastCalledWith(12, 3);
  expect(setDetail).toHaveBeenLastCalledWith({ ...detail, viewed: true });
  viewed.mockClear();
  vi.mocked(markPayrollStatementViewed).mockRejectedValueOnce(new Error("版が変更されました"));
  await act(async () => result.current.handlePayrollDownload(12));
  expect(downloadPayrollStatement).toHaveBeenCalledWith(12, undefined);
  expect(viewed).not.toHaveBeenCalled();
  expect(setErrorMessage).toHaveBeenLastCalledWith(expect.stringContaining("ダウンロードは開始しました"));
  await act(async () => result.current.handlePayrollDownload(12));
  expect(viewed).toHaveBeenLastCalledWith(12, 3);
});
