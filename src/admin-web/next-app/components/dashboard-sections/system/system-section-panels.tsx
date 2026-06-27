import { useState } from "react";
import { DataTable } from "@/components/data-table";
import { formatDurationMinutes } from "@/lib/api/formatters";
import {
  formatDisplayCode,
  formatEnvironmentLabel,
  formatEnvironmentMessage,
  formatEnvironmentPurpose,
} from "@/lib/display-labels";
import type {
  AttendanceAlertSetting,
  AttendanceDailyFieldSetting,
  AttendanceErrorRuleSetting,
  DashboardData,
  DepartmentSetting,
  EmploymentTypeSetting,
  LeaveTypeSetting,
  LocationSetting,
  PaidLeaveSetting,
  RequestTypeSetting,
  WorkTypeSetting,
} from "@/types";

export type SystemFormTarget =
  | "department"
  | "location"
  | "employment"
  | "workType"
  | "requestType"
  | "leaveType"
  | "paidLeaveSetting"
  | "attendanceAlert"
  | "attendanceErrorRule"
  | "dailyField";

export type SystemSectionProps = {
  data: {
    dashboard: DashboardData;
    systemResult: string;
    activePanel: string;
  };
  actions: {
    onSystemForm: (target: SystemFormTarget, formData: FormData) => Promise<void>;
  };
  formatters: {
    formatEmploymentType: (value?: string | null) => string;
  };
};

