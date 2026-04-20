import type { AttendanceSectionProps } from "@/components/dashboard-sections/attendance/attendance-section-types";

type AttendanceFiltersPanelProps = {
  dashboard: AttendanceSectionProps["data"]["dashboard"];
  filters: AttendanceSectionProps["filters"];
  actions: Pick<
    AttendanceSectionProps["actions"],
    | "onAttendanceFilterMonthChange"
    | "onAttendanceFilterEmployeeCodeChange"
    | "onAttendanceFilterDepartmentNameChange"
    | "onAttendanceApprovalStatusChange"
    | "onAttendanceEventFromChange"
    | "onAttendanceEventToChange"
    | "onAttendanceErrorCodeChange"
    | "onAttendanceErrorHandlingStatusChange"
    | "onAttendanceMonthCloseApprovalStatusChange"
    | "onAttendanceMonthCloseStatusFilterChange"
    | "onApplyAttendanceFilters"
    | "onResetAttendanceFilters"
  >;
};

export function AttendanceFiltersPanel({ dashboard, filters, actions }: AttendanceFiltersPanelProps) {
  return (
    <section id="attendance-filters" className="panel action-panel anchor-panel">
      <div className="panel-header">
        <div>
          <h3>日次勤怠の検索条件</h3>
        </div>
      </div>
      <div className="filter-grid">
        <label>
          対象月
          <input
            type="month"
            value={filters.attendanceFilterMonth}
            onChange={(event) => actions.onAttendanceFilterMonthChange(event.target.value)}
          />
        </label>
        <label>
          職員番号
          <input
            value={filters.attendanceFilterEmployeeCode}
            onChange={(event) => actions.onAttendanceFilterEmployeeCodeChange(event.target.value)}
            placeholder="例: 132"
          />
        </label>
        <label>
          部門
          <select
            value={filters.attendanceFilterDepartmentName}
            onChange={(event) => actions.onAttendanceFilterDepartmentNameChange(event.target.value)}
          >
            <option value="">すべて</option>
            {dashboard.systemMasters.departments.map((department) => (
              <option key={department.id} value={department.name}>
                {department.name}
              </option>
            ))}
          </select>
        </label>
        <label>
          承認状態
          <select
            value={filters.attendanceApprovalStatus}
            onChange={(event) => actions.onAttendanceApprovalStatusChange(event.target.value)}
          >
            <option value="">すべて</option>
            <option value="PENDING">承認待ち</option>
            <option value="APPROVED">承認済み</option>
            <option value="RETURNED">差戻し</option>
          </select>
        </label>
        <label>
          打刻開始日
          <input
            type="date"
            value={filters.attendanceEventFrom}
            onChange={(event) => actions.onAttendanceEventFromChange(event.target.value)}
          />
        </label>
        <label>
          打刻終了日
          <input
            type="date"
            value={filters.attendanceEventTo}
            onChange={(event) => actions.onAttendanceEventToChange(event.target.value)}
          />
        </label>
        <label>
          エラー種別
          <select value={filters.attendanceErrorCode} onChange={(event) => actions.onAttendanceErrorCodeChange(event.target.value)}>
            <option value="">すべて</option>
            <option value="MISSING_PUNCH">出勤・退勤入力漏れ</option>
            <option value="MISSING_BOTH_PUNCHES">出勤・退勤未入力</option>
            <option value="LEAVE_WITH_WORK">休日・休暇の出勤</option>
            <option value="MISSING_BREAK">休憩入力漏れ</option>
            <option value="SHORT_BREAK_6_TO_8">休憩不足（6〜8時間）</option>
            <option value="SHORT_BREAK_OVER_8">休憩不足（8時間超）</option>
            <option value="BREAK_TOO_LONG">休憩時間の超過</option>
          </select>
        </label>
        <label>
          対応状況
          <select
            value={filters.attendanceErrorHandlingStatus}
            onChange={(event) => actions.onAttendanceErrorHandlingStatusChange(event.target.value)}
          >
            <option value="">すべて</option>
            <option value="OPEN">未対応</option>
            <option value="IN_PROGRESS">対応中</option>
            <option value="RESOLVED">対応済み</option>
            <option value="IGNORED">対象外</option>
          </select>
        </label>
        <label>
          月締承認状態
          <select
            value={filters.attendanceMonthCloseApprovalStatus}
            onChange={(event) => actions.onAttendanceMonthCloseApprovalStatusChange(event.target.value)}
          >
            <option value="">すべて</option>
            <option value="UNSUBMITTED">未申請（未登録）</option>
            <option value="PENDING">承認待ち</option>
            <option value="RETURNED">差戻し</option>
            <option value="APPROVED">承認済み</option>
          </select>
        </label>
        <label>
          月締状況
          <select
            value={filters.attendanceMonthCloseStatusFilter}
            onChange={(event) => actions.onAttendanceMonthCloseStatusFilterChange(event.target.value)}
          >
            <option value="">すべて</option>
            <option value="OPEN">未締め</option>
            <option value="CLOSED">締め済み</option>
          </select>
        </label>
      </div>
      <div className="button-row">
        <button type="button" onClick={() => void actions.onApplyAttendanceFilters()}>
          条件で再表示
        </button>
        <button type="button" className="secondary" onClick={() => void actions.onResetAttendanceFilters()}>
          条件をクリア
        </button>
      </div>
    </section>
  );
}
