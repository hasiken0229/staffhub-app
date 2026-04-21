import { PayrollStatementDetailCard } from "@/components/payroll-statement-detail-card";
import {
  formatApprovalStatus,
  formatDateOnly,
  formatDateTime,
  formatLeaveLedgerEntryType,
  formatMonthDay,
} from "@/lib/api";
import type { EmployeePortalSectionProps, UseDashboardSectionPropsParams } from "@/hooks/dashboard-section-props/types";

export function buildEmployeePortalSectionProps(params: UseDashboardSectionPropsParams): EmployeePortalSectionProps {
  const selectedPayrollDetailCard = params.selectedEmployeePayrollDetail ? (
    <PayrollStatementDetailCard
      detail={params.selectedEmployeePayrollDetail}
      mode="employee"
      onAdminPayrollDownload={params.handleAdminPayrollDownload}
      onPayrollDownload={params.handlePayrollDownload}
      onDeletePayrollStatement={params.handleDeletePayrollStatement}
      formatDateOnly={formatDateOnly}
      formatMonthDay={formatMonthDay}
    />
  ) : null;

  return {
    data: {
      employeePortal: params.employeePortal,
      currentUserName: params.currentUser?.name,
      isPending: params.isPending,
      errorMessage: params.errorMessage,
      selectedPayrollDetailCard,
      currentMode: params.currentAudience || "EMPLOYEE",
      canUseEmployeePortal: params.canUseEmployeePortal,
    },
    actions: {
      onRefresh: params.handleRefresh,
      onLogout: params.handleLogout,
      onModeChange: params.canUseEmployeePortal ? params.handlePortalModeChange : undefined,
      onLeaveRequestCreate: params.handleEmployeeLeaveRequestCreate,
      onAttendanceDailyEditRequestCreate: params.handleEmployeeAttendanceDailyEditRequestCreate,
      onLoadEmployeePayrollDetail: params.handleLoadEmployeePayrollDetail,
      onPayrollDownload: params.handlePayrollDownload,
      onNotificationRead: params.handleNotificationRead,
    },
    formatters: {
      formatDateOnly,
      formatDateTime,
      formatMonthDay,
      formatApprovalStatus,
      formatLeaveLedgerEntryType,
    },
  };
}