export function SystemSectionPanels(props: SystemSectionProps) {
  const activePanel = props.data.activePanel || "system-departments";
  const [editingDepartment, setEditingDepartment] = useState<DepartmentSetting | null>(null);
  const [editingLocation, setEditingLocation] = useState<LocationSetting | null>(null);
  const [editingEmploymentType, setEditingEmploymentType] = useState<EmploymentTypeSetting | null>(null);
  const [editingWorkType, setEditingWorkType] = useState<WorkTypeSetting | null>(null);
  const [editingRequestType, setEditingRequestType] = useState<RequestTypeSetting | null>(null);
  const [editingLeaveType, setEditingLeaveType] = useState<LeaveTypeSetting | null>(null);
  const [editingPaidLeave, setEditingPaidLeave] = useState<PaidLeaveSetting | null>(null);
  const [editingAttendanceErrorRule, setEditingAttendanceErrorRule] = useState<AttendanceErrorRuleSetting | null>(null);
  const [editingAttendanceAlert, setEditingAttendanceAlert] = useState<AttendanceAlertSetting | null>(null);
  const [editingDailyField, setEditingDailyField] = useState<AttendanceDailyFieldSetting | null>(null);

  return (
    <section className="stack-section section-enter delay-3">
      {activePanel === "system-departments" ? (
        <section id="system-departments" className="split anchor-panel">
          <DataTable
            title="部門"
            rows={props.data.dashboard.systemMasters.departments}
            emptyMessage="部門はまだありません"
            columns={[
              { key: "name", header: "部門名", render: (row) => row.name },
              { key: "sortOrder", header: "表示順", render: (row) => row.sortOrder },
              { key: "isActive", header: "状態", render: (row) => (row.isActive ? "有効" : "無効") },
              {
                key: "actions",
                header: "操作",
                render: (row) => (
                  <MasterRowActions
                    target="department"
                    label={row.name}
                    disabled={!row.isActive}
                    hiddenFields={{ id: row.id, name: row.name, sortOrder: row.sortOrder }}
                    onEdit={() => setEditingDepartment(row)}
                    onSystemForm={props.actions.onSystemForm}
                    afterSubmit={() => setEditingDepartment(null)}
                  />
                ),
              },
            ]}
          />
          <section className="panel action-panel">
            <div className="panel-header">
              <div>
                <h3>{editingDepartment ? "部門を編集" : "部門を追加"}</h3>
              </div>
            </div>
            <form
              key={editingDepartment?.id ?? "new-department"}
              className="stack-form"
              action={async (formData) => {
                await props.actions.onSystemForm("department", formData);
                setEditingDepartment(null);
              }}
            >
              {editingDepartment ? <input type="hidden" name="id" value={editingDepartment.id} /> : null}
              <label>
                部門名
                <input name="name" defaultValue={editingDepartment?.name ?? ""} />
              </label>
              <label>
                表示順
                <input name="sortOrder" type="number" defaultValue={editingDepartment?.sortOrder ?? ""} />
              </label>
              <label className="checkbox-row">
                <input name="isActive" type="checkbox" defaultChecked={editingDepartment?.isActive ?? true} />
                有効
              </label>
              <FormButtonRow editing={Boolean(editingDepartment)} saveLabel="部門を保存" onCancel={() => setEditingDepartment(null)} />
            </form>
          </section>
        </section>
      ) : null}

      {activePanel === "system-locations" ? (
        <section id="system-locations" className="split anchor-panel">
          <DataTable
            title="拠点"
            rows={props.data.dashboard.systemMasters.locations}
            emptyMessage="拠点はまだありません"
            columns={[
              { key: "name", header: "拠点名", render: (row) => row.name },
              { key: "sortOrder", header: "表示順", render: (row) => row.sortOrder },
              { key: "isActive", header: "状態", render: (row) => (row.isActive ? "有効" : "無効") },
              {
                key: "actions",
                header: "操作",
                render: (row) => (
                  <MasterRowActions
                    target="location"
                    label={row.name}
                    disabled={!row.isActive}
                    hiddenFields={{ id: row.id, name: row.name, sortOrder: row.sortOrder }}
                    onEdit={() => setEditingLocation(row)}
                    onSystemForm={props.actions.onSystemForm}
                    afterSubmit={() => setEditingLocation(null)}
                  />
                ),
              },
            ]}
          />
          <section className="panel action-panel">
            <div className="panel-header">
              <div>
                <h3>{editingLocation ? "拠点を編集" : "拠点を追加"}</h3>
              </div>
            </div>
            <form
              key={editingLocation?.id ?? "new-location"}
              className="stack-form"
              action={async (formData) => {
                await props.actions.onSystemForm("location", formData);
                setEditingLocation(null);
              }}
            >
              {editingLocation ? <input type="hidden" name="id" value={editingLocation.id} /> : null}
              <label>
                拠点名
                <input name="name" defaultValue={editingLocation?.name ?? ""} />
              </label>
              <label>
                表示順
                <input name="sortOrder" type="number" defaultValue={editingLocation?.sortOrder ?? ""} />
              </label>
              <label className="checkbox-row">
                <input name="isActive" type="checkbox" defaultChecked={editingLocation?.isActive ?? true} />
                有効
              </label>
              <FormButtonRow editing={Boolean(editingLocation)} saveLabel="拠点を保存" onCancel={() => setEditingLocation(null)} />
            </form>
          </section>
        </section>
      ) : null}

      {activePanel === "system-employment" ? (
        <section id="system-employment" className="split anchor-panel">
          <DataTable
            title="雇用形態"
            rows={props.data.dashboard.systemMasters.employmentTypes}
            emptyMessage="雇用形態はまだありません"
            columns={[
              { key: "code", header: "雇用区分", render: (row) => props.formatters.formatEmploymentType(row.code) },
              { key: "label", header: "名称", render: (row) => row.label },
              { key: "standardDayMinutes", header: "所定1日", render: (row) => formatDurationMinutes(row.standardDayMinutes) },
              { key: "sortOrder", header: "表示順", render: (row) => row.sortOrder },
              { key: "isActive", header: "状態", render: (row) => (row.isActive ? "有効" : "無効") },
              {
                key: "actions",
                header: "操作",
                render: (row) => (
                  <MasterRowActions
                    target="employment"
                    label={row.label}
                    disabled={!row.isActive}
                    hiddenFields={{
                      code: row.code,
                      label: row.label,
                      standardDayMinutes: row.standardDayMinutes ?? "",
                      sortOrder: row.sortOrder,
                    }}
                    onEdit={() => setEditingEmploymentType(row)}
                    onSystemForm={props.actions.onSystemForm}
                    afterSubmit={() => setEditingEmploymentType(null)}
                  />
                ),
              },
            ]}
          />
          <section className="panel action-panel">
            <div className="panel-header">
              <div>
                <h3>{editingEmploymentType ? "雇用形態を編集" : "雇用形態を追加"}</h3>
              </div>
            </div>
            <form
              key={editingEmploymentType?.code ?? "new-employment-type"}
              className="stack-form"
              action={async (formData) => {
                await props.actions.onSystemForm("employment", formData);
                setEditingEmploymentType(null);
              }}
            >
              {editingEmploymentType ? <input type="hidden" name="code" value={editingEmploymentType.code} /> : null}
              <label>
                雇用区分
                <select name="code" defaultValue={editingEmploymentType?.code ?? "FULL_TIME"} disabled={Boolean(editingEmploymentType)}>
                  <option value="FULL_TIME">常勤</option>
                  <option value="PART_TIME">非常勤</option>
                  <option value="CONTRACT">契約</option>
                  <option value="TEMPORARY">臨時</option>
                </select>
              </label>
              <label>
                名称
                <input name="label" defaultValue={editingEmploymentType?.label ?? "常勤"} />
              </label>
              <label>
                表示順
                <input name="sortOrder" type="number" defaultValue={editingEmploymentType?.sortOrder ?? ""} />
              </label>
              <label>
                所定1日分
                <input name="standardDayMinutes" type="number" placeholder="例: 480" defaultValue={editingEmploymentType?.standardDayMinutes ?? ""} />
              </label>
              <label className="checkbox-row">
                <input name="isActive" type="checkbox" defaultChecked={editingEmploymentType?.isActive ?? true} />
                有効
              </label>
              <FormButtonRow editing={Boolean(editingEmploymentType)} saveLabel="雇用形態を保存" onCancel={() => setEditingEmploymentType(null)} />
            </form>
          </section>
        </section>
      ) : null}

      {activePanel === "system-work-types" ? (
        <section id="system-work-types" className="split anchor-panel">
          <DataTable
            title="勤務区分"
            rows={props.data.dashboard.systemMasters.workTypes}
            emptyMessage="勤務区分はまだありません"
            columns={[
              { key: "name", header: "名称", render: (row) => row.name },
              { key: "startTime", header: "開始時刻", render: (row) => formatClockTime(row.startTime) },
              { key: "endTime", header: "終了時刻", render: (row) => formatClockTime(row.endTime) },
              { key: "defaultBreakMinutes", header: "既定休憩", render: (row) => formatDurationMinutes(row.defaultBreakMinutes ?? 0) },
              { key: "standardDayMinutes", header: "時間", render: (row) => formatDurationMinutes(row.standardDayMinutes) },
              { key: "sortOrder", header: "表示順", render: (row) => row.sortOrder },
              { key: "isActive", header: "状態", render: (row) => (row.isActive ? "有効" : "無効") },
              {
                key: "actions",
                header: "操作",
                render: (row) => (
                  <MasterRowActions
                    target="workType"
                    label={row.name}
                    disabled={!row.isActive}
                    hiddenFields={{
                      id: row.id,
                      name: row.name,
                      startTime: normalizeClockTime(row.startTime),
                      endTime: normalizeClockTime(row.endTime),
                      defaultBreakMinutes: row.defaultBreakMinutes ?? "",
                      standardDayMinutes: row.standardDayMinutes ?? "",
                      sortOrder: row.sortOrder,
                    }}
                    onEdit={() => setEditingWorkType(row)}
                    onSystemForm={props.actions.onSystemForm}
                    afterSubmit={() => setEditingWorkType(null)}
                  />
                ),
              },
            ]}
          />
          <section className="panel action-panel">
            <div className="panel-header">
              <div>
                <h3>{editingWorkType ? "勤務区分を編集" : "勤務区分を追加"}</h3>
              </div>
            </div>
            <form
              key={editingWorkType?.id ?? "new-work-type"}
              className="stack-form"
              action={async (formData) => {
                await props.actions.onSystemForm("workType", formData);
                setEditingWorkType(null);
              }}
            >
              {editingWorkType ? <input type="hidden" name="id" value={editingWorkType.id} /> : null}
              <label>
                名称
                <input name="name" defaultValue={editingWorkType?.name ?? "通常勤務"} />
              </label>
              <label>
                開始時刻
                <input name="startTime" type="time" defaultValue={normalizeClockTime(editingWorkType?.startTime)} />
              </label>
              <label>
                終了時刻
                <input name="endTime" type="time" defaultValue={normalizeClockTime(editingWorkType?.endTime)} />
              </label>
              <label>
                既定休憩
                <input name="defaultBreakMinutes" type="number" defaultValue={editingWorkType?.defaultBreakMinutes ?? "60"} />
              </label>
              <label>
                表示順
                <input name="sortOrder" type="number" defaultValue={editingWorkType?.sortOrder ?? ""} />
              </label>
              <label className="checkbox-row">
                <input name="isActive" type="checkbox" defaultChecked={editingWorkType?.isActive ?? true} />
                有効
              </label>
              <FormButtonRow editing={Boolean(editingWorkType)} saveLabel="勤務区分を保存" onCancel={() => setEditingWorkType(null)} />
            </form>
          </section>
        </section>
      ) : null}

      {activePanel === "system-request-types" ? (
        <section id="system-request-types" className="split anchor-panel">
          <DataTable
            title="申請区分"
            rows={props.data.dashboard.systemMasters.requestTypes}
            emptyMessage="申請区分はまだありません"
            columns={[
              { key: "code", header: "管理用名", render: (row) => formatDisplayCode(row.code) },
              { key: "name", header: "名称", render: (row) => row.name },
              { key: "sortOrder", header: "表示順", render: (row) => row.sortOrder },
              { key: "isActive", header: "状態", render: (row) => (row.isActive ? "有効" : "無効") },
              {
                key: "actions",
                header: "操作",
                render: (row) => (
                  <MasterRowActions
                    target="requestType"
                    label={row.name}
                    disabled={!row.isActive}
                    hiddenFields={{ code: row.code, name: row.name, sortOrder: row.sortOrder }}
                    onEdit={() => setEditingRequestType(row)}
                    onSystemForm={props.actions.onSystemForm}
                    afterSubmit={() => setEditingRequestType(null)}
                  />
                ),
              },
            ]}
          />
          <section className="panel action-panel">
            <div className="panel-header">
              <div>
                <h3>{editingRequestType ? "申請区分を編集" : "申請区分を追加"}</h3>
              </div>
            </div>
            <form
              key={editingRequestType?.code ?? "new-request-type"}
              className="stack-form"
              action={async (formData) => {
                await props.actions.onSystemForm("requestType", formData);
                setEditingRequestType(null);
              }}
            >
              {editingRequestType ? <input type="hidden" name="code" value={editingRequestType.code} /> : null}
              <label>
                管理用名
                <select name="code" defaultValue={editingRequestType?.code ?? "PAID"} disabled={Boolean(editingRequestType)}>
                  <option value="PAID">有給</option>
                  <option value="SPECIAL">特別休暇</option>
                  <option value="ABSENCE">欠勤</option>
                </select>
              </label>
              <label>
                名称
                <input name="name" defaultValue={editingRequestType?.name ?? "有給申請"} />
              </label>
              <label>
                表示順
                <input name="sortOrder" type="number" defaultValue={editingRequestType?.sortOrder ?? ""} />
              </label>
              <label className="checkbox-row">
                <input name="isActive" type="checkbox" defaultChecked={editingRequestType?.isActive ?? true} />
                有効
              </label>
              <FormButtonRow editing={Boolean(editingRequestType)} saveLabel="申請区分を保存" onCancel={() => setEditingRequestType(null)} />
            </form>
          </section>
        </section>
      ) : null}

      {activePanel === "system-leave-types" ? (
        <section id="system-leave-types" className="split anchor-panel">
          <DataTable
            title="休暇区分"
            rows={props.data.dashboard.systemMasters.leaveTypes}
            emptyMessage="休暇区分はまだありません"
            columns={[
              { key: "code", header: "管理用名", render: (row) => formatDisplayCode(row.code) },
              { key: "name", header: "名称", render: (row) => row.name },
              { key: "requiresBalance", header: "残数管理", render: (row) => (row.requiresBalance ? "あり" : "なし") },
              { key: "allowsHalfDay", header: "半日", render: (row) => (row.allowsHalfDay ? "可" : "不可") },
              { key: "sortOrder", header: "表示順", render: (row) => row.sortOrder },
              { key: "isActive", header: "状態", render: (row) => (row.isActive ? "有効" : "無効") },
              {
                key: "actions",
                header: "操作",
                render: (row) => (
                  <MasterRowActions
                    target="leaveType"
                    label={row.name}
                    disabled={!row.isActive}
                    hiddenFields={{
                      code: row.code,
                      name: row.name,
                      requiresBalance: row.requiresBalance ? "on" : "",
                      allowsHalfDay: row.allowsHalfDay ? "on" : "",
                      sortOrder: row.sortOrder,
                    }}
                    onEdit={() => setEditingLeaveType(row)}
                    onSystemForm={props.actions.onSystemForm}
                    afterSubmit={() => setEditingLeaveType(null)}
                  />
                ),
              },
            ]}
          />
          <section className="panel action-panel">
            <div className="panel-header">
              <div>
                <h3>{editingLeaveType ? "休暇区分を編集" : "休暇区分を追加"}</h3>
              </div>
            </div>
            <form
              key={editingLeaveType?.code ?? "new-leave-type"}
              className="stack-form"
              action={async (formData) => {
                await props.actions.onSystemForm("leaveType", formData);
                setEditingLeaveType(null);
              }}
            >
              {editingLeaveType ? <input type="hidden" name="code" value={editingLeaveType.code} /> : null}
              <label>
                管理用名
                <select name="code" defaultValue={editingLeaveType?.code ?? "SPECIAL"} disabled={Boolean(editingLeaveType)}>
                  <option value="SPECIAL">特別休暇</option>
                  <option value="PAID">有給</option>
                  <option value="ABSENCE">欠勤</option>
                </select>
              </label>
              <label>
                名称
                <input name="name" defaultValue={editingLeaveType?.name ?? "特別休暇"} />
              </label>
              <label>
                表示順
                <input name="sortOrder" type="number" defaultValue={editingLeaveType?.sortOrder ?? ""} />
              </label>
              <label className="checkbox-row">
                <input name="requiresBalance" type="checkbox" defaultChecked={editingLeaveType?.requiresBalance ?? false} />
                残数管理あり
              </label>
              <label className="checkbox-row">
                <input name="allowsHalfDay" type="checkbox" defaultChecked={editingLeaveType?.allowsHalfDay ?? true} />
                半日申請可
              </label>
              <label className="checkbox-row">
                <input name="isActive" type="checkbox" defaultChecked={editingLeaveType?.isActive ?? true} />
                有効
              </label>
              <FormButtonRow editing={Boolean(editingLeaveType)} saveLabel="休暇区分を保存" onCancel={() => setEditingLeaveType(null)} />
            </form>
          </section>
        </section>
      ) : null}

      {activePanel === "system-paid-leave" ? (
        <section id="system-paid-leave" className="split anchor-panel">
          <DataTable
            title="有給付与設定"
            rows={props.data.dashboard.systemMasters.paidLeaveSettings}
            emptyMessage="付与設定はまだありません"
            columns={[
              { key: "settingName", header: "設定名", render: (row) => row.settingName },
              { key: "annualGrantDays", header: "付与日数", render: (row) => `${row.annualGrantDays}日` },
              { key: "carryForwardMonths", header: "繰越月数", render: (row) => `${row.carryForwardMonths}か月` },
              { key: "isActive", header: "状態", render: (row) => (row.isActive ? "有効" : "無効") },
              {
                key: "actions",
                header: "操作",
                render: (row) => (
                  <MasterRowActions
                    target="paidLeaveSetting"
                    label={row.settingName}
                    disabled={!row.isActive}
                    hiddenFields={{
                      id: row.id,
                      settingName: row.settingName,
                      annualGrantDays: row.annualGrantDays,
                      carryForwardMonths: row.carryForwardMonths,
                      standardDayMinutes: row.standardDayMinutes ?? "",
                      note: row.note ?? "",
                    }}
                    onEdit={() => setEditingPaidLeave(row)}
                    onSystemForm={props.actions.onSystemForm}
                    afterSubmit={() => setEditingPaidLeave(null)}
                  />
                ),
              },
            ]}
          />
          <section className="panel action-panel">
            <div className="panel-header">
              <div>
                <h3>{editingPaidLeave ? "有給付与設定を編集" : "有給付与設定を追加"}</h3>
              </div>
            </div>
            <form
              key={editingPaidLeave?.id ?? "new-paid-leave"}
              className="stack-form"
              action={async (formData) => {
                await props.actions.onSystemForm("paidLeaveSetting", formData);
                setEditingPaidLeave(null);
              }}
            >
              {editingPaidLeave ? <input type="hidden" name="id" value={editingPaidLeave.id} /> : null}
              <label>
                設定名
                <input name="settingName" defaultValue={editingPaidLeave?.settingName ?? "通常付与"} />
              </label>
              <label>
                年間付与日数
                <input name="annualGrantDays" type="number" defaultValue={editingPaidLeave?.annualGrantDays ?? "10"} />
              </label>
              <label>
                繰越月数
                <input name="carryForwardMonths" type="number" defaultValue={editingPaidLeave?.carryForwardMonths ?? "24"} />
              </label>
              <label>
                備考
                <input name="note" defaultValue={editingPaidLeave?.note ?? ""} />
              </label>
              <label className="checkbox-row">
                <input name="isActive" type="checkbox" defaultChecked={editingPaidLeave?.isActive ?? true} />
                有効
              </label>
              <FormButtonRow editing={Boolean(editingPaidLeave)} saveLabel="有給付与設定を保存" onCancel={() => setEditingPaidLeave(null)} />
            </form>
          </section>
        </section>
      ) : null}

      {activePanel === "system-attendance-alerts" ? (
        <section id="system-attendance-alerts" className="stack-section anchor-panel">
          <section className="split">
            <DataTable
              title="勤怠エラールール"
              rows={props.data.dashboard.systemMasters.attendanceErrorRules}
              emptyMessage="勤怠エラールールはまだありません"
              columns={[
                { key: "code", header: "管理用名", render: (row) => formatDisplayCode(row.code) },
                { key: "name", header: "名称", render: (row) => row.name },
                { key: "minWorkMinutes", header: "勤務下限", render: (row) => row.minWorkMinutes ?? "-" },
                { key: "maxWorkMinutes", header: "勤務上限", render: (row) => row.maxWorkMinutes ?? "-" },
                { key: "requiredBreakMinutes", header: "必要休憩", render: (row) => row.requiredBreakMinutes ?? "-" },
                { key: "maxBreakMinutes", header: "休憩上限", render: (row) => row.maxBreakMinutes ?? "-" },
                { key: "enabled", header: "状態", render: (row) => (row.enabled ? "有効" : "無効") },
                {
                  key: "actions",
                  header: "操作",
                  render: (row) => (
                    <MasterRowActions
                      target="attendanceErrorRule"
                      label={row.name}
                      disabled={!row.enabled}
                      hiddenFields={{
                        code: row.code,
                        name: row.name,
                        minWorkMinutes: row.minWorkMinutes ?? "",
                        maxWorkMinutes: row.maxWorkMinutes ?? "",
                        requiredBreakMinutes: row.requiredBreakMinutes ?? "",
                        maxBreakMinutes: row.maxBreakMinutes ?? "",
                        sortOrder: row.sortOrder,
                        note: row.note ?? "",
                      }}
                      onEdit={() => setEditingAttendanceErrorRule(row)}
                      onSystemForm={props.actions.onSystemForm}
                      afterSubmit={() => setEditingAttendanceErrorRule(null)}
                    />
                  ),
                },
              ]}
            />
            <section className="panel action-panel">
              <div className="panel-header">
                <div>
                  <h3>{editingAttendanceErrorRule ? "勤怠エラールールを編集" : "勤怠エラールールを追加"}</h3>
                </div>
              </div>
              <form
                key={editingAttendanceErrorRule?.code ?? "new-attendance-error-rule"}
                className="stack-form"
                action={async (formData) => {
                  await props.actions.onSystemForm("attendanceErrorRule", formData);
                  setEditingAttendanceErrorRule(null);
                }}
              >
                {editingAttendanceErrorRule ? <input type="hidden" name="code" value={editingAttendanceErrorRule.code} /> : null}
                <label>
                  管理用名
                  <input name="code" placeholder="例: 休憩不足" defaultValue={editingAttendanceErrorRule?.code ?? ""} disabled={Boolean(editingAttendanceErrorRule)} />
                </label>
                <label>
                  名称
                  <input name="name" defaultValue={editingAttendanceErrorRule?.name ?? "休憩不足（8時間超）"} />
                </label>
                <div className="form-grid">
                  <label>
                    勤務下限(分)
                    <input name="minWorkMinutes" type="number" defaultValue={editingAttendanceErrorRule?.minWorkMinutes ?? "480"} />
                  </label>
                  <label>
                    勤務上限(分)
                    <input name="maxWorkMinutes" type="number" defaultValue={editingAttendanceErrorRule?.maxWorkMinutes ?? ""} />
                  </label>
                  <label>
                    必要休憩(分)
                    <input name="requiredBreakMinutes" type="number" defaultValue={editingAttendanceErrorRule?.requiredBreakMinutes ?? "60"} />
                  </label>
                  <label>
                    休憩上限(分)
                    <input name="maxBreakMinutes" type="number" defaultValue={editingAttendanceErrorRule?.maxBreakMinutes ?? ""} />
                  </label>
                </div>
                <label>
                  表示順
                  <input name="sortOrder" type="number" defaultValue={editingAttendanceErrorRule?.sortOrder ?? "60"} />
                </label>
                <label>
                  備考
                  <input name="note" defaultValue={editingAttendanceErrorRule?.note ?? ""} />
                </label>
                <label className="checkbox-row">
                  <input name="enabled" type="checkbox" defaultChecked={editingAttendanceErrorRule?.enabled ?? true} />
                  有効
                </label>
                <FormButtonRow editing={Boolean(editingAttendanceErrorRule)} saveLabel="勤怠エラールールを保存" onCancel={() => setEditingAttendanceErrorRule(null)} />
              </form>
            </section>
          </section>
          <section className="split">
            <DataTable
              title="打刻アラート設定"
              rows={props.data.dashboard.systemMasters.attendanceAlerts}
              emptyMessage="勤怠アラート設定はまだありません"
              columns={[
                { key: "code", header: "管理用名", render: (row) => formatDisplayCode(row.code) },
                { key: "name", header: "名称", render: (row) => row.name },
                { key: "thresholdMinutes", header: "閾値", render: (row) => formatDurationMinutes(row.thresholdMinutes ?? 0) },
                { key: "enabled", header: "状態", render: (row) => (row.enabled ? "有効" : "無効") },
                {
                  key: "actions",
                  header: "操作",
                  render: (row) => (
                    <MasterRowActions
                      target="attendanceAlert"
                      label={row.name}
                      disabled={!row.enabled}
                      hiddenFields={{
                        code: row.code,
                        name: row.name,
                        thresholdMinutes: row.thresholdMinutes ?? "",
                        note: row.note ?? "",
                      }}
                      onEdit={() => setEditingAttendanceAlert(row)}
                      onSystemForm={props.actions.onSystemForm}
                      afterSubmit={() => setEditingAttendanceAlert(null)}
                    />
                  ),
                },
              ]}
            />
            <section className="panel action-panel">
              <div className="panel-header">
                <div>
                  <h3>{editingAttendanceAlert ? "勤怠アラート設定を編集" : "勤怠アラート設定を追加"}</h3>
                </div>
              </div>
              <form
                key={editingAttendanceAlert?.code ?? "new-attendance-alert"}
                className="stack-form"
                action={async (formData) => {
                  await props.actions.onSystemForm("attendanceAlert", formData);
                  setEditingAttendanceAlert(null);
                }}
              >
                {editingAttendanceAlert ? <input type="hidden" name="code" value={editingAttendanceAlert.code} /> : null}
                <label>
                  管理用名
                  <input name="code" placeholder="例: 退勤未打刻" defaultValue={editingAttendanceAlert?.code ?? ""} disabled={Boolean(editingAttendanceAlert)} />
                </label>
                <label>
                  名称
                  <input name="name" defaultValue={editingAttendanceAlert?.name ?? "未退勤"} />
                </label>
                <label>
                  閾値(分)
                  <input name="thresholdMinutes" type="number" defaultValue={editingAttendanceAlert?.thresholdMinutes ?? "0"} />
                </label>
                <label>
                  備考
                  <input name="note" defaultValue={editingAttendanceAlert?.note ?? ""} />
                </label>
                <label className="checkbox-row">
                  <input name="enabled" type="checkbox" defaultChecked={editingAttendanceAlert?.enabled ?? true} />
                  有効
                </label>
                <FormButtonRow editing={Boolean(editingAttendanceAlert)} saveLabel="勤怠アラート設定を保存" onCancel={() => setEditingAttendanceAlert(null)} />
              </form>
            </section>
          </section>
        </section>
      ) : null}

      {activePanel === "system-daily-fields" ? (
        <section id="system-daily-fields" className="split anchor-panel">
          <DataTable
            title="日次勤怠項目"
            rows={props.data.dashboard.systemMasters.dailyFieldSettings}
            emptyMessage="日次勤怠項目はまだありません"
            columns={[
              { key: "fieldKey", header: "管理用名", render: (row) => row.label || formatDisplayCode(row.fieldKey) },
              { key: "label", header: "名称", render: (row) => row.label },
              { key: "displayOrder", header: "表示順", render: (row) => row.displayOrder },
              { key: "enabled", header: "状態", render: (row) => (row.enabled ? "有効" : "無効") },
              {
                key: "actions",
                header: "操作",
                render: (row) => (
                  <MasterRowActions
                    target="dailyField"
                    label={row.label}
                    disabled={!row.enabled}
                    hiddenFields={{ fieldKey: row.fieldKey, label: row.label, displayOrder: row.displayOrder }}
                    onEdit={() => setEditingDailyField(row)}
                    onSystemForm={props.actions.onSystemForm}
                    afterSubmit={() => setEditingDailyField(null)}
                  />
                ),
              },
            ]}
          />
          <section className="panel action-panel">
            <div className="panel-header">
              <div>
                <h3>{editingDailyField ? "日次勤怠項目を編集" : "日次勤怠項目を追加"}</h3>
              </div>
            </div>
            <form
              key={editingDailyField?.fieldKey ?? "new-daily-field"}
              className="stack-form"
              action={async (formData) => {
                await props.actions.onSystemForm("dailyField", formData);
                setEditingDailyField(null);
              }}
            >
              {editingDailyField ? <input type="hidden" name="fieldKey" value={editingDailyField.fieldKey} /> : null}
              <label>
                管理用名
                <input name="fieldKey" placeholder="例: 備考" defaultValue={editingDailyField?.fieldKey ?? ""} disabled={Boolean(editingDailyField)} />
              </label>
              <label>
                表示名
                <input name="label" defaultValue={editingDailyField?.label ?? "備考"} />
              </label>
              <label>
                表示順
                <input name="displayOrder" type="number" defaultValue={editingDailyField?.displayOrder ?? "100"} />
              </label>
              <label className="checkbox-row">
                <input name="enabled" type="checkbox" defaultChecked={editingDailyField?.enabled ?? true} />
                有効
              </label>
              <FormButtonRow editing={Boolean(editingDailyField)} saveLabel="日次勤怠項目を保存" onCancel={() => setEditingDailyField(null)} />
            </form>
          </section>
        </section>
      ) : null}

      {activePanel === "system-environment" ? (
        <section id="system-environment" className="stack-section anchor-panel">
          <section className="panel action-panel">
            <div className="panel-header">
              <div>
                <p className="panel-kicker">環境チェック</p>
                <h3>取込・出力に必要なサーバー機能</h3>
              </div>
              <span className={props.data.dashboard.environment.status === "OK" ? "status-badge status-badge-success" : "status-badge status-badge-warning"}>
                {props.data.dashboard.environment.status === "OK" ? "正常" : `${props.data.dashboard.environment.missingCount}件不足`}
              </span>
            </div>
            <p className="compact-empty">
              {formatEnvironmentMessage(props.data.dashboard.environment.status, props.data.dashboard.environment.missingCount)}
            </p>
          </section>
          <DataTable
            title="環境チェック結果"
            rows={props.data.dashboard.environment.checks}
            emptyMessage="環境チェックはまだ読み込まれていません"
            columns={[
              { key: "label", header: "項目", render: (row) => formatEnvironmentLabel(row.key, row.label) },
              { key: "enabled", header: "状態", render: (row) => (row.enabled ? "有効" : "不足") },
              { key: "purpose", header: "用途", render: (row) => formatEnvironmentPurpose(row.key, row.purpose) },
            ]}
          />
        </section>
      ) : null}

      {props.data.systemResult ? <p className="feedback">{props.data.systemResult}</p> : null}
    </section>
  );
}

