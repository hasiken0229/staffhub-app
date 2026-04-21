"use client";

import { type ReactNode, useState, useTransition } from "react";
import { AdminPortalShell } from "@/components/admin-portal-shell";
import { LoginSection } from "@/components/login-section";
import { useAdminDashboardDerivedData } from "@/hooks/use-admin-dashboard-derived-data";
import { useAdminDashboardEffects } from "@/hooks/use-admin-dashboard-effects";
import { useAdminUtilityActions } from "@/hooks/use-admin-utility-actions";
import { useAttendanceActions } from "@/hooks/use-attendance-actions";
import { useAttendanceAdminState } from "@/hooks/use-attendance-admin-state";
import { useAuditActions } from "@/hooks/use-audit-actions";
import { useAuditFilterState } from "@/hooks/use-audit-filter-state";
import { useAuthState } from "@/hooks/use-auth-state";
import { useCardAssignmentState } from "@/hooks/use-card-assignment-state";
import { useDashboardSessionActions } from "@/hooks/use-dashboard-session-actions";
import { useLeaveActions } from "@/hooks/use-leave-actions";
import { useLeaveAdminState } from "@/hooks/use-leave-admin-state";
import { useNoticeFormState } from "@/hooks/use-notice-form-state";
import { usePayrollAdminState } from "@/hooks/use-payroll-admin-state";
import { usePayrollActions } from "@/hooks/use-payroll-actions";
import { useReportState } from "@/hooks/use-report-state";
import { useSystemActions } from "@/hooks/use-system-actions";
import { useDashboardSectionProps } from "@/hooks/use-dashboard-section-props";
import { emptyData, emptyEmployeePortal, sectionLabels, sectionSubNavItems, type AdminSectionKey } from "@/lib/dashboard-defaults";
import { AttendanceSection } from "@/components/dashboard-sections/attendance-section";
import { AuditSection } from "@/components/dashboard-sections/audit-section";
import { CardsSection } from "@/components/dashboard-sections/cards-section";
import { DashboardOverviewSection } from "@/components/dashboard-sections/dashboard-overview-section";
import { EmployeePortalSection } from "@/components/dashboard-sections/employee-portal-section";
import { EmployeesSection } from "@/components/dashboard-sections/employees-section";
import { LeaveSection } from "@/components/dashboard-sections/leave-section";
import { NoticesSection } from "@/components/dashboard-sections/notices-section";
import { PayrollSection } from "@/components/dashboard-sections/payroll-section";
import { ReportsSection } from "@/components/dashboard-sections/reports-section";
import { SystemSection } from "@/components/dashboard-sections/system-section";
import {
  createEmployeeAttendanceDailyEditRequest,
  createEmployeeLeaveRequest,
  loadEmployeeLeaveLedger,
  loadEmployeeAttendanceDailyEditRequests,
  loadEmployeeLeaveRequests,
  loadEmployeePortalHome,
  updateEmployee,
} from "@/lib/api";
import type {
  AuthAudience,
  AttendanceDailyEditRequestCreatePayload,
  CurrentUser,
  DashboardData,
  EmployeePortalData,
  EmployeeUpdatePayload,
  LeaveRequestCreatePayload,
} from "@/types";

