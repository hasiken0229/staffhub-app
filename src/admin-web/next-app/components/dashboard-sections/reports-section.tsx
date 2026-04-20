import { DataTable } from "@/components/data-table";
import type { AttendanceApproval, AttendanceDaily, Employee, ImportHistory, PaidLeaveReportRow } from "@/types";

type ReportsSectionProps = {
  data: {
    employees: Employee[];
    reportTodayAttendance: AttendanceDaily[];
    reportAttendanceApprovals: AttendanceApproval[];
    paidLeaveReport: PaidLeaveReportRow[];
    reportFileHistory: ImportHistory[];
    reportResult: string;
    activePanel: string;
  };
  filters: {
    reportMonth: string;
    reportFrom: string;
    reportTo: string;
    reportEmployeeId: string;
  };
  actions: {
    onReportMonthChange: (value: string) => void;
    onReportFromChange: (value: string) => void;
    onReportToChange: (value: string) => void;
    onReportEmployeeIdChange: (value: string) => void;
    onDownloadMonthlyAttendanceCsv: (month: string) => Promise<void>;
    onDownloadMonthlyPayrollCsv: () => Promise<void>;
    onDownloadDailyAttendanceCsv: (from: string, to: string) => Promise<void>;
    onDownloadDailyAttendancePdf: (month: string) => Promise<void>;
    onDownloadMonthlyWorksPdf: () => Promise<void>;
    onFileHistoryDownload: (historyId: number, fileName?: string) => Promise<void>;
  };
  formatters: {
    formatDateOnly: (value?: string | null) => string;
    formatDateTime: (value?: string | null) => string;
    formatTimeOnly: (value?: string | null) => string;
    formatApprovalStatus: (value?: string | null) => string;
    formatImportType: (value?: string | null) => string;
    formatLeaveLedgerEntryType: (value?: string | null) => string;
  };
};

