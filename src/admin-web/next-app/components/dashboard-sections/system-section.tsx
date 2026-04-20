import { DataTable } from "@/components/data-table";
import type { DashboardData } from "@/types";

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

type SystemSectionProps = {
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

export function SystemSection(props: SystemSectionProps) {
  const activePanel = props.data.activePanel || "system-departments";

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
          ]}
        />
        <section className="panel action-panel">
          <div className="panel-header">
            <div>
              <h3>部門を追加</h3>
            </div>
          </div>
          <form className="stack-form" action={async (formData) => void props.actions.onSystemForm("department", formData)}>
            <label>
              部門名
              <input name="name" />
            </label>
            <label>
              表示順
              <input name="sortOrder" type="number" />
            </label>
            <label className="checkbox-row">
              <input name="isActive" type="checkbox" defaultChecked />
              有効
            </label>
            <button type="submit">部門を保存</button>
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
          ]}
        />
        <section className="panel action-panel">
          <div className="panel-header">
            <div>
              <h3>拠点を追加</h3>
            </div>
          </div>
          <form className="stack-form" action={async (formData) => void props.actions.onSystemForm("location", formData)}>
            <label>
              拠点名
              <input name="name" />
            </label>
            <label>
              表示順
              <input name="sortOrder" type="number" />
            </label>
            <label className="checkbox-row">
              <input name="isActive" type="checkbox" defaultChecked />
              有効
            </label>
            <button type="submit">拠点を保存</button>
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
            { key: "standardDayMinutes", header: "所定1日", render: (row) => (row.standardDayMinutes ? `${row.standardDayMinutes}分` : "-") },
            { key: "sortOrder", header: "表示順", render: (row) => row.sortOrder },
            { key: "isActive", header: "状態", render: (row) => (row.isActive ? "有効" : "無効") },
          ]}
        />
        <section className="panel action-panel">
          <div className="panel-header">
            <div>
              <h3>雇用形態を追加</h3>
            </div>
          </div>
          <form className="stack-form" action={async (formData) => void props.actions.onSystemForm("employment", formData)}>
            <label>
              雇用区分
              <select name="code" defaultValue="FULL_TIME">
                <option value="FULL_TIME">常勤</option>
                <option value="PART_TIME">非常勤</option>
                <option value="CONTRACT">契約</option>
                <option value="TEMPORARY">臨時</option>
              </select>
            </label>
            <label>
              名称
              <input name="label" defaultValue="常勤" />
            </label>
            <label>
              表示順
              <input name="sortOrder" type="number" />
            </label>
            <label>
              所定1日分
              <input name="standardDayMinutes" type="number" placeholder="例: 480" />
            </label>
            <label className="checkbox-row">
              <input name="isActive" type="checkbox" defaultChecked />
              有効
            </label>
            <button type="submit">雇用形態を保存</button>
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
            { key: "defaultBreakMinutes", header: "既定休憩", render: (row) => `${row.defaultBreakMinutes ?? 0}分` },
            { key: "standardDayMinutes", header: "所定1日", render: (row) => (row.standardDayMinutes ? `${row.standardDayMinutes}分` : "-") },
            { key: "sortOrder", header: "表示順", render: (row) => row.sortOrder },
            { key: "isActive", header: "状態", render: (row) => (row.isActive ? "有効" : "無効") },
          ]}
        />
        <section className="panel action-panel">
          <div className="panel-header">
            <div>
              <h3>勤務区分を追加</h3>
            </div>
          </div>
          <form className="stack-form" action={async (formData) => void props.actions.onSystemForm("workType", formData)}>
            <label>
              名称
              <input name="name" defaultValue="通常勤務" />
            </label>
            <label>
              既定休憩分
              <input name="defaultBreakMinutes" type="number" defaultValue="60" />
            </label>
            <label>
              所定1日分
              <input name="standardDayMinutes" type="number" placeholder="例: 480" />
            </label>
            <label>
              表示順
              <input name="sortOrder" type="number" />
            </label>
            <label className="checkbox-row">
              <input name="isActive" type="checkbox" defaultChecked />
              有効
            </label>
            <button type="submit">勤務区分を保存</button>
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
            { key: "code", header: "コード", render: (row) => row.code },
            { key: "name", header: "名称", render: (row) => row.name },
            { key: "sortOrder", header: "表示順", render: (row) => row.sortOrder },
            { key: "isActive", header: "状態", render: (row) => (row.isActive ? "有効" : "無効") },
          ]}
        />
        <section className="panel action-panel">
          <div className="panel-header">
            <div>
              <h3>申請区分を追加</h3>
            </div>
          </div>
          <form className="stack-form" action={async (formData) => void props.actions.onSystemForm("requestType", formData)}>
            <label>
              コード
              <input name="code" defaultValue="PAID" />
            </label>
            <label>
              名称
              <input name="name" defaultValue="有給申請" />
            </label>
            <label>
              表示順
              <input name="sortOrder" type="number" />
            </label>
            <label className="checkbox-row">
              <input name="isActive" type="checkbox" defaultChecked />
              有効
            </label>
            <button type="submit">申請区分を保存</button>
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
            { key: "code", header: "コード", render: (row) => row.code },
            { key: "name", header: "名称", render: (row) => row.name },
            { key: "requiresBalance", header: "残数管理", render: (row) => (row.requiresBalance ? "あり" : "なし") },
            { key: "allowsHalfDay", header: "半日", render: (row) => (row.allowsHalfDay ? "可" : "不可") },
          ]}
        />
        <section className="panel action-panel">
          <div className="panel-header">
            <div>
              <h3>休暇区分を追加</h3>
            </div>
          </div>
          <form className="stack-form" action={async (formData) => void props.actions.onSystemForm("leaveType", formData)}>
            <label>
              コード
              <input name="code" defaultValue="SPECIAL" />
            </label>
            <label>
              名称
              <input name="name" defaultValue="特別休暇" />
            </label>
            <label>
              表示順
              <input name="sortOrder" type="number" />
            </label>
            <label className="checkbox-row">
              <input name="requiresBalance" type="checkbox" />
              残数管理あり
            </label>
            <label className="checkbox-row">
              <input name="allowsHalfDay" type="checkbox" defaultChecked />
              半日申請可
            </label>
            <button type="submit">休暇区分を保存</button>
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
          ]}
        />
        <section className="panel action-panel">
          <div className="panel-header">
            <div>
              <h3>有給付与設定を追加</h3>
            </div>
          </div>
          <form className="stack-form" action={async (formData) => void props.actions.onSystemForm("paidLeaveSetting", formData)}>
            <label>
              設定名
              <input name="settingName" defaultValue="通常付与" />
            </label>
            <label>
              年間付与日数
              <input name="annualGrantDays" type="number" defaultValue="10" />
            </label>
            <label>
              繰越月数
              <input name="carryForwardMonths" type="number" defaultValue="24" />
            </label>
            <label>
              備考
              <input name="note" />
            </label>
            <label className="checkbox-row">
              <input name="isActive" type="checkbox" defaultChecked />
              有効
            </label>
            <button type="submit">有給付与設定を保存</button>
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
              { key: "code", header: "コード", render: (row) => row.code },
              { key: "name", header: "名称", render: (row) => row.name },
              { key: "minWorkMinutes", header: "勤務下限", render: (row) => row.minWorkMinutes ?? "-" },
              { key: "maxWorkMinutes", header: "勤務上限", render: (row) => row.maxWorkMinutes ?? "-" },
              { key: "requiredBreakMinutes", header: "必要休憩", render: (row) => row.requiredBreakMinutes ?? "-" },
              { key: "maxBreakMinutes", header: "休憩上限", render: (row) => row.maxBreakMinutes ?? "-" },
              { key: "enabled", header: "状態", render: (row) => (row.enabled ? "有効" : "無効") },
            ]}
          />
          <section className="panel action-panel">
            <div className="panel-header">
              <div>
                <h3>勤怠エラールールを保存</h3>
              </div>
            </div>
            <form className="stack-form" action={async (formData) => void props.actions.onSystemForm("attendanceErrorRule", formData)}>
              <label>
                コード
                <input name="code" defaultValue="SHORT_BREAK_OVER_8" />
              </label>
              <label>
                名称
                <input name="name" defaultValue="休憩不足（8時間超）" />
              </label>
              <div className="form-grid">
                <label>
                  勤務下限(分)
                  <input name="minWorkMinutes" type="number" defaultValue="480" />
                </label>
                <label>
                  勤務上限(分)
                  <input name="maxWorkMinutes" type="number" />
                </label>
                <label>
                  必要休憩(分)
                  <input name="requiredBreakMinutes" type="number" defaultValue="60" />
                </label>
                <label>
                  休憩上限(分)
                  <input name="maxBreakMinutes" type="number" />
                </label>
              </div>
              <label>
                表示順
                <input name="sortOrder" type="number" defaultValue="60" />
              </label>
              <label>
                備考
                <input name="note" />
              </label>
              <label className="checkbox-row">
                <input name="enabled" type="checkbox" defaultChecked />
                有効
              </label>
              <button type="submit">勤怠エラールールを保存</button>
            </form>
          </section>
        </section>
        <section className="split">
          <DataTable
            title="打刻アラート設定"
            rows={props.data.dashboard.systemMasters.attendanceAlerts}
            emptyMessage="勤怠アラート設定はまだありません"
            columns={[
              { key: "code", header: "コード", render: (row) => row.code },
              { key: "name", header: "名称", render: (row) => row.name },
              { key: "thresholdMinutes", header: "閾値", render: (row) => `${row.thresholdMinutes ?? 0}分` },
              { key: "enabled", header: "状態", render: (row) => (row.enabled ? "有効" : "無効") },
            ]}
          />
        <section className="panel action-panel">
          <div className="panel-header">
            <div>
              <h3>勤怠アラート設定を追加</h3>
            </div>
          </div>
          <form className="stack-form" action={async (formData) => void props.actions.onSystemForm("attendanceAlert", formData)}>
            <label>
              コード
              <input name="code" defaultValue="MISSING_CLOCK_OUT" />
            </label>
            <label>
              名称
              <input name="name" defaultValue="未退勤" />
            </label>
            <label>
              閾値(分)
              <input name="thresholdMinutes" type="number" defaultValue="0" />
            </label>
            <label>
              備考
              <input name="note" />
            </label>
            <label className="checkbox-row">
              <input name="enabled" type="checkbox" defaultChecked />
              有効
            </label>
            <button type="submit">勤怠アラート設定を保存</button>
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
            { key: "fieldKey", header: "キー", render: (row) => row.fieldKey },
            { key: "label", header: "名称", render: (row) => row.label },
            { key: "displayOrder", header: "表示順", render: (row) => row.displayOrder },
            { key: "enabled", header: "状態", render: (row) => (row.enabled ? "有効" : "無効") },
          ]}
        />
        <section className="panel action-panel">
          <div className="panel-header">
            <div>
              <h3>日次勤怠項目を追加</h3>
            </div>
          </div>
          <form className="stack-form" action={async (formData) => void props.actions.onSystemForm("dailyField", formData)}>
            <label>
              フィールドキー
              <input name="fieldKey" defaultValue="remarks" />
            </label>
            <label>
              表示名
              <input name="label" defaultValue="備考" />
            </label>
            <label>
              表示順
              <input name="displayOrder" type="number" defaultValue="100" />
            </label>
            <label className="checkbox-row">
              <input name="enabled" type="checkbox" defaultChecked />
              有効
            </label>
            <button type="submit">日次勤怠項目を保存</button>
          </form>
        </section>
      </section>
      ) : null}

      {props.data.systemResult ? <p className="feedback">{props.data.systemResult}</p> : null}
    </section>
  );
}
