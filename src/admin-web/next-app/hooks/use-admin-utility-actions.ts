import {
  assignCard,
  createNotice,
  downloadAdminCsvTemplate,
  downloadFileHistory,
  downloadMonthlyPayrollCsv,
  downloadMonthlyWorksPdf,
  importEmployeesCsv,
  markNotificationRead,
} from "@/lib/api";

type UseAdminUtilityActionsParams = {
  assignEmployeeId: string;
  assignCardUid: string;
  noticeType: string;
  noticeTitle: string;
  noticeBody: string;
  noticeStartAt: string;
  noticeEndAt: string;
  reportMonth: string;
  reportEmployeeId: string;
  setAssignResult: (value: string) => void;
  setAssignCardUid: (value: string) => void;
  setNoticeResult: (value: string) => void;
  setNoticeTitle: (value: string) => void;
  setNoticeBody: (value: string) => void;
  setEmployeeImportResult: (value: string) => void;
  setPayrollDefinitionResult: (value: string) => void;
  setReportResult: (value: string) => void;
  setErrorMessage: (value: string) => void;
  onRefresh: () => Promise<void>;
};

export function useAdminUtilityActions(params: UseAdminUtilityActionsParams) {
  async function handleAssignCard() {
    try {
      const result = await assignCard(Number(params.assignEmployeeId), params.assignCardUid);
      params.setAssignResult(`${result.employeeName} にカード ${result.cardUid} を割り当てました。`);
      params.setAssignCardUid("");
      await params.onRefresh();
    } catch (error) {
      params.setAssignResult(error instanceof Error ? error.message : "カード登録に失敗しました。");
    }
  }

  async function handleCreateNotice() {
    try {
      await createNotice({
        noticeType: params.noticeType,
        title: params.noticeTitle,
        body: params.noticeBody,
        publishStartAt: params.noticeStartAt,
        publishEndAt: params.noticeEndAt || undefined,
      });
      params.setNoticeResult("お知らせを登録しました。");
      params.setNoticeTitle("");
      params.setNoticeBody("");
      await params.onRefresh();
    } catch (error) {
      params.setNoticeResult(error instanceof Error ? error.message : "お知らせ登録に失敗しました。");
    }
  }

  async function handleEmployeeImport(formData: FormData) {
    try {
      const result = await importEmployeesCsv(formData);
      params.setEmployeeImportResult(
        `職員 ${result.processedCount} 件を処理しました。新規 ${result.createdCount} / 更新 ${result.updatedCount} / スキップ ${result.skippedCount}`,
      );
      await params.onRefresh();
    } catch (error) {
      params.setEmployeeImportResult(error instanceof Error ? error.message : "職員CSV取込に失敗しました。");
    }
  }

  async function handleTemplateDownload(kind: "employees" | "payroll" | "bonus") {
    try {
      await downloadAdminCsvTemplate(kind);
    } catch (error) {
      const message = error instanceof Error ? error.message : "テンプレートCSVのダウンロードに失敗しました。";
      if (kind === "employees") {
        params.setEmployeeImportResult(message);
      } else {
        params.setPayrollDefinitionResult(message);
      }
    }
  }

  async function handleMonthlyPayrollCsvDownload() {
    try {
      await downloadMonthlyPayrollCsv(params.reportMonth);
      params.setReportResult("給与ソフト用CSVを出力しました。");
      await params.onRefresh();
    } catch (error) {
      params.setReportResult(error instanceof Error ? error.message : "給与ソフト用CSVの出力に失敗しました。");
    }
  }

  async function handleMonthlyWorksPdfDownload() {
    try {
      await downloadMonthlyWorksPdf(Number(params.reportEmployeeId), params.reportMonth);
      params.setReportResult("月次勤務PDFを出力しました。");
      await params.onRefresh();
    } catch (error) {
      params.setReportResult(error instanceof Error ? error.message : "月次勤務PDFの出力に失敗しました。");
    }
  }

  async function handleFileHistoryDownload(historyId: number, fileName?: string) {
    try {
      await downloadFileHistory(historyId, fileName);
    } catch (error) {
      params.setReportResult(error instanceof Error ? error.message : "履歴ファイルの再取得に失敗しました。");
    }
  }

  async function handleNotificationRead(id: number, sourceType: string) {
    try {
      await markNotificationRead(id, sourceType);
      await params.onRefresh();
    } catch (error) {
      params.setErrorMessage(error instanceof Error ? error.message : "お知らせ既読に失敗しました。");
    }
  }

  return {
    handleAssignCard,
    handleCreateNotice,
    handleEmployeeImport,
    handleFileHistoryDownload,
    handleMonthlyPayrollCsvDownload,
    handleMonthlyWorksPdfDownload,
    handleNotificationRead,
    handleTemplateDownload,
  };
}
