import { useEffect, useMemo, useRef, useState } from "react";
import { AnnualPaidLeaveRegister } from "./leave/annual-paid-leave-register";
import { reportPeriod } from "@/lib/report-period";
import { japaneseHolidayName } from "@/lib/japanese-holidays";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { AttendanceDailyEditor } from "@/components/dashboard-sections/attendance/attendance-daily-editor";
import { recordBreakOvertime } from "@/components/dashboard-sections/attendance/record-break-overtime";
import type { BreakOvertimeConfirmation } from "@/types";
import { buildDailyUpdatePayload, isNextDay, normalizeBreaks } from "@/components/dashboard-sections/attendance/attendance-section-utils";
import { ApiRequestError } from "@/lib/api/core";
import type { FieldErrors } from "@/components/form-field-errors";
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
  menuResetKey?: number;
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
    onOpenMonthClose: (month: string) => void;
    onDownloadMonthlyAttendanceCsv: (month: string, filters?: { employeeCode?: string; departmentName?: string }) => Promise<void>;
    onDownloadMonthlyPayrollCsv: () => Promise<void>;
    onDownloadDailyAttendanceCsv: (from: string, to: string, filters?: { employeeCode?: string; departmentName?: string }) => Promise<void>;
    onDownloadDailyAttendancePdf: (month: string, filters?: { employeeCode?: string; departmentName?: string }) => Promise<void>;
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
  const [reportView, setReportView] = useState<"menu" | "monthly-list" | "monthly-calendar" | "leave-register">("menu");
  const [loadedRows, setMonthlyRows] = useState<AttendanceDaily[]>([]);
  const [loadedMonth, setLoadedMonth] = useState("");
  const monthlyRows = loadedMonth === props.filters.reportMonth ? loadedRows : [];
  const monthlyRequestId = useRef(0);
  const [monthlyEmployeeId, setMonthlyEmployeeId] = useState<number | null>(null);
  const [monthlyMessage, setMonthlyMessage] = useState("");
  const [isMonthlyLoading, setIsMonthlyLoading] = useState(false);
  const [editingDaily, setEditingDaily] = useState<AttendanceDailyDetail | null>(null);
  const [originalDaily, setOriginalDaily] = useState<AttendanceDailyDetail | null>(null);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [dailyBusy, setDailyBusy] = useState(false);
  const dailyMutationBusy = useRef(false);
  const [editingBreaks, setEditingBreaks] = useState<AttendanceDailyBreak[]>([]);
  const [historyRows, setHistoryRows] = useState<AttendanceDailyHistory[]>([]);
  const [activeEditTab, setActiveEditTab] = useState<"detail" | "edit" | "history">("detail");
  const [editMessage, setEditMessage] = useState("");
  const [editError, setEditError] = useState("");
  const [isDailyEditLoading, setIsDailyEditLoading] = useState(false);
  const [pendingReportExport, setPendingReportExport] = useState<{ label: string; scope: string; description: string; action: () => Promise<void> } | null>(null);
  useEffect(() => () => { monthlyRequestId.current += 1; }, []);

  useEffect(() => {
    setReportView("menu");
    setMonthlyEmployeeId(null);
    setEditingDaily(null);
    setEditError("");
    setEditMessage("");
  }, [props.menuResetKey]);

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
    () => buildMonthlyCalendarRows(props.filters.reportMonth, monthlyRows.filter((row) => row.employeeId === monthlyEmployeeId), props.data.employees.find((e) => e.id === monthlyEmployeeId)?.employmentType),
    [monthlyEmployeeId, monthlyRows, props.filters.reportMonth, props.data.employees],
  );

  function runReportExport(label: string, scope: string, action: () => Promise<void>) {
    const employee = label === "職員別勤務PDF" ? selectedEmployeeLabel : "全職員";
    const period = label === "日次勤怠CSV" ? `${props.filters.reportFrom} - ${props.filters.reportTo}`
      : label === "日次勤怠PDF" ? `${props.filters.reportMonth}（暦月）`
      : `${props.filters.reportMonth}（パート・扶養内: 前月21日～当月20日／その他: 暦月）`;
    setPendingReportExport({ label, scope, description: `対象期間: ${period}／対象職員: ${employee}／形式: ${scope}`, action });
  }

  async function loadMonthlyRows(targetMonth: string) {
    const requestId = ++monthlyRequestId.current;
    setMonthlyRows([]);
    setMonthlyMessage("");
    setIsMonthlyLoading(true);
    try {
      const rows = await loadAttendanceDailyGrid({ targetMonth, periodBasis: "PAYROLL" });
      if (requestId !== monthlyRequestId.current) return;
      setMonthlyRows(rows);
      setLoadedMonth(targetMonth);
    } catch (error) {
      if (requestId !== monthlyRequestId.current) return;
      setMonthlyRows([]);
      setMonthlyMessage(error instanceof Error ? error.message : "月次レポートを読み込めませんでした。");
    } finally {
      if (requestId === monthlyRequestId.current) setIsMonthlyLoading(false);
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

  async function openDailyEditor(row?: AttendanceDaily, targetDate = row?.targetDate) {
    if (dailyMutationBusy.current || isDailyEditLoading) return;
    setIsDailyEditLoading(true);
    setEditError("");
    setEditMessage("");
    try {
      let dailyId = row?.id ?? null;
      if (!dailyId) {
        if (!monthlyEmployeeId || !targetDate) {
          throw new Error("日次勤怠を作成する職員または日付を確認できませんでした。");
        }
        const created = await createAttendanceDaily({ employeeId: monthlyEmployeeId, targetDate });
        dailyId = created.id;
        await loadMonthlyRows(props.filters.reportMonth);
      }
      const [detail, histories] = await Promise.all([loadAttendanceDailyDetail(dailyId), loadAttendanceDailyHistories(dailyId)]);
      setEditingDaily(detail);
      setOriginalDaily(detail); setFieldErrors({});
      setEditingBreaks(normalizeBreaks(detail.breaks));
      setHistoryRows(histories);
      setActiveEditTab("detail");
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
    if (!editingDaily || !originalDaily || dailyMutationBusy.current) {
      return;
    }
    const payload=buildDailyUpdatePayload(originalDaily,editingDaily,editingBreaks);
    if (!Object.keys(payload).length) {setEditMessage("変更はありません。");return;}
    dailyMutationBusy.current=true;setDailyBusy(true);setFieldErrors({});
    try {
      const detail = await updateAttendanceDaily(editingDaily.id, payload);
      setEditingDaily(detail);
      setOriginalDaily(detail);
      setEditingBreaks(normalizeBreaks(detail.breaks));
      setEditMessage("日次勤怠を更新しました。");
      setEditError("");
      const [historyResult, refreshResult] = await Promise.allSettled([loadAttendanceDailyHistories(editingDaily.id), loadMonthlyRows(props.filters.reportMonth)]);
      if (historyResult.status === "fulfilled") setHistoryRows(historyResult.value);
      else setHistoryRows([]);
      if (historyResult.status === "rejected" || refreshResult.status === "rejected") setEditMessage("保存は完了しました。履歴または一覧の再取得に失敗したため、再読込して確認してください。");
    } catch (error) {
      setEditError(error instanceof Error ? error.message : "日次勤怠の更新に失敗しました。");
      if(error instanceof ApiRequestError)setFieldErrors(error.fieldErrors);
    } finally {dailyMutationBusy.current=false;setDailyBusy(false);}
  }

  async function confirmBreakOvertime(id: number, inputs: BreakOvertimeConfirmation) {
    if (dailyMutationBusy.current || editingDaily?.id !== id) throw new Error("勤務日を選び直してください。");
    dailyMutationBusy.current = true;
    setDailyBusy(true);
    setEditError("");
    setEditMessage("");
    try {
      const result = await recordBreakOvertime(id, inputs, () => loadMonthlyRows(props.filters.reportMonth));
      setEditingDaily(result.detail);
      setOriginalDaily(result.detail);
      setEditingBreaks(normalizeBreaks(result.detail?.breaks));
      setHistoryRows(result.histories);
      setEditMessage(result.message);
      setEditError(result.refreshError);
    } finally {
      dailyMutationBusy.current = false;
      setDailyBusy(false);
    }
  }

  async function resetManualEdit() {
    if (!editingDaily || dailyMutationBusy.current) {
      return;
    }
    dailyMutationBusy.current=true;setDailyBusy(true);setFieldErrors({});
    try {
      const detail = await resetAttendanceDailyManualEdit(editingDaily.id);
      setEditingDaily(detail);
      setOriginalDaily(detail);
      setEditingBreaks(normalizeBreaks(detail.breaks));
      setEditMessage("手動補正を解除しました。");
      setEditError("");
      const [historyResult, refreshResult] = await Promise.allSettled([loadAttendanceDailyHistories(editingDaily.id), loadMonthlyRows(props.filters.reportMonth)]);
      if (historyResult.status === "fulfilled") setHistoryRows(historyResult.value);
      else setHistoryRows([]);
      if (historyResult.status === "rejected" || refreshResult.status === "rejected") setEditMessage("手動補正の解除は完了しました。履歴または一覧を再読込して確認してください。");
    } catch (error) {
      setEditError(error instanceof Error ? error.message : "手動補正の解除に失敗しました。");
    } finally {dailyMutationBusy.current=false;setDailyBusy(false);}
  }

  function setEditingClock(field: "clockInAt" | "clockOutAt", time: string, nextDay?: boolean) {
    setEditingDaily((current) => {
      if (!current) {
        return current;
      }
      if (!time) {
        return { ...current, [field]: null };
      }
      const base = new Date(`${current.targetDate}T00:00:00`);
      if(nextDay??isNextDay(current[field],current.targetDate))base.setDate(base.getDate()+1);
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
            <h3>{reportView === "menu" || reportView === "leave-register" ? "レポート" : reportView === "monthly-list" ? "月次レポート" : "月次レポート 個人別"}</h3>
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

        {reportView === "leave-register" && <AnnualPaidLeaveRegister initialEmployeeId={props.filters.reportEmployeeId} />}
        {reportView === "menu" ? (
          <div className="report-output-layout">
            <div className="report-output-conditions">
              <label>
                対象月
                <input type="month" value={props.filters.reportMonth} onChange={(event) => handleMonthlyMonthChange(event.target.value)} />
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
                <span>集計対象月</span>
                <strong>{props.filters.reportMonth}</strong>
                <span>月次: パート・扶養内は前月21日～当月20日、その他は暦月</span>
                <span>日次CSV: {props.filters.reportFrom} - {props.filters.reportTo}</span>
                <span>職員別PDF: {selectedEmployeeLabel}／その他の出力: 全職員</span>
              </div>
            </div>
            <div className="report-hub-grid">
              <div className="report-menu-column">
                <h4>
                  <span>レポート</span>
                  <small>画面表示・PDF作成</small>
                </h4>
                <button type="button" className="secondary" aria-label="年次有給休暇管理簿" onClick={() => setReportView("leave-register")}><span className="report-action-label">年次有給休暇管理簿</span></button>
                <button type="button" className="secondary" onClick={() => void openMonthlyReportList()}>
                  <span className="report-action-copy">
                    <span className="report-action-label">月次レポート</span>
                    <span className="report-action-detail">月ごとの勤怠一覧を確認</span>
                  </span>
                </button>
                <button type="button" className="secondary" disabled={!/^\d{4}-\d{2}$/.test(props.filters.reportMonth)} onClick={() => props.actions.onOpenMonthClose(props.filters.reportMonth)}>
                  <span className="report-action-copy">
                    <span className="report-action-label">月次締めへ</span>
                    <span className="report-action-detail">選択月の全職員の承認・締め前チェック</span>
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
            {monthlyMessage ? <p className="feedback" role="alert">{monthlyMessage}</p> : null}
          </div>
        ) : null}

        {reportView === "monthly-list" ? (
          <div className="report-workspace">
            <div className="report-toolbar">
              <label>
                対象月
                <input type="month" value={props.filters.reportMonth} onChange={(event) => handleMonthlyMonthChange(event.target.value)} />
              </label>
              <button type="button" onClick={() => void reloadMonthlyReportList()} disabled={isMonthlyLoading}>
                {isMonthlyLoading ? "読込中..." : "月次レポート更新"}
              </button>
              <button type="button" className="secondary" disabled={!/^\d{4}-\d{2}$/.test(props.filters.reportMonth)} onClick={() => props.actions.onOpenMonthClose(props.filters.reportMonth)}>この月の月次締めへ</button>
            </div>
            <p className="muted">月次締めは暦月の全職員が対象です。パート・扶養内のレポート集計期間（前月21日〜当月20日）とは異なります。</p>
            {monthlyMessage ? <p className="feedback">{monthlyMessage}</p> : null}
            <div className="table-wrap report-table-wrap">
              <table className="report-monthly-table">
                <thead>
                  <tr>
                    <th>職員番号</th>
                    <th>氏名</th>
                    <th>部門</th>
                    <th>勤務日数</th>
                    <th>勤務時間</th>
                    <th>実働時間</th>
                    <th>残業時間</th>
                    <th>子の看護等休暇</th>
                    <th>介護休暇</th>
                    <th>遅刻日数</th>
                    <th>早退日数</th>
                    <th>休日日数</th>
                  </tr>
                </thead>
                <tbody>
                  {monthlySummaryRows.length === 0 ? (
                    <tr>
                      <td colSpan={12} className="table-empty-cell">
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
                        <td>{row.childCareLeaveDays > 0 ? `${row.childCareLeaveDays}日 / ` : ""}{formatMinutes(row.childCareLeaveMinutes)}</td>
                        <td>{row.nursingCareLeaveDays > 0 ? `${row.nursingCareLeaveDays}日 / ` : ""}{formatMinutes(row.nursingCareLeaveMinutes)}</td>
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
                <small style={{ display: "block" }}>{selectedMonthlyCalendarRows[0]?.targetDate} ～ {selectedMonthlyCalendarRows[selectedMonthlyCalendarRows.length - 1]?.targetDate}</small>
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
                <caption style={{ textAlign: "left", padding: "8px 0" }}>上段は計算に使う時刻、下段の薄字は元の打刻です。実働時間には有給休暇を含みます。</caption>
                <thead>
                  <tr>
                    <th>日付</th>
                    <th>勤務区分</th>
                    <th>出勤時間/元の打刻</th>
                    <th>退勤時間/元の打刻</th>
                    <th>時刻の採用元</th>
                    <th>勤務時間</th>
                    <th>休憩時間</th>
                    <th>実働時間</th>
                    <th>時間有給</th>
                    <th>子の看護等休暇</th>
                    <th>介護休暇</th>
                    <th>残業時間</th>
                    <th>承認</th>
                    <th>備考</th>
                  </tr>
                </thead>
                <tbody>
                  {selectedMonthlyCalendarRows.length === 0 ? (
                    <tr>
                      <td colSpan={14} className="table-empty-cell">
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
                              onClick={() => void openDailyEditor(row.daily, row.targetDate)}
                              disabled={isDailyEditLoading}
                            >
                              <img src={withBasePath("/icons/attendance-edit-pencil.png")} alt="" aria-hidden="true" />
                            </button>
                          </div>
                        </td>
                        <td>{row.daily?.scheduleName || row.holidayName || (row.daily?.absenceFlag ? "公休" : "-")}</td>
                        <td><strong>{props.formatters.formatTimeOnly(row.daily?.clockInAt)}</strong><small style={{ display: "block", color: "#58677b" }}>{props.formatters.formatTimeOnly(row.daily?.rawClockInAt)}</small></td>
                        <td><strong>{props.formatters.formatTimeOnly(row.daily?.clockOutAt)}</strong><small style={{ display: "block", color: "#58677b" }}>{props.formatters.formatTimeOnly(row.daily?.rawClockOutAt)}</small></td>
                        <td>{attendanceTimeSource(row.daily)}</td>
                        <td>{formatDailyMinutes(row.daily, getDailyTotalMinutes(row.daily))}</td>
                        <td>{formatDailyMinutes(row.daily, !row.daily?.breakMinutes && (row.daily?.hourPaidLeaveMinutes || row.daily?.paidLeaveUnit) ? null : row.daily?.breakMinutes ?? 0)}</td>
                        <td>{formatDailyMinutes(row.daily, row.daily?.reportWorkMinutes === undefined ? row.daily?.workMinutes : row.daily.reportWorkMinutes)}</td>
                        <td>{formatDailyMinutes(row.daily, row.daily?.hourPaidLeaveMinutes ?? 0)}</td>
                        <td>{formatCareLeave(row.daily, row.daily?.childCareLeaveDays, row.daily?.childCareLeaveMinutes)}</td>
                        <td>{formatCareLeave(row.daily, row.daily?.nursingCareLeaveDays, row.daily?.nursingCareLeaveMinutes)}</td>
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
            {editingDaily && editingDaily.employeeId === monthlyEmployeeId && selectedMonthlyCalendarRows.some(row => row.daily?.id === editingDaily.id) ? (
              <AttendanceDailyEditor
                editingDaily={editingDaily}
                editingBreaks={editingBreaks}
                historyRows={historyRows}
                activeEditTab={activeEditTab}
                fieldErrors={fieldErrors}
                busy={dailyBusy}
                editError={editError}
                editMessage={editMessage}
                dashboard={props.data.dashboard}
                formatters={props.formatters}
                onClose={() => setEditingDaily(null)}
                onSave={saveDailyEditor}
                onResetManualEdit={resetManualEdit}
                onApplyBreakOvertime={confirmBreakOvertime}
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
          title="勤怠承認履歴（直近100件）"
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
      <ConfirmDialog
        open={pendingReportExport !== null}
        title={`${pendingReportExport?.label ?? "帳票"}を出力しますか？`}
        message={pendingReportExport?.description ?? ""}
        confirmLabel="出力する"
        onCancel={() => setPendingReportExport(null)}
        onConfirm={() => {
          const exportAction = pendingReportExport;
          setPendingReportExport(null);
          if (exportAction) {
            void exportAction.action();
          }
        }}
      />
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
  childCareLeaveMinutes: number;
  nursingCareLeaveMinutes: number;
  childCareLeaveDays: number;
  nursingCareLeaveDays: number;
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
      childCareLeaveMinutes: 0,
      nursingCareLeaveMinutes: 0,
      childCareLeaveDays: 0,
      nursingCareLeaveDays: 0,
      lateDays: 0,
      earlyLeaveDays: 0,
      holidayDays: 0,
    };

    const worked = (row.workMinutes ?? 0) > 0;
    current.childCareLeaveMinutes += row.childCareLeaveMinutes ?? 0;
    current.nursingCareLeaveMinutes += row.nursingCareLeaveMinutes ?? 0;
    current.childCareLeaveDays += row.childCareLeaveDays ?? 0;
    current.nursingCareLeaveDays += row.nursingCareLeaveDays ?? 0;
    if (row.absenceFlag) {
      current.holidayDays += 1;
    }
    current.actualWorkMinutes += (row.reportWorkMinutes === undefined ? row.workMinutes : row.reportWorkMinutes) ?? 0;
    if (worked) {
      current.workDays += 1;
      current.totalWorkMinutes += getDailyTotalMinutes(row) ?? 0;

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

function buildMonthlyCalendarRows(targetMonth: string, rows: AttendanceDaily[], employmentType?: string | null): MonthlyCalendarRow[] {
  const [year, month] = targetMonth.split("-").map((value) => Number(value));
  if (!year || !month) {
    return [];
  }

  const rowByDate = new Map(rows.map((row) => [row.targetDate, row]));
  const defaults = reportPeriod(targetMonth, employmentType);
  const from = rows[0]?.periodStart ? new Date(`${rows[0].periodStart}T00:00:00`) : defaults.from;
  const to = rows[0]?.periodEnd ? new Date(`${rows[0].periodEnd}T00:00:00`) : defaults.to;
  const datesInMonth = Math.round((to.getTime() - from.getTime()) / 86400000) + 1;

  return Array.from({ length: datesInMonth }, (_, index) => {
    const date = new Date(from.getFullYear(), from.getMonth(), from.getDate() + index);
    const targetDate = formatDateKey(date);
    const weekday = date.getDay();

    return {
      targetDate,
      daily: rowByDate.get(targetDate),
      isSaturday: weekday === 6,
      isSunday: weekday === 0,
      holidayName: japaneseHolidayName(targetDate),
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

function attendanceTimeSource(row?: AttendanceDaily) {
  if (!row || (!row.clockInAt && !row.clockOutAt)) return "-";
  if (row.timeSource === "RULE") return "勤務ルール適用";
  if (row.isManuallyEdited || row.timeSource === "MANUAL") return "手動修正";
  if (row.scheduleInferredSide || row.timeSource === "SCHEDULE") return "予定補完";
  if (row.sourceSystem || row.timeSource === "IMPORTED") return `${row.sourceSystem || "外部"}取込`;
  return "打刻";
}

function formatDailyMinutes(row: AttendanceDaily | undefined, value?: number | null) {
  return row && value != null ? formatMinutes(value) : "-";
}

function formatCareLeave(row: AttendanceDaily | undefined, days = 0, minutes = 0) {
  if (!row) return "-";
  return [days > 0 ? `${days}日` : "", minutes > 0 || days <= 0 ? formatMinutes(minutes) : ""].filter(Boolean).join(" / ");
}

function getDailyTotalMinutes(row?: AttendanceDaily) {
  if (!row?.clockInAt || !row.clockOutAt) {
    return null;
  }

  const elapsed = new Date(row.clockOutAt).getTime() - new Date(row.clockInAt).getTime();
  return Number.isFinite(elapsed) && elapsed >= 0 ? Math.floor(elapsed / 60000) : null;
}

function getDailyOvertimeMinutes(row?: AttendanceDaily) {
  if (!row || row.workMinutes === null || row.workMinutes === undefined) {
    return null;
  }

  if (row.overtimeMinutesOverride != null) return row.overtimeMinutesOverride;
  if (row.overtimeMinutes !== undefined) return row.overtimeMinutes;
  return Math.max(0, row.workMinutes - 8 * 60);
}

function formatDateLabel(value: string) {
  const date = new Date(`${value}T00:00:00`);
  const weekdays = ["日", "月", "火", "水", "木", "金", "土"];
  return `${String(date.getDate()).padStart(2, "0")} ${weekdays[date.getDay()]}`;
}

function formatDateKey(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}
