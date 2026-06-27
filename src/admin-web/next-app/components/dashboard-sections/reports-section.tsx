import { useMemo, useState } from "react";
import { AttendanceDailyEditor } from "@/components/dashboard-sections/attendance/attendance-daily-editor";
import { isoToTime, normalizeBreaks } from "@/components/dashboard-sections/attendance/attendance-section-utils";
import { DataTable } from "@/components/data-table";
import { ApprovalStatusBadge } from "@/components/status-badge";
import { createAttendanceDaily, loadAttendanceDailyDetail, loadAttendanceDailyGrid, loadAttendanceDailyHistories, resetAttendanceDailyManualEdit, updateAttendanceDaily } from "@/lib/api/attendance";
import { withBasePath } from "@/lib/base-path";
import type {
  AttendanceApproval,
  AttendanceDaily,
  AttendanceDailyBreak,
  AttendanceDailyDetail,
  AttendanceDailyHistory,
  DashboardData,
  Employee,
  ImportHistory,
  PaidLeaveReportRow,
} from "@/types";

type ReportsSectionProps = {
  data: {
    dashboard: DashboardData;
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
  const selectedEmployee = props.data.employees.find((employee) => String(employee.id) === props.filters.reportEmployeeId);
  const selectedEmployeeLabel = selectedEmployee ? `${selectedEmployee.employeeCode} / ${selectedEmployee.name}` : "未選択";
  const [reportView, setReportView] = useState<"menu" | "monthly-list" | "monthly-calendar">("menu");
  const [monthlyRows, setMonthlyRows] = useState<AttendanceDaily[]>([]);
  const [monthlyEmployeeId, setMonthlyEmployeeId] = useState<number | null>(null);
  const [monthlyMessage, setMonthlyMessage] = useState("");
  const [isMonthlyLoading, setIsMonthlyLoading] = useState(false);
  const [editingDaily, setEditingDaily] = useState<AttendanceDailyDetail | null>(null);
  const [editingBreaks, setEditingBreaks] = useState<AttendanceDailyBreak[]>([]);
  const [historyRows, setHistoryRows] = useState<AttendanceDailyHistory[]>([]);
  const [activeEditTab, setActiveEditTab] = useState<"edit" | "history">("edit");
  const [editMessage, setEditMessage] = useState("");
  const [editError, setEditError] = useState("");
  const [isDailyEditLoading, setIsDailyEditLoading] = useState(false);

  const monthlySummaryRows = useMemo(() => buildMonthlySummaryRows(monthlyRows), [monthlyRows]);
  const monthlyEmployeeOptions = useMemo(() => {
    const options = new Map<number, { employeeId: number; employeeCode: string; employeeName: string; departmentName?: string | null }>();

    props.data.employees.forEach((employee) => {
      options.set(employee.id, {
        employeeId: employee.id,
        employeeCode: employee.employeeCode,
        employeeName: employee.name,
        departmentName: employee.departmentName,
      });
    });

    monthlySummaryRows.forEach((row) => {
      options.set(row.employeeId, {
        employeeId: row.employeeId,
        employeeCode: row.employeeCode,
        employeeName: row.employeeName,
        departmentName: row.departmentName,
      });
    });

    return Array.from(options.values()).sort((a, b) => a.employeeCode.localeCompare(b.employeeCode, "ja"));
  }, [monthlySummaryRows, props.data.employees]);
  const selectedMonthlyEmployee = useMemo(
    () => {
      const summary = monthlySummaryRows.find((row) => row.employeeId === monthlyEmployeeId);
      if (summary) {
        return summary;
      }

      const employee = props.data.employees.find((row) => row.id === monthlyEmployeeId);
      return employee
        ? {
            employeeId: employee.id,
            employeeCode: employee.employeeCode,
            employeeName: employee.name,
            departmentName: employee.departmentName,
          }
        : null;
    },
    [monthlyEmployeeId, monthlySummaryRows, props.data.employees],
  );
  const selectedMonthlyCalendarRows = useMemo(
    () => buildMonthlyCalendarRows(props.filters.reportMonth, monthlyRows.filter((row) => row.employeeId === monthlyEmployeeId)),
    [monthlyEmployeeId, monthlyRows, props.filters.reportMonth],
  );

  function confirmReportExport(label: string, scope: string) {
    return window.confirm(
      `${label}を出力します。\n対象月: ${props.filters.reportMonth}\n対象期間: ${props.filters.reportFrom} - ${props.filters.reportTo}\n対象職員: ${
        selectedEmployeeLabel
      }\n形式: ${scope}`,
    );
  }

  function runReportExport(label: string, scope: string, action: () => Promise<void>) {
    if (confirmReportExport(label, scope)) {
      void action();
    }
  }

  async function loadMonthlyRows(targetMonth: string) {
    setMonthlyMessage("");
    setIsMonthlyLoading(true);
    try {
      const rows = await loadAttendanceDailyGrid({ targetMonth });
      setMonthlyRows(rows);
    } catch (error) {
      setMonthlyRows([]);
      setMonthlyMessage(error instanceof Error ? error.message : "月次レポートを読み込めませんでした。");
    } finally {
      setIsMonthlyLoading(false);
    }
  }

  async function openMonthlyReportList() {
    setReportView("monthly-list");
    setMonthlyEmployeeId(null);
    await loadMonthlyRows(props.filters.reportMonth);
  }

  async function openSelectedEmployeeMonthlyCalendar() {
    if (!selectedEmployee) {
      setMonthlyMessage("個人別月次表示を開く職員を選択してください。");
      window.alert("個人別月次表示を開く職員を選択してください。");
      return;
    }

    setReportView("monthly-calendar");
    setMonthlyEmployeeId(selectedEmployee.id);
    await loadMonthlyRows(props.filters.reportMonth);
  }

  async function reloadMonthlyReportList() {
    await loadMonthlyRows(props.filters.reportMonth);
  }

  function openMonthlyCalendar(employeeId: number) {
    props.actions.onReportEmployeeIdChange(String(employeeId));
    setMonthlyEmployeeId(employeeId);
    setReportView("monthly-calendar");
  }

  function handleMonthlyEmployeeChange(value: string) {
    const employeeId = Number(value);
    if (Number.isFinite(employeeId)) {
      props.actions.onReportEmployeeIdChange(value);
      setMonthlyEmployeeId(employeeId);
    }
  }

  function handleMonthlyMonthChange(value: string) {
    props.actions.onReportMonthChange(value);
    void loadMonthlyRows(value);
  }

  async function openDailyEditor(row?: AttendanceDaily) {
    setIsDailyEditLoading(true);
    setEditError("");
    setEditMessage("");
    try {
      let dailyId = row?.id ?? null;
      if (!dailyId) {
        if (!monthlyEmployeeId || !row?.targetDate) {
          throw new Error("日次勤怠を作成する職員または日付を確認できませんでした。");
        }
        const created = await createAttendanceDaily({ employeeId: monthlyEmployeeId, targetDate: row.targetDate });
        dailyId = created.id;
        await loadMonthlyRows(props.filters.reportMonth);
      }
      const [detail, histories] = await Promise.all([loadAttendanceDailyDetail(dailyId), loadAttendanceDailyHistories(dailyId)]);
      setEditingDaily(detail);
      setEditingBreaks(normalizeBreaks(detail.breaks));
      setHistoryRows(histories);
      setActiveEditTab("edit");
      window.setTimeout(() => {
        document.querySelector(".daily-edit-panel")?.scrollIntoView({ behavior: "smooth", block: "start" });
      }, 0);
    } catch (error) {
      setEditError(error instanceof Error ? error.message : "日次勤怠を読み込めませんでした。");
    } finally {
      setIsDailyEditLoading(false);
    }
  }

  async function saveDailyEditor() {
    if (!editingDaily) {
      return;
    }
    try {
      const detail = await updateAttendanceDaily(editingDaily.id, {
        workTypeId: editingDaily.workTypeId ?? null,
        clockInTime: isoToTime(editingDaily.clockInAt),
        clockInNextDay: false,
        clockOutTime: isoToTime(editingDaily.clockOutAt),
        clockOutNextDay: false,
        breaks: editingBreaks.map((breakRow) => ({ ...breakRow, startNextDay: false, endNextDay: false })),
        remark: editingDaily.remark ?? null,
        supervisorComment: editingDaily.supervisorComment ?? null,
        approvalStatus: editingDaily.approvalStatus ?? "PENDING",
        approvalComment: editingDaily.approvalComment ?? null,
      });
      const histories = await loadAttendanceDailyHistories(editingDaily.id);
      setEditingDaily(detail);
      setEditingBreaks(normalizeBreaks(detail.breaks));
      setHistoryRows(histories);
      setEditMessage("日次勤怠を更新しました。");
      setEditError("");
      await loadMonthlyRows(props.filters.reportMonth);
    } catch (error) {
      setEditError(error instanceof Error ? error.message : "日次勤怠の更新に失敗しました。");
    }
  }

  async function resetManualEdit() {
    if (!editingDaily) {
      return;
    }
    try {
      const detail = await resetAttendanceDailyManualEdit(editingDaily.id);
      const histories = await loadAttendanceDailyHistories(editingDaily.id);
      setEditingDaily(detail);
      setEditingBreaks(normalizeBreaks(detail.breaks));
      setHistoryRows(histories);
      setEditMessage("手動補正を解除しました。");
      setEditError("");
      await loadMonthlyRows(props.filters.reportMonth);
    } catch (error) {
      setEditError(error instanceof Error ? error.message : "手動補正の解除に失敗しました。");
    }
  }

  function setEditingClock(field: "clockInAt" | "clockOutAt", time: string) {
    setEditingDaily((current) => {
      if (!current) {
        return current;
      }
      if (!time) {
        return { ...current, [field]: null };
      }
      const base = new Date(`${current.targetDate}T00:00:00`);
      const date = `${base.getFullYear()}-${`${base.getMonth() + 1}`.padStart(2, "0")}-${`${base.getDate()}`.padStart(2, "0")}`;
      return { ...current, [field]: `${date}T${time}:00` };
    });
  }

  return (
    <section className="stack-section section-enter delay-3">
      {activePanel === "reports-export" ? (
      <section id="reports-export" className="panel action-panel anchor-panel">
        <div className="panel-header">
          <div>
            <h3>{reportView === "menu" ? "レポート" : reportView === "monthly-list" ? "月次レポート" : "月次レポート 個人別"}</h3>
          </div>
          {reportView !== "menu" ? (
            <button
              type="button"
              className="secondary"
              onClick={() => {
                if (reportView === "monthly-calendar") {
                  setReportView("monthly-list");
                  setMonthlyEmployeeId(null);
                } else {
                  setReportView("menu");
                }
              }}
            >
              戻る
            </button>
          ) : null}
        </div>

        {reportView === "menu" ? (
          <div className="report-output-layout">
            <div className="report-output-conditions">
              <label>
                対象月
                <input type="month" value={props.filters.reportMonth} onChange={(event) => props.actions.onReportMonthChange(event.target.value)} />
              </label>
              <label>
                日次CSV 開始日
                <input type="date" value={props.filters.reportFrom} onChange={(event) => props.actions.onReportFromChange(event.target.value)} />
              </label>
              <label>
                日次CSV 終了日
                <input type="date" value={props.filters.reportTo} onChange={(event) => props.actions.onReportToChange(event.target.value)} />
              </label>
              <label>
                職員別PDF 対象職員
                <select value={props.filters.reportEmployeeId} onChange={(event) => props.actions.onReportEmployeeIdChange(event.target.value)}>
                  <option value="">選択してください</option>
                  {props.data.employees.map((employee) => (
                    <option key={employee.id} value={employee.id}>
                      {employee.employeeCode} / {employee.name}
                    </option>
                  ))}
                </select>
              </label>
              <div className="report-output-target" aria-live="polite">
                <span>出力対象</span>
                <strong>{props.filters.reportMonth}</strong>
                <span>{props.filters.reportFrom} - {props.filters.reportTo}</span>
                <span>{selectedEmployeeLabel}</span>
              </div>
            </div>
            <div className="report-hub-grid">
              <div className="report-menu-column">
                <h4>
                  <span>レポート</span>
                  <small>画面表示・PDF作成</small>
                </h4>
                <button type="button" className="secondary" onClick={() => void openMonthlyReportList()}>
                  <span className="report-action-copy">
                    <span className="report-action-label">月次レポート</span>
                    <span className="report-action-detail">月ごとの勤怠一覧を確認</span>
                  </span>
                </button>
                <button type="button" className="secondary" onClick={() => void openSelectedEmployeeMonthlyCalendar()}>
                  <span className="report-action-copy">
                    <span className="report-action-label">個人別月次表示</span>
                    <span className="report-action-detail">選択中の職員を日別で表示</span>
                  </span>
                </button>
                <button
                  type="button"
                  className="secondary"
                  onClick={() => runReportExport("日次勤怠PDF", "PDF", () => props.actions.onDownloadDailyAttendancePdf(props.filters.reportMonth))}
                >
                  <span className="report-action-copy">
                    <span className="report-action-label">日次勤怠PDF</span>
                    <span className="report-action-detail">対象月の日別勤怠をPDF出力</span>
                  </span>
                </button>
                <button
                  type="button"
                  className="secondary"
                  disabled={!selectedEmployee}
                  onClick={() => runReportExport("職員別勤務PDF", "PDF", () => props.actions.onDownloadMonthlyWorksPdf())}
                >
                  <span className="report-action-copy">
                    <span className="report-action-label">職員別勤務PDF</span>
                    <span className="report-action-detail">選択中の職員の勤務表をPDF出力</span>
                  </span>
                </button>
              </div>
              <div className="report-menu-column">
                <h4>
                  <span>データ出力</span>
                  <small>CSVファイル作成</small>
                </h4>
                <button
                  type="button"
                  className="secondary"
                  onClick={() => runReportExport("月次集計CSV", "CSV", () => props.actions.onDownloadMonthlyAttendanceCsv(props.filters.reportMonth))}
                >
                  <span className="report-action-copy">
                    <span className="report-action-label">月次集計データ出力</span>
                    <span className="report-action-detail">対象月の集計CSVを出力</span>
                  </span>
                </button>
                <button
                  type="button"
                  className="secondary"
                  onClick={() => runReportExport("日次勤怠CSV", "CSV", () => props.actions.onDownloadDailyAttendanceCsv(props.filters.reportFrom, props.filters.reportTo))}
                >
                  <span className="report-action-copy">
                    <span className="report-action-label">日次勤怠データ出力</span>
                    <span className="report-action-detail">指定期間の日次CSVを出力</span>
                  </span>
                </button>
                <button
                  type="button"
                  className="secondary"
                  onClick={() => runReportExport("給与ソフトCSV", "CSV", () => props.actions.onDownloadMonthlyPayrollCsv())}
                >
                  <span className="report-action-copy">
                    <span className="report-action-label">給与ソフトCSV</span>
                    <span className="report-action-detail">給与取込用CSVを出力</span>
                  </span>
                </button>
              </div>
            </div>
          </div>
        ) : null}

        {reportView === "monthly-list" ? (
          <div className="report-workspace">
            <div className="report-toolbar">
              <label>
                対象月
                <input type="month" value={props.filters.reportMonth} onChange={(event) => props.actions.onReportMonthChange(event.target.value)} />
              </label>
              <button type="button" onClick={() => void reloadMonthlyReportList()} disabled={isMonthlyLoading}>
                {isMonthlyLoading ? "読込中..." : "月次レポート更新"}
              </button>
            </div>
            {monthlyMessage ? <p className="feedback">{monthlyMessage}</p> : null}
            <div className="table-wrap report-table-wrap">
              <table className="report-monthly-table">
                <thead>
                  <tr>
                    <th>職員番号</th>
                    <th>氏名</th>
                    <th>部門</th>
                    <th>勤務日数</th>
                    <th>総労働時間</th>
                    <th>実働時間</th>
                    <th>残業時間</th>
                    <th>遅刻日数</th>
                    <th>早退日数</th>
                    <th>休日日数</th>
                  </tr>
                </thead>
                <tbody>
                  {monthlySummaryRows.length === 0 ? (
                    <tr>
                      <td colSpan={10} className="table-empty-cell">
                        {isMonthlyLoading ? "月次レポートを読み込んでいます" : "月次レポートはありません"}
                      </td>
                    </tr>
                  ) : (
                    monthlySummaryRows.map((row) => (
                      <tr key={row.employeeId}>
                        <td>{row.employeeCode}</td>
                        <td>
                          <button type="button" className="text-link-button" onClick={() => openMonthlyCalendar(row.employeeId)}>
                            {row.employeeName}
                          </button>
                        </td>
                        <td>{row.departmentName || "-"}</td>
                        <td>{row.workDays}日</td>
                        <td>{formatMinutes(row.totalWorkMinutes)}</td>
                        <td>{formatMinutes(row.actualWorkMinutes)}</td>
                        <td>{formatMinutes(row.overtimeMinutes)}</td>
                        <td>{row.lateDays}日</td>
                        <td>{row.earlyLeaveDays}日</td>
                        <td>{row.holidayDays}日</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        ) : null}

        {reportView === "monthly-calendar" ? (
          <div className="report-workspace">
            <div className="report-employee-summary">
              <div>
                <span className="detail-label">社員番号</span>
                <strong>{selectedMonthlyEmployee?.employeeCode ?? "-"}</strong>
              </div>
              <div>
                <span className="detail-label">氏名</span>
                <strong>{selectedMonthlyEmployee?.employeeName ?? "-"}</strong>
              </div>
              <div>
                <span className="detail-label">部門名</span>
                <strong>{selectedMonthlyEmployee?.departmentName || "-"}</strong>
              </div>
              <div>
                <span className="detail-label">対象月</span>
                <strong>{props.filters.reportMonth}</strong>
              </div>
            </div>
            <div className="report-toolbar">
              <label>
                職員を切替
                <select value={monthlyEmployeeId ?? ""} onChange={(event) => handleMonthlyEmployeeChange(event.target.value)}>
                  {monthlyEmployeeOptions.length === 0 ? (
                    <option value="">職員未登録</option>
                  ) : (
                    monthlyEmployeeOptions.map((employee) => (
                      <option key={employee.employeeId} value={employee.employeeId}>
                        {employee.employeeCode} / {employee.employeeName}
                      </option>
                    ))
                  )}
                </select>
              </label>
              <label>
                対象月
                <input
                  type="month"
                  value={props.filters.reportMonth}
                  onChange={(event) => handleMonthlyMonthChange(event.target.value)}
                />
              </label>
              <button type="button" className="secondary" onClick={() => void props.actions.onDownloadMonthlyWorksPdf()}>
                この職員のPDF出力
              </button>
            </div>
            <div className="table-wrap report-table-wrap">
              <table className="report-calendar-table">
                <thead>
                  <tr>
                    <th>日付</th>
                    <th>勤務区分</th>
                    <th>出勤時刻</th>
                    <th>退勤時刻</th>
                    <th>総労働時間</th>
                    <th>休憩時間</th>
                    <th>実働時間</th>
                    <th>時間有給</th>
                    <th>残業時間</th>
                    <th>承認</th>
                    <th>備考</th>
                  </tr>
                </thead>
                <tbody>
                  {selectedMonthlyCalendarRows.length === 0 ? (
                    <tr>
                      <td colSpan={11} className="table-empty-cell">
                        個人別の月次データはありません
                      </td>
                    </tr>
                  ) : (
                    selectedMonthlyCalendarRows.map((row) => (
                      <tr key={`${monthlyEmployeeId ?? "none"}-${row.targetDate}`} className={getCalendarRowClassName(row)}>
                        <td>
                          <div className="attendance-edit-date-cell report-date-edit-cell">
                            <span>{formatDateLabel(row.targetDate)}</span>
                            <button
                              type="button"
                              className="icon-table-action"
                              title="この日付を修正"
                              aria-label={`${formatDateLabel(row.targetDate)}を修正`}
                              onClick={() => void openDailyEditor(row.daily)}
                              disabled={isDailyEditLoading}
                            >
                              <img src={withBasePath("/icons/attendance-edit-pencil.png")} alt="" aria-hidden="true" />
                            </button>
                          </div>
                        </td>
                        <td>{row.daily?.scheduleName || row.holidayName || (row.daily?.absenceFlag ? "公休" : "-")}</td>
                        <td>{props.formatters.formatTimeOnly(row.daily?.clockInAt)}</td>
                        <td>{props.formatters.formatTimeOnly(row.daily?.clockOutAt)}</td>
                        <td>{formatDailyMinutes(row.daily, getDailyTotalMinutes(row.daily))}</td>
                        <td>{formatDailyMinutes(row.daily, row.daily?.breakMinutes ?? 0)}</td>
                        <td>{formatDailyMinutes(row.daily, row.daily?.workMinutes)}</td>
                        <td>{formatDailyMinutes(row.daily, row.daily?.hourPaidLeaveMinutes ?? 0)}</td>
                        <td>{formatDailyMinutes(row.daily, getDailyOvertimeMinutes(row.daily))}</td>
                        <td className={getApprovalCellClassName(row.daily?.approvalStatus)}>
                          {row.daily ? props.formatters.formatApprovalStatus(row.daily.approvalStatus) : "-"}
                        </td>
                        <td>{row.daily?.remark || row.daily?.alertSummary || row.holidayName || "-"}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
            {editingDaily ? (
              <AttendanceDailyEditor
                editingDaily={editingDaily}
                editingBreaks={editingBreaks}
                historyRows={historyRows}
                activeEditTab={activeEditTab}
                editError={editError}
                editMessage={editMessage}
                dashboard={props.data.dashboard}
                formatters={props.formatters}
                onClose={() => setEditingDaily(null)}
                onSave={saveDailyEditor}
                onResetManualEdit={resetManualEdit}
                onActiveEditTabChange={setActiveEditTab}
                setEditingDaily={setEditingDaily}
                setEditingBreaks={setEditingBreaks}
                setEditingClock={setEditingClock}
              />
            ) : editError ? (
              <p className="banner">{editError}</p>
            ) : null}
          </div>
        ) : null}
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
            { key: "approvalStatus", header: "承認", render: (row) => <ApprovalStatusBadge value={row.approvalStatus} format={props.formatters.formatApprovalStatus} /> },
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
            { key: "approvalStatus", header: "状態", render: (row) => <ApprovalStatusBadge value={row.approvalStatus} format={props.formatters.formatApprovalStatus} /> },
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
            { key: "sourceFileName", header: "ファイル", render: (row) => row.downloadFileName ?? row.sourceFileName },
            { key: "targetPeriod", header: "対象月", render: (row) => row.targetPeriod ?? "-" },
            { key: "successCount", header: "成功", render: (row) => row.successCount },
            { key: "errorCount", header: "失敗", render: (row) => row.errorCount },
            { key: "downloadAvailable", header: "再取得", render: (row) => (row.downloadAvailable ? "可" : "不可") },
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

type MonthlySummaryRow = {
  employeeId: number;
  employeeCode: string;
  employeeName: string;
  departmentName?: string | null;
  workDays: number;
  totalWorkMinutes: number;
  actualWorkMinutes: number;
  overtimeMinutes: number;
  lateDays: number;
  earlyLeaveDays: number;
  holidayDays: number;
};

type MonthlyCalendarRow = {
  targetDate: string;
  daily?: AttendanceDaily;
  isSaturday: boolean;
  isSunday: boolean;
  holidayName?: string;
};

function buildMonthlySummaryRows(rows: AttendanceDaily[]): MonthlySummaryRow[] {
  const summaries = new Map<number, MonthlySummaryRow>();

  rows.forEach((row) => {
    const current = summaries.get(row.employeeId) ?? {
      employeeId: row.employeeId,
      employeeCode: row.employeeCode,
      employeeName: row.employeeName,
      departmentName: row.departmentName,
      workDays: 0,
      totalWorkMinutes: 0,
      actualWorkMinutes: 0,
      overtimeMinutes: 0,
      lateDays: 0,
      earlyLeaveDays: 0,
      holidayDays: 0,
    };

    const worked = Boolean(row.clockInAt || row.clockOutAt || (row.workMinutes ?? 0) > 0);
    if (row.absenceFlag) {
      current.holidayDays += 1;
    }
    if (worked) {
      current.workDays += 1;
      current.totalWorkMinutes += getDailyTotalMinutes(row) ?? 0;
      current.actualWorkMinutes += row.workMinutes ?? 0;
      current.overtimeMinutes += getDailyOvertimeMinutes(row) ?? 0;
    }
    if (row.alerts?.some((alert) => alert.code === "LATE")) {
      current.lateDays += 1;
    }
    if (row.alerts?.some((alert) => alert.code === "EARLY_LEAVE")) {
      current.earlyLeaveDays += 1;
    }

    summaries.set(row.employeeId, current);
  });

  return Array.from(summaries.values()).sort((a, b) => a.employeeCode.localeCompare(b.employeeCode, "ja"));
}

function buildMonthlyCalendarRows(targetMonth: string, rows: AttendanceDaily[]): MonthlyCalendarRow[] {
  const [year, month] = targetMonth.split("-").map((value) => Number(value));
  if (!year || !month) {
    return [];
  }

  const rowByDate = new Map(rows.map((row) => [row.targetDate, row]));
  const datesInMonth = new Date(year, month, 0).getDate();

  return Array.from({ length: datesInMonth }, (_, index) => {
    const day = index + 1;
    const targetDate = `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
    const date = new Date(year, month - 1, day);
    const weekday = date.getDay();

    return {
      targetDate,
      daily: rowByDate.get(targetDate),
      isSaturday: weekday === 6,
      isSunday: weekday === 0,
      holidayName: getJapaneseHolidayName(date),
    };
  });
}

function getCalendarRowClassName(row: MonthlyCalendarRow) {
  const classNames = [];
  if (row.holidayName || row.isSunday || row.daily?.absenceFlag) {
    classNames.push("report-holiday-row");
  } else if (row.isSaturday) {
    classNames.push("report-saturday-row");
  }
  if (!row.daily) {
    classNames.push("report-empty-day-row");
  }
  return classNames.length > 0 ? classNames.join(" ") : undefined;
}

function getApprovalCellClassName(value?: string | null) {
  const normalized = (value ?? "").toUpperCase();
  if (!normalized) {
    return undefined;
  }

  return `report-approval-cell report-approval-${normalized.toLowerCase()}`;
}

function formatMinutes(value?: number | null) {
  const minutes = Math.max(0, value ?? 0);
  const hours = Math.floor(minutes / 60);
  const remain = minutes % 60;
  return `${hours}:${String(remain).padStart(2, "0")}`;
}

function formatDailyMinutes(row: AttendanceDaily | undefined, value?: number | null) {
  return row ? formatMinutes(value) : "-";
}

function getDailyTotalMinutes(row?: AttendanceDaily) {
  if (!row || row.workMinutes === null || row.workMinutes === undefined) {
    return null;
  }

  return (row.workMinutes ?? 0) + (row.breakMinutes ?? 0);
}

function getDailyOvertimeMinutes(row?: AttendanceDaily) {
  if (!row || row.workMinutes === null || row.workMinutes === undefined) {
    return null;
  }

  return Math.max(0, row.workMinutes - 8 * 60);
}

function formatDateLabel(value: string) {
  const date = new Date(`${value}T00:00:00`);
  const weekdays = ["日", "月", "火", "水", "木", "金", "土"];
  return `${String(date.getDate()).padStart(2, "0")} ${weekdays[date.getDay()]}`;
}

function getJapaneseHolidayName(date: Date) {
  const holidays = buildJapaneseHolidayMap(date.getFullYear());
  return holidays.get(formatDateKey(date));
}

function buildJapaneseHolidayMap(year: number) {
  const holidays = new Map<string, string>();

  const add = (month: number, day: number, name: string) => {
    holidays.set(formatDateKey(new Date(year, month - 1, day)), name);
  };
  const addHappyMonday = (month: number, week: number, name: string) => {
    add(month, nthWeekdayOfMonth(year, month, week, 1), name);
  };

  add(1, 1, "元日");
  addHappyMonday(1, 2, "成人の日");
  add(2, 11, "建国記念の日");
  add(2, 23, "天皇誕生日");
  add(3, getVernalEquinoxDay(year), "春分の日");
  add(4, 29, "昭和の日");
  add(5, 3, "憲法記念日");
  add(5, 4, "みどりの日");
  add(5, 5, "こどもの日");
  addHappyMonday(7, 3, "海の日");
  add(8, 11, "山の日");
  addHappyMonday(9, 3, "敬老の日");
  add(9, getAutumnalEquinoxDay(year), "秋分の日");
  addHappyMonday(10, 2, "スポーツの日");
  add(11, 3, "文化の日");
  add(11, 23, "勤労感謝の日");

  addSubstituteHolidays(year, holidays);
  return holidays;
}

function addSubstituteHolidays(year: number, holidays: Map<string, string>) {
  const holidayEntries = Array.from(holidays.entries()).sort(([a], [b]) => a.localeCompare(b));
  for (const [dateKey] of holidayEntries) {
    const date = parseDateKey(dateKey);
    if (date.getDay() !== 0) {
      continue;
    }

    const substitute = new Date(date);
    do {
      substitute.setDate(substitute.getDate() + 1);
    } while (holidays.has(formatDateKey(substitute)));

    if (substitute.getFullYear() === year) {
      holidays.set(formatDateKey(substitute), "振替休日");
    }
  }
}

function nthWeekdayOfMonth(year: number, month: number, week: number, weekday: number) {
  const firstDay = new Date(year, month - 1, 1);
  const offset = (weekday - firstDay.getDay() + 7) % 7;
  return 1 + offset + (week - 1) * 7;
}

function getVernalEquinoxDay(year: number) {
  return Math.floor(20.8431 + 0.242194 * (year - 1980) - Math.floor((year - 1980) / 4));
}

function getAutumnalEquinoxDay(year: number) {
  return Math.floor(23.2488 + 0.242194 * (year - 1980) - Math.floor((year - 1980) / 4));
}

function formatDateKey(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

function parseDateKey(value: string) {
  const [year, month, day] = value.split("-").map((part) => Number(part));
  return new Date(year, month - 1, day);
}
