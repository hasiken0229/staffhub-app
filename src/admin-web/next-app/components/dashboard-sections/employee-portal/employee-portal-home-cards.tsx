import { ApprovalStatusBadge } from "@/components/status-badge";
import type { EmployeePortalSectionProps } from "./employee-portal-types";

type EmployeePortalHomeCardsProps = {
  data: EmployeePortalSectionProps["data"];
  formatters: EmployeePortalSectionProps["formatters"];
};

export function EmployeePortalHomeCards({ data, formatters }: EmployeePortalHomeCardsProps) {
  const latestLeaveRequest = data.employeePortal.leaveRequests[0] ?? null;
  const latestPayroll = data.employeePortal.home.latestPayroll ?? data.employeePortal.payroll[0] ?? null;
  const latestNotification = data.employeePortal.notifications[0] ?? null;

  return (
    <section id="employee-portal-home" className="portal-home-grid section-enter delay-1" aria-label="職員ホーム">
      <a className="portal-home-card portal-home-card-primary" href="#leave-request-form">
        <span className="portal-card-label">有給残日数</span>
        <strong>{data.employeePortal.home.paidLeaveBalance}日</strong>
        <span className="portal-card-detail">休暇申請へ</span>
      </a>

      <a className="portal-home-card" href="#employee-payroll-list">
        <span className="portal-card-label">最新の給与明細</span>
        <strong>{latestPayroll ? latestPayroll.targetYearMonth : "-"}</strong>
        <span className="portal-card-detail">
          {latestPayroll ? `${latestPayroll.statementTypeLabel ?? "明細"} / ${formatters.formatMonthDay(latestPayroll.payDate ?? latestPayroll.publishedAt)}` : "公開済み明細はありません"}
        </span>
      </a>

      <a className="portal-home-card" href="#employee-leave-list">
        <span className="portal-card-label">最新の申請</span>
        <strong>{latestLeaveRequest ? <ApprovalStatusBadge value={latestLeaveRequest.status} format={formatters.formatApprovalStatus} /> : "-"}</strong>
        <span className="portal-card-detail">
          {latestLeaveRequest
            ? latestLeaveRequest.requestCategory === "TIME_LEAVE"
              ? `${formatters.formatDateOnly(latestLeaveRequest.targetDate)} ${latestLeaveRequest.startTime ?? ""}-${latestLeaveRequest.endTime ?? ""}`
              : `${formatters.formatDateOnly(latestLeaveRequest.startDate)} - ${formatters.formatDateOnly(latestLeaveRequest.endDate)}`
            : "申請はまだありません"}
        </span>
      </a>

      <a className="portal-home-card" href="#employee-notices-list">
        <span className="portal-card-label">通知</span>
        <strong>{data.employeePortal.home.unreadNotificationCount}件</strong>
        <span className="portal-card-detail">{latestNotification ? latestNotification.title : "未読通知はありません"}</span>
      </a>
    </section>
  );
}
