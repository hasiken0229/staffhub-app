import { DataTable } from "@/components/data-table";
import type { DashboardData } from "@/types";

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
            { key: "status", header: "状態", render: (row) => props.formatters.formatApprovalStatus(row.status) },
          ]}
        />
      ) : null}
    </section>
  );
}
