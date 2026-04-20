import {
  createPayrollImportBatch,
  deletePayrollImportBatch,
  deletePayrollStatement,
  downloadAdminPayrollStatement,
  downloadPayrollStatement,
  exportPayrollImportBatchPdf,
  loadAdminPayrollStatementDetail,
  loadEmployeePayrollStatementDetail,
  loadPayrollImportBatchDetail,
  savePayrollDefinition,
} from "@/lib/api";
import type { PayrollImportBatchDetail, PayrollStatementDetail } from "@/types";

type UsePayrollActionsParams = {
  payrollDefinitionId: string;
  payrollStatementType: "PAYROLL" | "BONUS";
  payrollDefinitionName: string;
  payrollDefinitionActive: boolean;
  payrollBatchEmployeeCodeFilter: string;
  payrollBatchEmployeeNameFilter: string;
  selectedPayrollBatchId: number | null;
  selectedAdminPayrollDetail: PayrollStatementDetail | null;
  setPayrollResult: (value: string) => void;
  setPayrollDefinitionResult: (value: string) => void;
  setPayrollBatchResult: (value: string) => void;
  setPayrollDefinitionId: (value: string) => void;
  setPayrollDefinitionName: (value: string) => void;
  setPayrollDefinitionActive: (value: boolean) => void;
  setSelectedPayrollBatchId: (value: number | null) => void;
  setSelectedPayrollBatchDetail: (value: PayrollImportBatchDetail | null) => void;
  setSelectedAdminPayrollDetail: (value: PayrollStatementDetail | null) => void;
  setSelectedEmployeePayrollDetail: (value: PayrollStatementDetail | null) => void;
  setErrorMessage: (value: string) => void;
  onRefresh: () => Promise<void>;
};

export function usePayrollActions(params: UsePayrollActionsParams) {
  async function handlePayrollDefinitionSave() {
    try {
      const saved = await savePayrollDefinition({
        id: params.payrollDefinitionId ? Number(params.payrollDefinitionId) : undefined,
        statementType: params.payrollStatementType,
        definitionName: params.payrollDefinitionName,
        isActive: params.payrollDefinitionActive,
      });
      params.setPayrollDefinitionResult(`${saved.definitionName} を保存しました。`);
      params.setPayrollDefinitionId(String(saved.id));
      params.setPayrollDefinitionName(saved.definitionName);
      params.setPayrollDefinitionActive(saved.isActive);
      await params.onRefresh();
    } catch (error) {
      params.setPayrollDefinitionResult(error instanceof Error ? error.message : "データ定義の保存に失敗しました。");
    }
  }

  async function openPayrollBatchDetail(batchId: number) {
    try {
      const detail = await loadPayrollImportBatchDetail(batchId, {
        employeeCode: params.payrollBatchEmployeeCodeFilter || undefined,
        employeeName: params.payrollBatchEmployeeNameFilter || undefined,
      });
      params.setSelectedPayrollBatchId(batchId);
      params.setSelectedPayrollBatchDetail(detail);
      params.setSelectedAdminPayrollDetail(null);
      params.setErrorMessage("");
    } catch (error) {
      params.setErrorMessage(error instanceof Error ? error.message : "取込詳細の読込に失敗しました。");
    }
  }

  async function handlePayrollBatchCreate(formData: FormData) {
    try {
      const result = await createPayrollImportBatch(formData);
      params.setPayrollBatchResult(
        `${result.statementTypeLabel}を ${result.importedCount} 件登録しました。エラー ${result.errorCount} 件。`,
      );
      if (result.batchId != null) {
        params.setSelectedPayrollBatchId(result.batchId);
      }
      await params.onRefresh();
      if (result.batchId != null) {
        await openPayrollBatchDetail(result.batchId);
      }
    } catch (error) {
      params.setPayrollBatchResult(error instanceof Error ? error.message : "データCSV登録に失敗しました。");
    }
  }

  async function searchPayrollBatchDetail() {
    if (!params.selectedPayrollBatchId) {
      params.setPayrollBatchResult("先に取込バッチを選択してください。");
      return;
    }

    await openPayrollBatchDetail(params.selectedPayrollBatchId);
  }

  async function handleDeletePayrollBatch(batchId: number) {
    try {
      await deletePayrollImportBatch(batchId);
      params.setPayrollBatchResult(`取込バッチ ${batchId} を削除しました。`);
      if (params.selectedPayrollBatchId === batchId) {
        params.setSelectedPayrollBatchId(null);
        params.setSelectedPayrollBatchDetail(null);
        params.setSelectedAdminPayrollDetail(null);
      }
      await params.onRefresh();
    } catch (error) {
      params.setPayrollBatchResult(error instanceof Error ? error.message : "取込バッチの削除に失敗しました。");
    }
  }

  async function handleExportPayrollBatch(batchId: number, fileName?: string) {
    try {
      await exportPayrollImportBatchPdf(batchId, fileName);
    } catch (error) {
      params.setPayrollBatchResult(error instanceof Error ? error.message : "一括PDF出力に失敗しました。");
    }
  }

  async function handleLoadAdminPayrollDetail(statementId: number) {
    try {
      const detail = await loadAdminPayrollStatementDetail(statementId);
      params.setSelectedAdminPayrollDetail(detail);
      params.setErrorMessage("");
    } catch (error) {
      params.setErrorMessage(error instanceof Error ? error.message : "明細詳細の読込に失敗しました。");
    }
  }

  async function handleDeletePayrollStatement(statementId: number) {
    try {
      await deletePayrollStatement(statementId);
      params.setPayrollResult(`明細 ${statementId} を削除しました。`);
      if (params.selectedAdminPayrollDetail?.id === statementId) {
        params.setSelectedAdminPayrollDetail(null);
      }
      if (params.selectedPayrollBatchId != null) {
        await openPayrollBatchDetail(params.selectedPayrollBatchId);
      } else {
        await params.onRefresh();
      }
    } catch (error) {
      params.setPayrollResult(error instanceof Error ? error.message : "明細の削除に失敗しました。");
    }
  }

  async function handleLoadEmployeePayrollDetail(statementId: number) {
    try {
      const detail = await loadEmployeePayrollStatementDetail(statementId);
      params.setSelectedEmployeePayrollDetail(detail);
      params.setErrorMessage("");
    } catch (error) {
      params.setErrorMessage(error instanceof Error ? error.message : "明細詳細の読込に失敗しました。");
    }
  }

  async function handlePayrollDownload(id: number, fileName?: string) {
    try {
      await downloadPayrollStatement(id, fileName);
    } catch (error) {
      params.setErrorMessage(error instanceof Error ? error.message : "明細ダウンロードに失敗しました。");
    }
  }

  async function handleAdminPayrollDownload(id: number, fileName?: string) {
    try {
      await downloadAdminPayrollStatement(id, fileName);
    } catch (error) {
      params.setErrorMessage(error instanceof Error ? error.message : "明細PDFの保存に失敗しました。");
    }
  }

  return {
    handleAdminPayrollDownload,
    handleDeletePayrollBatch,
    handleDeletePayrollStatement,
    handleExportPayrollBatch,
    handleLoadAdminPayrollDetail,
    handleLoadEmployeePayrollDetail,
    handlePayrollBatchCreate,
    handlePayrollDefinitionSave,
    handlePayrollDownload,
    openPayrollBatchDetail,
    searchPayrollBatchDetail,
  };
}
