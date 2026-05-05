import { DataTable } from "@/components/data-table";
import { ApprovalStatusBadge } from "@/components/status-badge";
import type { DashboardData, Employee } from "@/types";

type DashboardOverviewSectionProps = {
  data: {
    dashboard: DashboardData;
    activePanel: string;
  };
  formatters: {
    formatDateOnly: (value?: string | null) => string;
    formatTimeOnly: (value?: string | null) => string;
    formatWorkMinutes: (value?: number | null) => string;
    formatApprovalStatus: (value?: string | null) => string;
  };
};

export function DashboardOverviewSection(props: DashboardOverviewSectionProps) {
  const activePanel = props.data.activePanel || "dashboard-today-attendance";
  const departmentSummaries = buildDepartmentSummaries(props.data.dashboard);

  return (
    <section className="split section-enter delay-3">
      {activePanel === "dashboard-today-attendance" ? (
        <DataTable
          id="dashboard-today-attendance"
          title="本日の勤怠状況"
          rows={props.data.dashboard.reportTodayAttendance}
          emptyMessage="本日の勤怠はまだありません"
          columns={[
            { key: "employeeCode", header: "職員番号", render: (row) => row.employeeCode },
            { key: "employeeName", header: "氏名", render: (row) => row.employeeName },
            { key: "clockInAt", header: "出勤", render: (row) => props.formatters.formatTimeOnly(row.clockInAt) },
            { key: "clockOutAt", header: "退勤", render: (row) => props.formatters.formatTimeOnly(row.clockOutAt) },
            { key: "workMinutes", header: "勤務時間", render: (row) => props.formatters.formatWorkMinutes(row.workMinutes) },
          ]}
        />
      ) : null}
      {activePanel === "dashboard-department-summary" ? (
        <section id="dashboard-department-summary" className="panel anchor-panel dashboard-visual-panel">
          <div className="panel-header">
            <div>
              <p className="panel-kicker">部門別</p>
              <h3>部門別サマリー</h3>
            </div>
            <span className="panel-meta">{departmentSummaries.length} 部門</span>
          </div>
          {departmentSummaries.length === 0 ? (
            <p className="compact-empty">部門別に集計できる職員データがありません</p>
          ) : (
            <div className="department-chart-list">
              {departmentSummaries.map((summary) => (
                <article key={summary.departmentName} className="department-chart-row">
                  <div className="department-chart-header">
                    <div>
                      <strong>{summary.departmentName}</strong>
                      <span>{summary.presentCount}/{summary.employeeCount} 名 出勤</span>
                    </div>
                    <b>{summary.attendanceRate}%</b>
                  </div>
                  <div className="bar-track" aria-label={`${summary.departmentName}の本日出勤率 ${summary.attendanceRate}%`}>
                    <span className="bar-fill" style={{ "--bar-value": `${summary.attendanceRate}%` } as React.CSSProperties} />
                  </div>
                  <div className="department-chart-meta">
                    <span>承認待ち届出 {summary.pendingLeaveCount} 件</span>
                    <span>平均有給残 {formatDays(summary.averagePaidLeaveBalance)} 日</span>
                  </div>
                </article>
              ))}
            </div>
          )}
        </section>
      ) : null}
      {activePanel === "dashboard-pending-requests" ? (
        <DataTable
          id="dashboard-pending-requests"
          title="承認待ちの届出"
          rows={props.data.dashboard.workProcedures}
          emptyMessage="承認待ちの届出はありません"
          columns={[
            { key: "employeeName", header: "申請者", render: (row) => row.employee?.name ?? "-" },
            { key: "leaveTypeName", header: "区分", render: (row) => row.leaveTypeName },
            {
              key: "period",
              header: "期間",
              render: (row) => `${props.formatters.formatDateOnly(row.startDate)} - ${props.formatters.formatDateOnly(row.endDate)}`,
            },
            { key: "status", header: "状態", render: (row) => <ApprovalStatusBadge value={row.status} format={props.formatters.formatApprovalStatus} /> },
          ]}
        />
      ) : null}
    </section>
  );
}

type DepartmentSummary = {
  departmentName: string;
  employeeCount: number;
  presentCount: number;
  attendanceRate: number;
  pendingLeaveCount: number;
  averagePaidLeaveBalance: number;
};

function buildDepartmentSummaries(dashboard: DashboardData): DepartmentSummary[] {
  const departments = new Map<string, { employees: Employee[]; presentCount: number; pendingLeaveCount: number; paidLeaveTotal: number; paidLeaveCount: number }>();

  for (const employee of dashboard.employees) {
    const departmentName = employee.departmentName || "未設定";
    const current = departments.get(departmentName) ?? {
      employees: [],
      presentCount: 0,
      pendingLeaveCount: 0,
      paidLeaveTotal: 0,
      paidLeaveCount: 0,
    };
    current.employees.push(employee);
    departments.set(departmentName, current);
  }

  for (const row of dashboard.reportTodayAttendance) {
    if (!row.clockInAt) {
      continue;
    }

    const departmentName = findDepartmentName(dashboard, row.employeeCode);
    const current = departments.get(departmentName);
    if (current) {
      current.presentCount += 1;
    }
  }

  for (const request of dashboard.workProcedures) {
    const departmentName = request.employee?.departmentName || "未設定";
    const current = departments.get(departmentName);
    if (current) {
      current.pendingLeaveCount += 1;
    }
  }

  for (const row of dashboard.paidLeaveReport) {
    const departmentName = row.departmentName || "未設定";
    const current = departments.get(departmentName);
    if (current) {
      current.paidLeaveTotal += row.currentBalance;
      current.paidLeaveCount += 1;
    }
  }

  return Array.from(departments.entries())
    .map(([departmentName, summary]) => ({
      departmentName,
      employeeCount: summary.employees.length,
      presentCount: summary.presentCount,
      attendanceRate: summary.employees.length > 0 ? Math.round((summary.presentCount / summary.employees.length) * 100) : 0,
      pendingLeaveCount: summary.pendingLeaveCount,
      averagePaidLeaveBalance: summary.paidLeaveCount > 0 ? roundOne(summary.paidLeaveTotal / summary.paidLeaveCount) : 0,
    }))
    .sort((left, right) => right.pendingLeaveCount - left.pendingLeaveCount || right.attendanceRate - left.attendanceRate || left.departmentName.localeCompare(right.departmentName, "ja"));
}

function findDepartmentName(dashboard: DashboardData, employeeCode: string) {
  return dashboard.employees.find((employee) => employee.employeeCode === employeeCode)?.departmentName || "未設定";
}

function roundOne(value: number) {
  return Math.round(value * 10) / 10;
}

function formatDays(value: number) {
  return Number.isInteger(value) ? String(value) : value.toFixed(1).replace(/0$/, "").replace(/\.$/, "");
}
