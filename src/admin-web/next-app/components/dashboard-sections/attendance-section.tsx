import { useRef, useState } from "react";
import { ConfirmDialog } from "@/components/confirm-dialog";
import { AttendanceMonthClosePanel, AttendanceExportPanel } from "@/components/dashboard-sections/attendance/attendance-close-export-panels";
import { AttendanceDailyEditor } from "@/components/dashboard-sections/attendance/attendance-daily-editor";
import { recordBreakOvertime } from "@/components/dashboard-sections/attendance/record-break-overtime";
import type { BreakOvertimeConfirmation } from "@/types";
import { AttendanceFiltersPanel } from "@/components/dashboard-sections/attendance/attendance-filters-panel";
import { AttendanceMonthSelector } from "@/components/dashboard-sections/attendance/attendance-month-selector";
import { AttendanceAttentionPanel } from "@/components/dashboard-sections/attendance/attendance-attention-panel";
import { AttendanceSettingsPanels } from "@/components/dashboard-sections/attendance/attendance-settings-panels";
import { AttendanceTablesPanel } from "@/components/dashboard-sections/attendance/attendance-tables-panel";
import { AttendanceMonthReviewPanel, type AttendanceReviewRequest } from "@/components/dashboard-sections/attendance/attendance-month-review-panel";
import type { AttendanceSectionProps } from "@/components/dashboard-sections/attendance/attendance-section-types";
import { buildDailyUpdatePayload, isNextDay, normalizeBreaks } from "@/components/dashboard-sections/attendance/attendance-section-utils";
import { ApiRequestError } from "@/lib/api/core";
import type { FieldErrors } from "@/components/form-field-errors";
import {
  loadAttendanceDailyDetail,
  loadAttendanceDailyHistories,
  resetAttendanceDailyManualEdit,
  updateAttendanceDaily,
} from "@/lib/api";
import type { AttendanceDailyBreak, AttendanceDailyDetail, AttendanceDailyHistory } from "@/types";

