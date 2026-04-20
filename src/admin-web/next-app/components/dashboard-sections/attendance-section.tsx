import { useState } from "react";
import { AttendanceMonthClosePanel, AttendanceExportPanel } from "@/components/dashboard-sections/attendance/attendance-close-export-panels";
import { AttendanceDailyEditor } from "@/components/dashboard-sections/attendance/attendance-daily-editor";
import { AttendanceFiltersPanel } from "@/components/dashboard-sections/attendance/attendance-filters-panel";
import { AttendanceTablesPanel } from "@/components/dashboard-sections/attendance/attendance-tables-panel";
import type { AttendanceSectionProps } from "@/components/dashboard-sections/attendance/attendance-section-types";
import { isoToTime, isNextDay, normalizeBreaks } from "@/components/dashboard-sections/attendance/attendance-section-utils";
import {
  loadAttendanceDailyDetail,
  loadAttendanceDailyHistories,
  resetAttendanceDailyManualEdit,
  updateAttendanceDaily,
} from "@/lib/api";
import type { AttendanceDailyBreak, AttendanceDailyDetail, AttendanceDailyHistory } from "@/types";

export function AttendanceSection(props: AttendanceSectionProps) {
  const activePanel = props.data.activePanel || "attendance-filters";
  const [editingDaily, setEditingDaily] = useState<AttendanceDailyDetail | null>(null);
  const [editingBreaks, setEditingBreaks] = useState<AttendanceDailyBreak[]>([]);
  const [historyRows, setHistoryRows] = useState<AttendanceDailyHistory[]>([]);
  const [activeEditTab, setActiveEditTab] = useState<"edit" | "history">("edit");
  const [editMessage, setEditMessage] = useState("");
  const [editError, setEditError] = useState("");

  async function openDailyEditor(id?: number | null) {
    if (typeof id !== "number") {
      setEditError("未登録の日次は先に打刻または届出反映で日次を作成してください。");
      return;
    }
    setEditError("");
    setEditMessage("");
    const [detail, histories] = await Promise.all([loadAttendanceDailyDetail(id), loadAttendanceDailyHistories(id)]);
    setEditingDaily(detail);
    setEditingBreaks(normalizeBreaks(detail.breaks));
    setHistoryRows(histories);
    setActiveEditTab("edit");
  }

  async function saveDailyEditor() {
    if (!editingDaily) {
      return;
    }
    try {
      const detail = await updateAttendanceDaily(editingDaily.id, {
        workTypeId: editingDaily.workTypeId ?? null,
        clockInTime: isoToTime(editingDaily.clockInAt),
        clockInNextDay: isNextDay(editingDaily.clockInAt, editingDaily.targetDate),
        clockOutTime: isoToTime(editingDaily.clockOutAt),
        clockOutNextDay: isNextDay(editingDaily.clockOutAt, editingDaily.targetDate),
        breaks: editingBreaks,
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
      await props.actions.onApplyAttendanceFilters();
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
      await props.actions.onApplyAttendanceFilters();
    } catch (error) {
      setEditError(error instanceof Error ? error.message : "手動補正の解除に失敗しました。");
    }
  }

  function setEditingClock(field: "clockInAt" | "clockOutAt", time: string, nextDay = false) {
    setEditingDaily((current) => {
      if (!current) {
        return current;
      }
      if (!time) {
        return { ...current, [field]: null };
      }
      const base = new Date(`${current.targetDate}T00:00:00`);
      if (nextDay) {
        base.setDate(base.getDate() + 1);
      }
      const date = `${base.getFullYear()}-${`${base.getMonth() + 1}`.padStart(2, "0")}-${`${base.getDate()}`.padStart(2, "0")}`;
      return { ...current, [field]: `${date}T${time}:00` };
    });
  }

  return (
    <section className="stack-section section-enter delay-3">
      {activePanel === "attendance-filters" ? (
        <AttendanceFiltersPanel dashboard={props.data.dashboard} filters={props.filters} actions={props.actions} />
      ) : null}

      {activePanel === "attendance-close" ? (
        <AttendanceMonthClosePanel
          dashboard={props.data.dashboard}
          attendanceCloseResult={props.data.attendanceCloseResult}
          formatters={props.formatters}
          onAttendanceMonthClose={props.actions.onAttendanceMonthClose}
        />
      ) : null}

      {activePanel === "attendance-export" ? (
        <AttendanceExportPanel
          reportMonth={props.data.reportMonth}
          reportFrom={props.data.reportFrom}
          reportTo={props.data.reportTo}
          actions={props.actions}
        />
      ) : null}

      <AttendanceTablesPanel
        activePanel={activePanel}
        data={props.data}
        filters={props.filters}
        actions={props.actions}
        formatters={props.formatters}
        onOpenDailyEditor={openDailyEditor}
      />

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
    </section>
  );
}