export function AdminDashboard() {
  const [dashboard, setDashboard] = useState<DashboardData>(emptyData);
  const [employeePortal, setEmployeePortal] = useState<EmployeePortalData>(emptyEmployeePortal);
  const [currentUser, setCurrentUser] = useState<CurrentUser | null>(null);
  const [activeSection, setActiveSection] = useState<AdminSectionKey>("dashboard");
  const [activeSubSectionBySection, setActiveSubSectionBySection] = useState<Partial<Record<AdminSectionKey, string>>>({});
  const [errorMessage, setErrorMessage] = useState("");
  const [employeeImportResult, setEmployeeImportResult] = useState("");
  const [systemResult, setSystemResult] = useState("");
  const [isPending, startTransition] = useTransition();
  const {
    auditActorFilter,
    setAuditActorFilter,
    auditActionFilter,
    setAuditActionFilter,
    auditFrom,
    setAuditFrom,
    auditTo,
    setAuditTo,
  } = useAuditFilterState();
  const {
    loginId,
    setLoginId,
    password,
    setPassword,
    authMessage,
    setAuthMessage,
    isAuthenticated,
    setIsAuthenticated,
    currentAudience,
    setCurrentAudience,
  } = useAuthState();
  const {
    attendanceDecisionComment,
    setAttendanceDecisionComment,
    attendanceDecisionResult,
    setAttendanceDecisionResult,
    attendanceCloseResult,
    setAttendanceCloseResult,
    attendanceFilterMonth,
    setAttendanceFilterMonth,
    attendanceFilterEmployeeCode,
    setAttendanceFilterEmployeeCode,
    attendanceFilterDepartmentName,
    setAttendanceFilterDepartmentName,
    attendanceApprovalStatus,
    setAttendanceApprovalStatus,
    attendanceEventFrom,
    setAttendanceEventFrom,
    attendanceEventTo,
    setAttendanceEventTo,
    attendanceErrorCode,
    setAttendanceErrorCode,
    attendanceErrorHandlingStatus,
    setAttendanceErrorHandlingStatus,
    attendanceMonthCloseApprovalStatus,
    setAttendanceMonthCloseApprovalStatus,
    attendanceMonthCloseStatusFilter,
    setAttendanceMonthCloseStatusFilter,
  } = useAttendanceAdminState();
  const {
    payrollResult,
    setPayrollResult,
    payrollDefinitionResult,
    setPayrollDefinitionResult,
    payrollBatchResult,
    setPayrollBatchResult,
    payrollStatementType,
    setPayrollStatementType,
    payrollDefinitionId,
    setPayrollDefinitionId,
    payrollDefinitionName,
    setPayrollDefinitionName,
    payrollDefinitionActive,
    setPayrollDefinitionActive,
    payrollTargetYearMonth,
    setPayrollTargetYearMonth,
    payrollPeriodStartOn,
    setPayrollPeriodStartOn,
    payrollPeriodEndOn,
    setPayrollPeriodEndOn,
    payrollPayDate,
    setPayrollPayDate,
    payrollPublishDate,
    setPayrollPublishDate,
    payrollRemarks,
    setPayrollRemarks,
    payrollBatchTargetMonthFilter,
    setPayrollBatchTargetMonthFilter,
    selectedPayrollBatchId,
    setSelectedPayrollBatchId,
    selectedPayrollBatchDetail,
    setSelectedPayrollBatchDetail,
    payrollBatchEmployeeCodeFilter,
    setPayrollBatchEmployeeCodeFilter,
    payrollBatchEmployeeNameFilter,
    setPayrollBatchEmployeeNameFilter,
    selectedAdminPayrollDetail,
    setSelectedAdminPayrollDetail,
    selectedEmployeePayrollDetail,
    setSelectedEmployeePayrollDetail,
  } = usePayrollAdminState();
  const {
    assignEmployeeId,
    setAssignEmployeeId,
    assignCardUid,
    setAssignCardUid,
    assignResult,
    setAssignResult,
  } = useCardAssignmentState();
  const {
    decisionComment,
    setDecisionComment,
    decisionResult,
    setDecisionResult,
    grantEmployeeId,
    setGrantEmployeeId,
    grantDays,
    setGrantDays,
    grantDate,
    setGrantDate,
    grantExpiresOn,
    setGrantExpiresOn,
    grantNote,
    setGrantNote,
    adjustType,
    setAdjustType,
    adjustDays,
    setAdjustDays,
    adjustDate,
    setAdjustDate,
    adjustNote,
    setAdjustNote,
    leaveAdminResult,
    setLeaveAdminResult,
    workProcedureStatus,
    setWorkProcedureStatus,
    workProcedureEmployeeCode,
    setWorkProcedureEmployeeCode,
    workProcedureDepartmentName,
    setWorkProcedureDepartmentName,
    workProcedureLeaveTypeCode,
    setWorkProcedureLeaveTypeCode,
    workProcedureRequestCategory,
    setWorkProcedureRequestCategory,
    workProcedureTimeLeaveType,
    setWorkProcedureTimeLeaveType,
    workProcedureFrom,
    setWorkProcedureFrom,
    workProcedureTo,
    setWorkProcedureTo,
  } = useLeaveAdminState();
  const {
    noticeType,
    setNoticeType,
    noticeTitle,
    setNoticeTitle,
    noticeBody,
    setNoticeBody,
    noticeStartAt,
    setNoticeStartAt,
    noticeEndAt,
    setNoticeEndAt,
    noticeResult,
    setNoticeResult,
  } = useNoticeFormState();
  const {
    reportMonth,
    setReportMonth,
    reportFrom,
    setReportFrom,
    reportTo,
    setReportTo,
    reportEmployeeId,
    setReportEmployeeId,
    reportResult,
    setReportResult,
  } = useReportState();
  const {
    adminUrl,
    bootstrap,
    handleLogin,
    handleLogout,
    loginUrl,
    refresh,
    restoreStoredSession,
  } = useDashboardSessionActions({
    currentAudience,
    loginId,
    password,
    assignEmployeeId,
    grantEmployeeId,
    reportEmployeeId,
    selectedPayrollBatchId,
    payrollBatchEmployeeCodeFilter,
    payrollBatchEmployeeNameFilter,
    selectedAdminPayrollDetailId: selectedAdminPayrollDetail?.id ?? null,
    selectedEmployeePayrollDetailId: selectedEmployeePayrollDetail?.id ?? null,
    emptyDashboard: emptyData,
    emptyEmployeePortal,
    setCurrentUser,
    setCurrentAudience,
    setDashboard,
    setEmployeePortal,
    setErrorMessage,
    setAuthMessage,
    setIsAuthenticated,
    setAssignEmployeeId,
    setGrantEmployeeId,
    setReportEmployeeId,
    setSelectedPayrollBatchId,
    setSelectedPayrollBatchDetail,
    setSelectedAdminPayrollDetail,
    setSelectedEmployeePayrollDetail,
  });
  const {
    handleAssignCard,
    handleCreateNotice,
    handleEmployeeImport,
    handleFileHistoryDownload,
    handleMonthlyPayrollCsvDownload,
    handleMonthlyWorksPdfDownload,
    handleNotificationRead,
    handleTemplateDownload,
  } = useAdminUtilityActions({
    assignEmployeeId,
    assignCardUid,
    noticeType,
    noticeTitle,
    noticeBody,
    noticeStartAt,
    noticeEndAt,
    reportMonth,
    reportEmployeeId,
    setAssignResult,
    setAssignCardUid,
    setNoticeResult,
    setNoticeTitle,
    setNoticeBody,
    setEmployeeImportResult,
    setPayrollDefinitionResult,
    setReportResult,
    setErrorMessage,
    onRefresh: refresh,
  });
  const { applyAuditFilters, resetAuditFilters } = useAuditActions({
    auditActorFilter,
    auditActionFilter,
    auditFrom,
    auditTo,
    setAuditActorFilter,
    setAuditActionFilter,
    setAuditFrom,
    setAuditTo,
    setDashboard,
    setErrorMessage,
  });
  const {
    applyAttendanceFilters,
    handleAttendanceDecision,
    handleAttendanceDailyEditRequestDecision,
    handleAttendanceErrorStatus,
    handleAttendanceMonthClose,
    handleBulkAttendanceDecision,
    resetAttendanceFilters,
  } = useAttendanceActions({
    dashboard,
    attendanceDecisionComment,
    attendanceFilterMonth,
    attendanceFilterEmployeeCode,
    attendanceFilterDepartmentName,
    attendanceApprovalStatus,
    attendanceEventFrom,
    attendanceEventTo,
    attendanceErrorCode,
    attendanceErrorHandlingStatus,
    attendanceMonthCloseApprovalStatus,
    attendanceMonthCloseStatusFilter,
    setAttendanceDecisionResult,
    setAttendanceCloseResult,
    setAttendanceFilterMonth,
    setAttendanceFilterEmployeeCode,
    setAttendanceFilterDepartmentName,
    setAttendanceApprovalStatus,
    setAttendanceEventFrom,
    setAttendanceEventTo,
    setAttendanceErrorCode,
    setAttendanceErrorHandlingStatus,
    setAttendanceMonthCloseApprovalStatus,
    setAttendanceMonthCloseStatusFilter,
    setDashboard,
    setErrorMessage,
    onRefresh: refresh,
  });
  const { handleSystemForm } = useSystemActions({
    setSystemResult,
    onRefresh: refresh,
  });
  const {
    applyWorkProcedureFilters,
    handleAdjustPaidLeave,
    handleBulkWorkProcedureDecision,
    handleGrantPaidLeave,
    handleWorkProcedureDecision,
    resetWorkProcedureFilters,
  } = useLeaveActions({
    dashboard,
    decisionComment,
    grantEmployeeId,
    grantDays,
    grantDate,
    grantExpiresOn,
    grantNote,
    adjustType,
    adjustDays,
    adjustDate,
    adjustNote,
    workProcedureStatus,
    workProcedureEmployeeCode,
    workProcedureDepartmentName,
    workProcedureLeaveTypeCode,
    workProcedureRequestCategory,
    workProcedureTimeLeaveType,
    workProcedureFrom,
    workProcedureTo,
    setDecisionResult,
    setLeaveAdminResult,
    setWorkProcedureStatus,
    setWorkProcedureEmployeeCode,
    setWorkProcedureDepartmentName,
    setWorkProcedureLeaveTypeCode,
    setWorkProcedureRequestCategory,
    setWorkProcedureTimeLeaveType,
    setWorkProcedureFrom,
    setWorkProcedureTo,
    setDashboard,
    setErrorMessage,
    onRefresh: refresh,
  });
  const {
    handleAdminPayrollDownload,
    handleDeletePayrollBatch,
    handleDeletePayrollStatement,
    handleExportPayrollBatch,
    handleLoadAdminPayrollDetail,
    handleLoadEmployeePayrollDetail,
    handlePayrollBatchCreate,
    handlePayrollDefinitionSave,
    handlePayrollDownload,
    openPayrollBatchDetail,
    searchPayrollBatchDetail,
  } = usePayrollActions({
    payrollDefinitionId,
    payrollStatementType,
    payrollDefinitionName,
    payrollDefinitionActive,
    payrollBatchEmployeeCodeFilter,
    payrollBatchEmployeeNameFilter,
    selectedPayrollBatchId,
    selectedAdminPayrollDetail,
    setPayrollResult,
    setPayrollDefinitionResult,
    setPayrollBatchResult,
    setPayrollDefinitionId,
    setPayrollDefinitionName,
    setPayrollDefinitionActive,
    setSelectedPayrollBatchId,
    setSelectedPayrollBatchDetail,
    setSelectedAdminPayrollDetail,
    setSelectedEmployeePayrollDetail,
    setErrorMessage,
    onRefresh: refresh,
  });
  const {
    filteredPayrollDefinitions,
    filteredPayrollBatches,
    filteredPayrollStatements,
    filteredPayrollHistory,
    reportFileHistory,
    payrollTypeLabel,
  } = useAdminDashboardDerivedData({
    dashboard,
    payrollStatementType,
    payrollBatchTargetMonthFilter,
  });

  const currentSectionTitle = sectionLabels[activeSection];
  const currentSubNavItems = sectionSubNavItems[activeSection] ?? [];
  const currentSubNavId = activeSubSectionBySection[activeSection] ?? currentSubNavItems[0]?.targetId ?? "";
  const handleRefresh = () => startTransition(() => void refresh());
  const canUseEmployeePortal = Boolean(currentUser?.isAdmin && currentUser.canUseEmployeePortal);

  function handlePortalModeChange(mode: AuthAudience) {
    if (!canUseEmployeePortal || mode === currentAudience) {
      return;
    }

    startTransition(() => {
      void bootstrap(mode);
    });
  }

  function handleAdminSectionChange(section: string) {
    if (!(section in sectionLabels)) {
      return;
    }

    const nextSection = section as AdminSectionKey;
    setActiveSection(nextSection);
    setActiveSubSectionBySection((current) => ({
      ...current,
      [nextSection]: current[nextSection] ?? sectionSubNavItems[nextSection]?.[0]?.targetId ?? "",
    }));
  }

  function handleSubNavChange(targetId: string) {
    setActiveSubSectionBySection((current) => ({
      ...current,
      [activeSection]: targetId,
    }));
  }

  async function syncEmployeeLeavePortalSummary() {
    const [home, leaveRequests, attendanceDailyEditRequests, leaveLedger] = await Promise.all([
      loadEmployeePortalHome(),
      loadEmployeeLeaveRequests(),
      loadEmployeeAttendanceDailyEditRequests(),
      loadEmployeeLeaveLedger(),
    ]);

    setEmployeePortal((current) => ({
      ...current,
      home,
      leaveRequests,
      attendanceDailyEditRequests,
      leaveLedger,
    }));
  }

  async function handleEmployeeLeaveRequestCreate(payload: LeaveRequestCreatePayload) {
    setErrorMessage("");
    await createEmployeeLeaveRequest(payload);
    await syncEmployeeLeavePortalSummary();
  }

  async function handleEmployeeAttendanceDailyEditRequestCreate(payload: AttendanceDailyEditRequestCreatePayload) {
    setErrorMessage("");
    await createEmployeeAttendanceDailyEditRequest(payload);
    await syncEmployeeLeavePortalSummary();
  }

  async function handleEmployeeUpdate(id: number, payload: EmployeeUpdatePayload) {
    setErrorMessage("");
    await updateEmployee(id, payload);
    await refresh();
  }

  useAdminDashboardEffects({
    adminUrl,
    bootstrap,
    filteredPayrollDefinitions,
    loginUrl,
    payrollStatementType,
    restoreStoredSession,
    setPayrollDefinitionActive,
    setPayrollDefinitionId,
    setPayrollDefinitionName,
    startTransition,
  });

  const {
    dashboardOverviewSectionProps,
    employeesSectionProps,
    cardsSectionProps,
    attendanceSectionProps,
    leaveSectionProps,
    noticesSectionProps,
    payrollSectionProps,
    reportsSectionProps,
    systemSectionProps,
    auditSectionProps,
    employeePortalSectionProps,
  } = useDashboardSectionProps({
    dashboard,
    employeePortal,
    currentUser,
    currentAudience,
    currentSubNavId,
    isPending,
    errorMessage,
    canUseEmployeePortal,
    employeeImportResult,
    systemResult,
    assignEmployeeId,
    assignCardUid,
    assignResult,
    attendanceDecisionComment,
    attendanceDecisionResult,
    attendanceCloseResult,
    attendanceFilterMonth,
    attendanceFilterEmployeeCode,
    attendanceFilterDepartmentName,
    attendanceApprovalStatus,
    attendanceEventFrom,
    attendanceEventTo,
    attendanceErrorCode,
    attendanceErrorHandlingStatus,
    attendanceMonthCloseApprovalStatus,
    attendanceMonthCloseStatusFilter,
    decisionComment,
    decisionResult,
    grantEmployeeId,
    grantDays,
    grantDate,
    grantExpiresOn,
    grantNote,
    adjustType,
    adjustDays,
    adjustDate,
    adjustNote,
    leaveAdminResult,
    workProcedureStatus,
    workProcedureEmployeeCode,
    workProcedureDepartmentName,
    workProcedureLeaveTypeCode,
    workProcedureRequestCategory,
    workProcedureTimeLeaveType,
    workProcedureFrom,
    workProcedureTo,
    noticeType,
    noticeTitle,
    noticeBody,
    noticeStartAt,
    noticeEndAt,
    noticeResult,
    payrollTypeLabel,
    filteredPayrollDefinitions,
    filteredPayrollBatches,
    filteredPayrollStatements,
    filteredPayrollHistory,
    selectedPayrollBatchDetail,
    selectedAdminPayrollDetail,
    selectedEmployeePayrollDetail,
    payrollResult,
    payrollDefinitionResult,
    payrollBatchResult,
    payrollStatementType,
    payrollDefinitionId,
    payrollDefinitionName,
    payrollDefinitionActive,
    payrollTargetYearMonth,
    payrollPeriodStartOn,
    payrollPeriodEndOn,
    payrollPayDate,
    payrollPublishDate,
    payrollRemarks,
    payrollBatchTargetMonthFilter,
    payrollBatchEmployeeCodeFilter,
    payrollBatchEmployeeNameFilter,
    reportFileHistory,
    reportMonth,
    reportFrom,
    reportTo,
    reportEmployeeId,
    reportResult,
    auditActorFilter,
    auditActionFilter,
    auditFrom,
    auditTo,
    setAssignEmployeeId,
    setAssignCardUid,
    setAttendanceFilterMonth,
    setAttendanceFilterEmployeeCode,
    setAttendanceFilterDepartmentName,
    setAttendanceApprovalStatus,
    setAttendanceEventFrom,
    setAttendanceEventTo,
    setAttendanceDecisionComment,
    setAttendanceErrorCode,
    setAttendanceErrorHandlingStatus,
    setAttendanceMonthCloseApprovalStatus,
    setAttendanceMonthCloseStatusFilter,
    setWorkProcedureStatus,
    setWorkProcedureEmployeeCode,
    setWorkProcedureDepartmentName,
    setWorkProcedureLeaveTypeCode,
    setWorkProcedureRequestCategory,
    setWorkProcedureTimeLeaveType,
    setWorkProcedureFrom,
    setWorkProcedureTo,
    setGrantEmployeeId,
    setGrantDays,
    setGrantDate,
    setGrantExpiresOn,
    setGrantNote,
    setAdjustType,
    setAdjustDays,
    setAdjustDate,
    setAdjustNote,
    setDecisionComment,
    setNoticeType,
    setNoticeTitle,
    setNoticeBody,
    setNoticeStartAt,
    setNoticeEndAt,
    setPayrollStatementType,
    setPayrollDefinitionId,
    setPayrollDefinitionName,
    setPayrollDefinitionActive,
    setPayrollTargetYearMonth,
    setPayrollPeriodStartOn,
    setPayrollPeriodEndOn,
    setPayrollPayDate,
    setPayrollPublishDate,
    setPayrollRemarks,
    setPayrollBatchTargetMonthFilter,
    setPayrollBatchEmployeeCodeFilter,
    setPayrollBatchEmployeeNameFilter,
    setReportMonth,
    setReportFrom,
    setReportTo,
    setReportEmployeeId,
    setAuditActorFilter,
    setAuditActionFilter,
    setAuditFrom,
    setAuditTo,
    handleRefresh,
    handleLogout,
    handlePortalModeChange,
    handleEmployeeImport,
    handleTemplateDownload,
    handleEmployeeUpdate,
    handleAssignCard,
    applyAttendanceFilters,
    resetAttendanceFilters,
    handleAttendanceMonthClose,
    handleAttendanceDecision,
    handleAttendanceDailyEditRequestDecision,
    handleBulkAttendanceDecision,
    handleAttendanceErrorStatus,
    applyWorkProcedureFilters,
    resetWorkProcedureFilters,
    handleWorkProcedureDecision,
    handleBulkWorkProcedureDecision,
    handleGrantPaidLeave,
    handleAdjustPaidLeave,
    handleCreateNotice,
    handlePayrollDefinitionSave,
    handlePayrollBatchCreate,
    openPayrollBatchDetail,
    searchPayrollBatchDetail,
    handleDeletePayrollBatch,
    handleExportPayrollBatch,
    handleLoadAdminPayrollDetail,
    handleAdminPayrollDownload,
    handlePayrollDownload,
    handleDeletePayrollStatement,
    handleFileHistoryDownload,
    handleMonthlyPayrollCsvDownload,
    handleMonthlyWorksPdfDownload,
    handleSystemForm,
    applyAuditFilters,
    resetAuditFilters,
    handleEmployeeLeaveRequestCreate,
    handleEmployeeAttendanceDailyEditRequestCreate,
    handleLoadEmployeePayrollDetail,
    handleNotificationRead,
  });

  const adminSections = {
    dashboard: <DashboardOverviewSection {...dashboardOverviewSectionProps} />,
    employees: <EmployeesSection {...employeesSectionProps} />,
    cards: <CardsSection {...cardsSectionProps} />,
    attendance: <AttendanceSection {...attendanceSectionProps} />,
    leave: <LeaveSection {...leaveSectionProps} />,
    notices: <NoticesSection {...noticesSectionProps} />,
    payroll: <PayrollSection {...payrollSectionProps} />,
    reports: <ReportsSection {...reportsSectionProps} />,
    system: <SystemSection {...systemSectionProps} />,
    audit: <AuditSection {...auditSectionProps} />,
  } satisfies Record<AdminSectionKey, ReactNode>;

  if (!isAuthenticated) {
    return (
      <LoginSection
        loginId={loginId}
        password={password}
        authMessage={authMessage}
        onLoginIdChange={setLoginId}
        onPasswordChange={setPassword}
        onLogin={handleLogin}
      />
    );
  }

  if (currentAudience === "EMPLOYEE") {
    return <EmployeePortalSection {...employeePortalSectionProps} />;
  }

  return (
    <AdminPortalShell
      activeSection={activeSection}
      currentSectionTitle={currentSectionTitle}
      currentUserName={currentUser?.name}
      currentMode={currentAudience || "ADMIN"}
      canUseEmployeePortal={canUseEmployeePortal}
      subNavItems={currentSubNavItems}
      activeSubNavId={currentSubNavId}
      isPending={isPending}
      errorMessage={errorMessage}
      onModeChange={handlePortalModeChange}
      onActiveSectionChange={handleAdminSectionChange}
      onSubNavChange={handleSubNavChange}
      onRefresh={handleRefresh}
      onLogout={handleLogout}
    >
      {adminSections[activeSection]}
    </AdminPortalShell>
  );
}