export function AttendanceSection(props: AttendanceSectionProps & {onAttentionTotalChange?: (total: number) => void}) {
  const activePanel = props.data.activePanel || "attendance-filters";
  const showingAttention = activePanel === "attendance-attention";
  const [attentionRevision, setAttentionRevision] = useState(0);
  const [editingDaily, setEditingDaily] = useState<AttendanceDailyDetail | null>(null);
  const [originalDaily, setOriginalDaily] = useState<AttendanceDailyDetail | null>(null);
  const [editingBreaks, setEditingBreaks] = useState<AttendanceDailyBreak[]>([]);
  const [historyRows, setHistoryRows] = useState<AttendanceDailyHistory[]>([]);
  const [activeEditTab, setActiveEditTab] = useState<"detail" | "edit" | "history">("detail");
  const [editMessage, setEditMessage] = useState("");
  const [editError, setEditError] = useState("");
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [detailLoading, setDetailLoading] = useState(false);
  const [dailyBusy, setDailyBusy] = useState(false);
  const [lastDailyId, setLastDailyId] = useState<number | null>(null);
  const [pendingMonth, setPendingMonth] = useState<string | null>(null);
  const [reviewRequest, setReviewRequest] = useState<AttendanceReviewRequest | null>(null);
  const detailSequence = useRef(0);
  const mutationBusy = useRef(false);

  function changeMonth(value: string) {
    detailSequence.current++;
    setEditingDaily(null);
    setOriginalDaily(null);
    setLastDailyId(null);
    setDetailLoading(false);
    setEditError("");
    setEditMessage("");
    setPendingMonth(null);
    props.actions.onAttendanceFilterMonthChange(value);
  }

  function selectMonth(value: string) {
    if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(value) || value < "1000-01" || value === props.filters.attendanceFilterMonth || mutationBusy.current) return;
    if (editingDaily && originalDaily && Object.keys(buildDailyUpdatePayload(originalDaily, editingDaily, editingBreaks)).length > 0) {
      setPendingMonth(value);
      return;
    }
    changeMonth(value);
  }

  async function openDailyEditor(id?: number | null, expected?: {employeeId: number; targetDate: string}) {
    if (mutationBusy.current) return;
    if (typeof id !== "number") {
      setEditError("未登録の日次は先に打刻または届出反映で日次を作成してください。");
      return;
    }
    setEditError("");
    setEditMessage("");
    setFieldErrors({}); setEditingDaily(null); setOriginalDaily(null); setLastDailyId(id); setDetailLoading(true);
    const request = ++detailSequence.current;
    try {
      const [detail, histories] = await Promise.all([loadAttendanceDailyDetail(id), loadAttendanceDailyHistories(id)]);
      if (request !== detailSequence.current) return;
      if (detail.id !== id || (expected && (detail.employeeId !== expected.employeeId || detail.targetDate !== expected.targetDate))) {
        throw new Error("選択した職員・勤務日と取得した日次が一致しません。一覧から対象を選び直してください。");
      }
      setEditingDaily(detail); setOriginalDaily(detail);
      setEditingBreaks(normalizeBreaks(detail.breaks)); setHistoryRows(histories); setActiveEditTab("detail");
    } catch (error) {
      if (request === detailSequence.current) setEditError(error instanceof Error ? error.message : "日次詳細を読み込めませんでした。");
    } finally { if (request === detailSequence.current) setDetailLoading(false); }
  }

  async function saveDailyEditor() {
    if (!editingDaily || !originalDaily || mutationBusy.current) {
      return;
    }
    const payload = buildDailyUpdatePayload(originalDaily, editingDaily, editingBreaks);
    if (Object.keys(payload).length === 0) { setEditMessage("変更はありません。"); return; }
    mutationBusy.current = true; setDailyBusy(true); setFieldErrors({});
    try {
      const detail = await updateAttendanceDaily(editingDaily.id, payload);
      setAttentionRevision(value => value + 1);
      setEditingDaily(detail);
      setOriginalDaily(detail);
      setEditingBreaks(normalizeBreaks(detail.breaks));
      setEditMessage("日次勤怠を更新しました。");
      setEditError("");
      const refreshed = await Promise.allSettled([loadAttendanceDailyHistories(editingDaily.id), props.actions.onApplyAttendanceFilters()]);
      if (refreshed[0].status === "fulfilled") setHistoryRows(refreshed[0].value);
      if (refreshed.some(result=>result.status === "rejected")) setEditError("保存は完了しましたが再取得に失敗しました。再保存せず詳細を再取得してください。");
    } catch (error) {
      setEditError(error instanceof Error ? error.message : "日次勤怠の更新に失敗しました。");
      if (error instanceof ApiRequestError) setFieldErrors(error.fieldErrors);
    } finally { mutationBusy.current = false; setDailyBusy(false); }
  }

  async function confirmBreakOvertime(id: number, inputs: BreakOvertimeConfirmation) {
    if (mutationBusy.current || editingDaily?.id !== id) throw new Error("勤務日を選び直してください。");
    mutationBusy.current = true;
    setDailyBusy(true);
    setEditError("");
    setEditMessage("");
    try {
      const result = await recordBreakOvertime(id, inputs, props.actions.onApplyAttendanceFilters);
      setAttentionRevision(value => value + 1);
      setEditingDaily(result.detail);
      setOriginalDaily(result.detail);
      setEditingBreaks(normalizeBreaks(result.detail?.breaks));
      setHistoryRows(result.histories);
      setEditMessage(result.message);
      setEditError(result.refreshError);
    } finally {
      mutationBusy.current = false;
      setDailyBusy(false);
    }
  }

  async function resetManualEdit() {
    if (!editingDaily || mutationBusy.current) {
      return;
    }
    mutationBusy.current = true; setDailyBusy(true);
    try {
      const detail = await resetAttendanceDailyManualEdit(editingDaily.id);
      setAttentionRevision(value => value + 1);
      setEditingDaily(detail);
      setOriginalDaily(detail);
      setEditingBreaks(normalizeBreaks(detail.breaks));
      setEditMessage("手動補正を解除しました。");
      setEditError("");
      const refreshed = await Promise.allSettled([loadAttendanceDailyHistories(editingDaily.id), props.actions.onApplyAttendanceFilters()]);
      if (refreshed[0].status === "fulfilled") setHistoryRows(refreshed[0].value);
      else setHistoryRows([]);
      if (refreshed.some(result=>result.status === "rejected")) setEditError("手動補正の解除は完了しましたが再取得に失敗しました。再操作せず詳細を再取得してください。");
    } catch (error) {
      setEditError(error instanceof Error ? error.message : "手動補正の解除に失敗しました。");
    } finally { mutationBusy.current = false; setDailyBusy(false); }
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
      if (nextDay ?? isNextDay(current[field], current.targetDate)) base.setDate(base.getDate()+1);
      const date = `${base.getFullYear()}-${`${base.getMonth() + 1}`.padStart(2, "0")}-${`${base.getDate()}`.padStart(2, "0")}`;
      return { ...current, [field]: `${date}T${time}:00` };
    });
  }

  return (
    <section className="stack-section section-enter delay-3">
      {showingAttention
        ? <AttendanceAttentionPanel revision={`${attentionRevision}/${props.data.dashboard.reportsHub.checkedAt ?? ""}`} onOpenDaily={openDailyEditor} onRefresh={props.actions.onApplyAttendanceFilters} onTotalChange={props.onAttentionTotalChange} />
        : <AttendanceMonthSelector targetMonth={props.filters.attendanceFilterMonth} isLoading={props.data.attendanceIsLoading}
            disabled={dailyBusy} onMonthChange={selectMonth} onReload={props.actions.onApplyAttendanceFilters} />}
      {activePanel === "attendance-filters" ? (
        <AttendanceFiltersPanel dashboard={props.data.dashboard} filters={props.filters} actions={props.actions} isLoading={props.data.attendanceIsLoading} />
      ) : null}

      {activePanel === "attendance-close" ? (
        <><AttendanceMonthReviewPanel key={"review-" + props.filters.attendanceFilterMonth} targetMonth={props.filters.attendanceFilterMonth} formatters={props.formatters} onRefresh={props.actions.onApplyAttendanceFilters} onOpenDaily={openDailyEditor} reviewRequest={reviewRequest}/><AttendanceMonthClosePanel
          key={"close-" + props.filters.attendanceFilterMonth}
          dashboard={props.data.dashboard}
          attendanceCloseResult={props.data.attendanceCloseResult}
          attendanceIsLoading={props.data.attendanceIsLoading}
          attendanceMonthDataFresh={props.data.attendanceMonthDataFresh}
          attendanceLoadError={props.data.attendanceLoadError}
          targetMonth={props.filters.attendanceFilterMonth}
          formatters={props.formatters}
          onAttendanceMonthClose={props.actions.onAttendanceMonthClose}
          onReviewBlocker={code => setReviewRequest(current => ({targetMonth: props.filters.attendanceFilterMonth, code, revision: (current?.revision ?? 0) + 1}))}
        /></>
      ) : null}

      {activePanel === "attendance-export" ? (
        <AttendanceExportPanel
          key={"export-" + props.filters.attendanceFilterMonth}
          employeeCode={props.filters.attendanceFilterEmployeeCode}
          departmentName={props.filters.attendanceFilterDepartmentName}
          targetMonth={props.filters.attendanceFilterMonth}
          reportFrom={props.filters.attendanceEventFrom}
          reportTo={props.filters.attendanceEventTo}
          actions={props.actions}
        />
      ) : null}

      {!showingAttention && (props.data.attendanceIsLoading ? <p role="status">対象月の勤怠を読み込んでいます。</p> : <AttendanceTablesPanel
        key={"tables-" + props.filters.attendanceFilterMonth}
        activePanel={activePanel}
        data={props.data}
        filters={props.filters}
        actions={props.actions}
        formatters={props.formatters}
        onOpenDailyEditor={openDailyEditor}
      />)}

      <AttendanceSettingsPanels
        activePanel={activePanel}
        dashboard={props.data.dashboard}
        targetMonth={props.filters.attendanceFilterMonth}
      />

      {detailLoading ? <p role="status">日次詳細を読み込んでいます。</p> : null}
      {editingDaily ? (
        <AttendanceDailyEditor
          editingDaily={editingDaily}
          editingBreaks={editingBreaks}
          historyRows={historyRows}
          activeEditTab={activeEditTab}
          editError={editError}
          editMessage={editMessage}
          fieldErrors={fieldErrors}
          busy={dailyBusy}
          dashboard={props.data.dashboard}
          formatters={props.formatters}
          onClose={() => { detailSequence.current++; setEditingDaily(null); setOriginalDaily(null); }}
          onSave={saveDailyEditor}
          onResetManualEdit={resetManualEdit}
          onApplyBreakOvertime={confirmBreakOvertime}
          onActiveEditTabChange={setActiveEditTab}
          setEditingDaily={setEditingDaily}
          setEditingBreaks={setEditingBreaks}
          setEditingClock={setEditingClock}
        />
      ) : editError ? (
        <div className="banner" role="alert"><p>{editError}</p>{lastDailyId ? <button type="button" className="secondary" onClick={()=>void openDailyEditor(lastDailyId)}>日次詳細を再取得</button> : null}</div>
      ) : null}
      <ConfirmDialog open={pendingMonth !== null} title="対象月を変更しますか？"
        message={"入力途中の日次勤怠の変更は保存されません。対象月を" + (pendingMonth ?? "") + "に変更します。"}
        confirmLabel="保存せず月を変更" onCancel={() => setPendingMonth(null)} onConfirm={() => { if (pendingMonth) changeMonth(pendingMonth); }} />
    </section>
  );
}
