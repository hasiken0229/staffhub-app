import { buildAttendanceSectionProps, buildLeaveSectionProps } from "@/hooks/dashboard-section-props/attendance-leave-builders";
import {
  buildAuditSectionProps,
  buildCardsSectionProps,
  buildDashboardOverviewSectionProps,
  buildEmployeesSectionProps,
  buildNoticesSectionProps,
  buildSystemSectionProps,
} from "@/hooks/dashboard-section-props/admin-builders";
import { buildPayrollSectionProps, buildReportsSectionProps } from "@/hooks/dashboard-section-props/payroll-report-builders";
import { buildEmployeePortalSectionProps } from "@/hooks/dashboard-section-props/portal-builder";
import type { UseDashboardSectionPropsParams } from "@/hooks/dashboard-section-props/types";

export function useDashboardSectionProps(params: UseDashboardSectionPropsParams) {
  return {
    dashboardOverviewSectionProps: buildDashboardOverviewSectionProps(params),
    employeesSectionProps: buildEmployeesSectionProps(params),
    cardsSectionProps: buildCardsSectionProps(params),
    attendanceSectionProps: buildAttendanceSectionProps(params),
    leaveSectionProps: buildLeaveSectionProps(params),
    noticesSectionProps: buildNoticesSectionProps(params),
    payrollSectionProps: buildPayrollSectionProps(params),
    reportsSectionProps: buildReportsSectionProps(params),
    systemSectionProps: buildSystemSectionProps(params),
    auditSectionProps: buildAuditSectionProps(params),
    employeePortalSectionProps: buildEmployeePortalSectionProps(params),
  };
}