export function ReportsSection(props: ReportsSectionProps) {
  const activePanel = props.data.activePanel || "reports-export";

  return (
    <section className="stack-section section-enter delay-3">
      {activePanel === "reports-export" ? (
      <section id="reports-export" className="panel action-panel anchor-panel">
        <div className="panel-header">
          <div>
            <h3>レポート出力</h3>
          </div>
        </div>
        <label>
          対象月
          <input type="month" value={props.filters.reportMonth} onChange={(event) => props.actions.onReportMonthChange(event.target.value)} />
        </label>
        <label>
          開始日
          <input type="date" value={props.filters.reportFrom} onChange={(event) => props.actions.onReportFromChange(event.target.value)} />
        </label>
        <label>
          終了日
          <input type="date" value={props.filters.reportTo} onChange={(event) => props.actions.onReportToChange(event.target.value)} />
        </label>
        <label>
          対象職員
          <select value={props.filters.reportEmployeeId} onChange={(event) => props.actions.onReportEmployeeIdChange(event.target.value)}>
            {props.data.employees.length === 0 ? (
              <option value="1">職員未登録</option>
            ) : (
              props.data.employees.map((employee) => (
                <option key={employee.id} value={employee.id}>
                  {employee.employeeCode} / {employee.name}
                </option>
              ))
            )}
          </select>
        </label>
        <div className="button-row">
          <button type="button" onClick={() => void props.actions.onDownloadMonthlyAttendanceCsv(props.filters.reportMonth)}>
            月次集計CSV
          </button>
          <button type="button" onClick={() => void props.actions.onDownloadMonthlyPayrollCsv()}>
            給与ソフトCSV
          </button>
          <button type="button" className="secondary" onClick={() => void props.actions.onDownloadDailyAttendanceCsv(props.filters.reportFrom, props.filters.reportTo)}>
            日次勤怠CSV
          </button>
          <button type="button" className="secondary" onClick={() => void props.actions.onDownloadDailyAttendancePdf(props.filters.reportMonth)}>
            日次勤怠PDF
          </button>
          <button type="button" className="secondary" onClick={() => void props.actions.onDownloadMonthlyWorksPdf()}>
            職員別勤務PDF
          </button>
        </div>
        {props.data.reportResult ? <p className="feedback">{props.data.reportResult}</p> : null}
      </section>
      ) : null}
      {activePanel === "reports-today" ? (
        <DataTable
          id="reports-today"
          title="今日の出退勤レポート"
          rows={props.data.reportTodayAttendance}
          emptyMessage="本日のレポートはありません"
          columns={[
            { key: "employeeCode", header: "職員番号", render: (row) => row.employeeCode },
            { key: "employeeName", header: "氏名", render: (row) => row.employeeName },
            { key: "clockInAt", header: "出勤", render: (row) => props.formatters.formatTimeOnly(row.clockInAt) },
            { key: "clockOutAt", header: "退勤", render: (row) => props.formatters.formatTimeOnly(row.clockOutAt) },
            { key: "approvalStatus", header: "承認", render: (row) => props.formatters.formatApprovalStatus(row.approvalStatus) },
          ]}
        />
      ) : null}
      {activePanel === "reports-approvals" ? (
        <DataTable
          id="reports-approvals"
          title="勤怠承認履歴"
          rows={props.data.reportAttendanceApprovals}
          emptyMessage="承認履歴はまだありません"
          columns={[
            { key: "targetDate", header: "日付", render: (row) => props.formatters.formatDateOnly(row.targetDate) },
            { key: "employeeName", header: "職員", render: (row) => row.employeeName },
            { key: "approvalStatus", header: "状態", render: (row) => props.formatters.formatApprovalStatus(row.approvalStatus) },
            { key: "approvedAt", header: "処理日時", render: (row) => props.formatters.formatDateTime(row.approvedAt) },
            { key: "approvalComment", header: "コメント", render: (row) => row.approvalComment ?? "-" },
          ]}
        />
      ) : null}
      {activePanel === "reports-paid-leave" ? (
        <DataTable
          id="reports-paid-leave"
          title="有給休暇管理レポート"
          rows={props.data.paidLeaveReport}
          emptyMessage="有給レポートはありません"
          columns={[
            { key: "employeeCode", header: "職員番号", render: (row) => row.employeeCode },
            { key: "employeeName", header: "氏名", render: (row) => row.employeeName },
            { key: "currentBalance", header: "残数", render: (row) => `${row.currentBalance}日` },
            { key: "latestEntryType", header: "最新区分", render: (row) => props.formatters.formatLeaveLedgerEntryType(row.latestEntryType) },
            {
              key: "latestOccurredOn",
              header: "最新日付",
              render: (row) => (row.latestOccurredOn ? props.formatters.formatDateOnly(row.latestOccurredOn) : "-"),
            },
          ]}
        />
      ) : null}
      {activePanel === "reports-history" ? (
        <DataTable
          id="reports-history"
          title="CSV・PDF履歴"
          rows={props.data.reportFileHistory}
          emptyMessage="履歴はまだありません"
          columns={[
            { key: "createdAt", header: "実行日時", render: (row) => props.formatters.formatDateTime(row.createdAt) },
            { key: "importType", header: "区分", render: (row) => props.formatters.formatImportType(row.importType) },
            { key: "sourceFileName", header: "ファイル", render: (row) => row.sourceFileName },
            { key: "targetPeriod", header: "対象月", render: (row) => row.targetPeriod ?? "-" },
            { key: "successCount", header: "成功", render: (row) => row.successCount },
            { key: "errorCount", header: "失敗", render: (row) => row.errorCount },
            {
              key: "action",
              header: "操作",
              render: (row) =>
                row.downloadAvailable ? (
                  <button
                    type="button"
                    className="table-action"
                    onClick={() => void props.actions.onFileHistoryDownload(row.id, row.downloadFileName ?? row.sourceFileName)}
                  >
                    再取得
                  </button>
                ) : (
                  "-"
                ),
            },
          ]}
        />
      ) : null}
    </section>
  );
}
