import type { Dispatch, SetStateAction } from "react";
import { DataTable } from "@/components/data-table";
import { isoToTime, isNextDay } from "@/components/dashboard-sections/attendance/attendance-section-utils";
import type { AttendanceSectionProps } from "@/components/dashboard-sections/attendance/attendance-section-types";
import type { AttendanceDailyBreak, AttendanceDailyDetail, AttendanceDailyHistory } from "@/types";

type AttendanceDailyEditorProps = {
  editingDaily: AttendanceDailyDetail;
  editingBreaks: AttendanceDailyBreak[];
  historyRows: AttendanceDailyHistory[];
  activeEditTab: "edit" | "history";
  editError: string;
  editMessage: string;
  dashboard: AttendanceSectionProps["data"]["dashboard"];
  formatters: Pick<AttendanceSectionProps["formatters"], "formatDateOnly" | "formatDateTime" | "formatTimeOnly">;
  onClose: () => void;
  onSave: () => Promise<void>;
  onResetManualEdit: () => Promise<void>;
  onActiveEditTabChange: (value: "edit" | "history") => void;
  setEditingDaily: Dispatch<SetStateAction<AttendanceDailyDetail | null>>;
  setEditingBreaks: Dispatch<SetStateAction<AttendanceDailyBreak[]>>;
  setEditingClock: (field: "clockInAt" | "clockOutAt", time: string, nextDay?: boolean) => void;
};