function FormButtonRow(props: { editing: boolean; saveLabel: string; onCancel: () => void }) {
  return (
    <div className="button-row">
      <button type="submit">{props.saveLabel}</button>
      {props.editing ? (
        <button type="button" className="secondary" onClick={props.onCancel}>
          新規追加に戻る
        </button>
      ) : null}
    </div>
  );
}

function normalizeClockTime(value?: string | null) {
  if (!value) {
    return "";
  }
  return value.slice(0, 5);
}

function formatClockTime(value?: string | null) {
  return normalizeClockTime(value) || "-";
}

function MasterRowActions(props: {
  target: SystemFormTarget;
  label: string;
  disabled?: boolean;
  hiddenFields: Record<string, string | number | boolean | null | undefined>;
  onEdit: () => void;
  onSystemForm: (target: SystemFormTarget, formData: FormData) => Promise<void>;
  afterSubmit: () => void;
}) {
  return (
    <div className="table-action-row">
      <button type="button" className="secondary compact-button" onClick={props.onEdit}>
        編集
      </button>
      {props.disabled ? null : (
        <form
          action={async (formData) => {
            await props.onSystemForm(props.target, formData);
            props.afterSubmit();
          }}
          onSubmit={(event) => {
            if (!window.confirm(`${props.label}を無効にします。よろしいですか？`)) {
              event.preventDefault();
            }
          }}
        >
          <HiddenFields values={props.hiddenFields} />
          <button type="submit" className="secondary compact-button">
            削除
          </button>
        </form>
      )}
    </div>
  );
}

function HiddenFields(props: { values: Record<string, string | number | boolean | null | undefined> }) {
  return (
    <>
      {Object.entries(props.values).map(([key, value]) =>
        value === null || value === undefined ? null : <input key={key} type="hidden" name={key} value={String(value)} />,
      )}
    </>
  );
}
