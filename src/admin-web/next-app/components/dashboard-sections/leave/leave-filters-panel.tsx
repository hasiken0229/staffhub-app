import type { LeaveSectionProps } from "./leave-section-types";

type LeaveFiltersPanelProps = {
  dashboard: LeaveSectionProps["data"]["dashboard"];
  filters: LeaveSectionProps["filters"];
  actions: LeaveSectionProps["actions"];
};

export function LeaveFiltersPanel({ dashboard, filters, actions }: LeaveFiltersPanelProps) {
  return (
    <section id="leave-filters" className="panel action-panel anchor-panel">
      <div className="panel-header">
        <div>
          <h3>届出の検索条件</h3>
        </div>
      </div>
      <div className="filter-grid">
        <label>
          状態
          <select value={filters.workProcedureStatus} onChange={(event) => actions.onWorkProcedureStatusChange(event.target.value)}>
            <option value="">すべて</option>
            <option value="PENDING">承認待ち</option>
            <option value="APPROVED">承認済み</option>
            <option value="RETURNED">差戻し</option>
            <option value="REJECTED">却下</option>
          </select>
        </label>
        <label>
          職員番号
          <input
            value={filters.workProcedureEmployeeCode}
            onChange={(event) => actions.onWorkProcedureEmployeeCodeChange(event.target.value)}
            placeholder="例: 132"
          />
        </label>
        <label>
          部門
          <select value={filters.workProcedureDepartmentName} onChange={(event) => actions.onWorkProcedureDepartmentNameChange(event.target.value)}>
            <option value="">すべて</option>
            {dashboard.systemMasters.departments.map((department) => (
              <option key={department.id} value={department.name}>
                {department.name}
              </option>
            ))}
          </select>
        </label>
        <label>
          休暇区分
          <select value={filters.workProcedureLeaveTypeCode} onChange={(event) => actions.onWorkProcedureLeaveTypeCodeChange(event.target.value)}>
            <option value="">すべて</option>
            {dashboard.systemMasters.leaveTypes.map((leaveType) => (
              <option key={leaveType.code} value={leaveType.code}>
                {leaveType.name}
              </option>
            ))}
          </select>
        </label>
        <label>
          届出カテゴリ
          <select value={filters.workProcedureRequestCategory} onChange={(event) => actions.onWorkProcedureRequestCategoryChange(event.target.value)}>
            <option value="">すべて</option>
            <option value="LEAVE">通常休暇</option>
            <option value="TIME_LEAVE">時間休暇</option>
          </select>
        </label>
        <label>
          時間休暇種別
          <select value={filters.workProcedureTimeLeaveType} onChange={(event) => actions.onWorkProcedureTimeLeaveTypeChange(event.target.value)}>
            <option value="">すべて</option>
            <option value="PAID_HOURLY">時間有給</option>
            <option value="CHILD_CARE_HOURLY">子の看護（時間）</option>
            <option value="NURSING_CARE_HOURLY">介護（時間）</option>
          </select>
        </label>
        <label>
          申請開始日
          <input type="date" value={filters.workProcedureFrom} onChange={(event) => actions.onWorkProcedureFromChange(event.target.value)} />
        </label>
        <label>
          申請終了日
          <input type="date" value={filters.workProcedureTo} onChange={(event) => actions.onWorkProcedureToChange(event.target.value)} />
        </label>
      </div>
      <div className="button-row">
        <button type="button" onClick={() => void actions.onApplyWorkProcedureFilters()}>
          条件で再表示
        </button>
        <button type="button" className="secondary" onClick={() => void actions.onResetWorkProcedureFilters()}>
          条件をクリア
        </button>
      </div>
    </section>
  );
}