export function AttendanceDailyEditor({
  editingDaily,
  editingBreaks,
  historyRows,
  activeEditTab,
  editError,
  editMessage,
  dashboard,
  formatters,
  onClose,
  onSave,
  onResetManualEdit,
  onActiveEditTabChange,
  setEditingDaily,
  setEditingBreaks,
  setEditingClock,
}: AttendanceDailyEditorProps) {
  return (
    <section className="panel action-panel anchor-panel daily-edit-panel">
      <div className="panel-header">
        <div>
          <h3>
            日次勤怠修正 / {editingDaily.employeeCode} {editingDaily.employeeName}
          </h3>
          <p className="compact-empty">
            {formatters.formatDateOnly(editingDaily.targetDate)} / 原本出勤 {formatters.formatTimeOnly(editingDaily.rawClockInAt)} / 原本退勤{" "}
            {formatters.formatTimeOnly(editingDaily.rawClockOutAt)}
          </p>
        </div>
        <button type="button" className="secondary" onClick={onClose}>
          閉じる
        </button>
      </div>

      <div className="button-row">
        <button type="button" className={activeEditTab === "edit" ? "" : "secondary"} onClick={() => onActiveEditTabChange("edit")}>
          編集
        </button>
        <button type="button" className={activeEditTab === "history" ? "" : "secondary"} onClick={() => onActiveEditTabChange("history")}>
          履歴
        </button>
      </div>

      {activeEditTab === "edit" ? (
        <div className="stack-form">
          <div className="form-grid">
            <label>
              勤務区分
              <select
                value={editingDaily.workTypeId ?? ""}
                onChange={(event) =>
                  setEditingDaily((current) => current && { ...current, workTypeId: event.target.value ? Number(event.target.value) : null })
                }
              >
                <option value="">未指定</option>
                {dashboard.systemMasters.workTypes.map((workType) => (
                  <option key={workType.id} value={workType.id}>
                    {workType.name}
                  </option>
                ))}
              </select>
            </label>
            <label>
              出勤
              <input
                type="time"
                value={isoToTime(editingDaily.clockInAt)}
                onChange={(event) => setEditingClock("clockInAt", event.target.value, isNextDay(editingDaily.clockInAt, editingDaily.targetDate))}
              />
            </label>
            <label>
              出勤翌日
              <input
                type="checkbox"
                checked={isNextDay(editingDaily.clockInAt, editingDaily.targetDate)}
                onChange={(event) => setEditingClock("clockInAt", isoToTime(editingDaily.clockInAt), event.target.checked)}
              />
            </label>
            <label>
              退勤
              <input
                type="time"
                value={isoToTime(editingDaily.clockOutAt)}
                onChange={(event) => setEditingClock("clockOutAt", event.target.value, isNextDay(editingDaily.clockOutAt, editingDaily.targetDate))}
              />
            </label>
            <label>
              退勤翌日
              <input
                type="checkbox"
                checked={isNextDay(editingDaily.clockOutAt, editingDaily.targetDate)}
                onChange={(event) => setEditingClock("clockOutAt", isoToTime(editingDaily.clockOutAt), event.target.checked)}
              />
            </label>
            <label>
              申請承認
              <select
                value={editingDaily.approvalStatus ?? "PENDING"}
                onChange={(event) => setEditingDaily((current) => current && { ...current, approvalStatus: event.target.value })}
              >
                <option value="PENDING">承認待ち</option>
                <option value="APPROVED">承認済み</option>
                <option value="RETURNED">差戻し</option>
              </select>
            </label>
          </div>

          <div className="stack-form">
            {editingBreaks.map((breakRow, index) => (
              <div className="form-grid" key={index}>
                <label>
                  休憩{index + 1} 開始
                  <input
                    type="time"
                    value={breakRow.startTime ?? ""}
                    onChange={(event) =>
                      setEditingBreaks((items) =>
                        items.map((item, itemIndex) => (itemIndex === index ? { ...item, startTime: event.target.value } : item)),
                      )
                    }
                  />
                </label>
                <label>
                  開始翌日
                  <input
                    type="checkbox"
                    checked={Boolean(breakRow.startNextDay)}
                    onChange={(event) =>
                      setEditingBreaks((items) =>
                        items.map((item, itemIndex) => (itemIndex === index ? { ...item, startNextDay: event.target.checked } : item)),
                      )
                    }
                  />
                </label>
                <label>
                  休憩{index + 1} 終了
                  <input
                    type="time"
                    value={breakRow.endTime ?? ""}
                    onChange={(event) =>
                      setEditingBreaks((items) =>
                        items.map((item, itemIndex) => (itemIndex === index ? { ...item, endTime: event.target.value } : item)),
                      )
                    }
                  />
                </label>
                <label>
                  終了翌日
                  <input
                    type="checkbox"
                    checked={Boolean(breakRow.endNextDay)}
                    onChange={(event) =>
                      setEditingBreaks((items) =>
                        items.map((item, itemIndex) => (itemIndex === index ? { ...item, endNextDay: event.target.checked } : item)),
                      )
                    }
                  />
                </label>
              </div>
            ))}
            <div className="button-row">
              <button type="button" className="secondary" onClick={() => setEditingBreaks((items) => [...items, { startTime: "", endTime: "" }])}>
                休憩を追加
              </button>
              <button type="button" className="secondary" onClick={() => setEditingBreaks((items) => items.slice(0, -1))} disabled={editingBreaks.length === 0}>
                休憩を削除
              </button>
            </div>
          </div>

          <label>
            備考
            <textarea
              rows={3}
              value={editingDaily.remark ?? ""}
              onChange={(event) => setEditingDaily((current) => current && { ...current, remark: event.target.value })}
            />
          </label>
          <label>
            所属長コメント
            <textarea
              rows={3}
              value={editingDaily.supervisorComment ?? ""}
              onChange={(event) => setEditingDaily((current) => current && { ...current, supervisorComment: event.target.value })}
            />
          </label>
          <label>
            承認コメント
            <textarea
              rows={3}
              value={editingDaily.approvalComment ?? ""}
              onChange={(event) => setEditingDaily((current) => current && { ...current, approvalComment: event.target.value })}
            />
          </label>
          <div className="button-row">
            <button type="button" onClick={() => void onSave()}>
              保存
            </button>
            <button type="button" className="secondary" onClick={() => void onResetManualEdit()} disabled={!editingDaily.isManuallyEdited}>
              手動補正を解除
            </button>
          </div>
        </div>
      ) : (
        <DataTable
          title="日次勤怠履歴"
          rows={historyRows}
          emptyMessage="履歴はありません"
          columns={[
            { key: "actedAt", header: "操作日時", render: (row) => formatters.formatDateTime(row.actedAt) },
            { key: "fieldLabel", header: "項目", render: (row) => row.fieldLabel },
            { key: "oldValue", header: "変更前", render: (row) => row.oldValue ?? "-" },
            { key: "newValue", header: "変更後", render: (row) => row.newValue ?? "-" },
            { key: "actorRole", header: "操作者権限", render: (row) => row.actorRole ?? "-" },
            { key: "actorEmployeeCode", header: "操作者社員番号", render: (row) => row.actorEmployeeCode ?? "-" },
            { key: "actorName", header: "操作者名", render: (row) => row.actorName ?? "-" },
          ]}
        />
      )}
      {editError ? <p className="banner">{editError}</p> : null}
      {editMessage ? <p className="feedback">{editMessage}</p> : null}
    </section>
  );
}
