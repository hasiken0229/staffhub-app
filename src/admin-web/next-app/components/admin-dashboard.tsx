"use client";

import { type ReactNode, useEffect, useState, useTransition } from "react";
import { AdminDashboardView } from "@/components/admin-dashboard-view";
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
import { useEmployeePortalActions } from "@/hooks/use-employee-portal-actions";
import { useLeaveActions } from "@/hooks/use-leave-actions";
import { useLeaveAdminState } from "@/hooks/use-leave-admin-state";
import { useNoticeFormState } from "@/hooks/use-notice-form-state";
import { usePayrollAdminState } from "@/hooks/use-payroll-admin-state";
import { usePayrollActions } from "@/hooks/use-payroll-actions";
import { useReportState } from "@/hooks/use-report-state";
import { useSystemActions } from "@/hooks/use-system-actions";
import { useDashboardSectionProps } from "@/hooks/use-dashboard-section-props";
import { loadAdminSectionData } from "@/lib/api";
import { emptyData, emptyEmployeePortal, sectionLabels, sectionSubNavItems, type AdminSectionKey } from "@/lib/dashboard-defaults";
import { AttendanceSection } from "@/components/dashboard-sections/attendance-section";
import { AuditSection } from "@/components/dashboard-sections/audit-section";
import { CardsSection } from "@/components/dashboard-sections/cards-section";
import { DashboardOverviewSection } from "@/components/dashboard-sections/dashboard-overview-section";
import { EmployeePortalSection } from "@/components/dashboard-sections/employee-portal-section";
import { EmployeesSection } from "@/components/dashboard-sections/employees-section";
import { HarmosMigrationSection } from "@/components/dashboard-sections/harmos-migration-section";
import { LeaveSection } from "@/components/dashboard-sections/leave-section";
import { NoticesSection } from "@/components/dashboard-sections/notices-section";
import { PayrollSection } from "@/components/dashboard-sections/payroll-section";
import { ReportsSection } from "@/components/dashboard-sections/reports-section";
import { SystemSection } from "@/components/dashboard-sections/system-section";
import type {
  AuthAudience,
  CurrentUser,
  DashboardData,
  EmployeePortalData,
} from "@/types";

export function AdminDashboard() {
  const initialActiveSection = (): AdminSectionKey => {
    if (typeof window === "undefined") {
      return "dashboard";
    }

    const stored = window.sessionStorage.getItem("staffhub.activeSection");
    return stored && stored in sectionLabels ? (stored as AdminSectionKey) : "dashboard";
  };

  const initialSubSections = (): Partial<Record<AdminSectionKey, string>> => {
    if (typeof window === "undefined") {
      return {};
    }

    try {
      return JSON.parse(window.sessionStorage.getItem("staffhub.activeSubSections") ?? "{}") as Partial<Record<AdminSectionKey, string>>;
    } catch {
      return {};
    }
  };

  const [dashboard, setDashboard] = useState<DashboardData>(emptyData);
  const [employeePortal, setEmployeePortal] = useState<EmployeePortalData>(emptyEmployeePortal);
  const [currentUser, setCurrentUser] = useState<CurrentUser | null>(null);
  const [activeSection, setActiveSection] = useState<AdminSectionKey>(initialActiveSection);
  const [loadedAdminSections, setLoadedAdminSections] = useState<Partial<Record<AdminSectionKey, boolean>>>({ dashboard: true });
  const [activeSubSectionBySection, setActiveSubSectionBySection] = useState<Partial<Record<AdminSectionKey, string>>>(initialSubSections);
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
    handleDeleteCard,
    handleEmployeeImport,
    handleFileHistoryDownload,
    handleDailyAttendanceCsvDownload,
    handleDailyAttendancePdfDownload,
    handleMonthlyAttendanceCsvDownload,
    handleMonthlyPayrollCsvDownload,
    handleMonthlyWorksPdfDownload,
    handleNotificationRead,
    handleRevokeCard,
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
    onRefresh: refreshCurrentView,
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
    onRefresh: refreshCurrentView,
  });
  const { handleSystemForm } = useSystemActions({
    setSystemResult,
    onRefresh: refreshCurrentView,
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
    onRefresh: refreshCurrentView,
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
    onRefresh: refreshCurrentView,
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
  const storedSubNavId = activeSubSectionBySection[activeSection];
  const defaultSubNavId = currentSubNavItems[0]?.targetId ?? "";
  const currentSubNavId = storedSubNavId && currentSubNavItems.some((item) => item.targetId === storedSubNavId) ? storedSubNavId : defaultSubNavId;
  async function refreshCurrentView() {
    if (currentAudience !== "ADMIN") {
      await refresh();
      return;
    }

    const partialDashboard = await loadAdminSectionData(activeSection);
    setDashboard((current) => ({ ...current, ...partialDashboard }));
    setLoadedAdminSections((current) => ({ ...current, [activeSection]: true }));
    setErrorMessage("");
  }
  const handleRefresh = () => startTransition(() => void refreshCurrentView());
  const canUseEmployeePortal = Boolean(currentUser?.isAdmin && currentUser.canUseEmployeePortal);

  useEffect(() => {
    window.sessionStorage.setItem("staffhub.activeSection", activeSection);
  }, [activeSection]);

  useEffect(() => {
    window.sessionStorage.setItem("staffhub.activeSubSections", JSON.stringify(activeSubSectionBySection));
  }, [activeSubSectionBySection]);

  useEffect(() => {
    if (currentAudience !== "ADMIN" || loadedAdminSections[activeSection]) {
      return;
    }

    startTransition(() => {
      void loadAdminSectionData(activeSection)
        .then((partialDashboard) => {
          setDashboard((current) => ({ ...current, ...partialDashboard }));
          setLoadedAdminSections((current) => ({ ...current, [activeSection]: true }));
          setErrorMessage("");
        })
        .catch((error) => {
          setErrorMessage(error instanceof Error ? error.message : `${sectionLabels[activeSection]}の読込に失敗しました。`);
        });
    });
  }, [activeSection, currentAudience, loadedAdminSections]);

  function handlePortalModeChange(mode: AuthAudience) {
    if (!canUseEmployeePortal || mode === currentAudience) {
      return;
    }

    setErrorMessage("");
    setCurrentAudience(mode);
    startTransition(() => {
      void bootstrap(mode);
    });
  }

  function handleAdminSectionChange(section: string) {
    if (!(section in sectionLabels)) {
      return;
    }

    const nextSection = section as AdminSectionKey;
    const nextSubNavItems = sectionSubNavItems[nextSection] ?? [];
    setActiveSection(nextSection);
    setActiveSubSectionBySection((current) => ({
      ...current,
      [nextSection]: nextSubNavItems.some((item) => item.targetId === current[nextSection])
        ? current[nextSection]
        : nextSubNavItems[0]?.targetId ?? "",
    }));

    if (!loadedAdminSections[nextSection]) {
      startTransition(() => {
        void loadAdminSectionData(nextSection)
          .then((partialDashboard) => {
            setDashboard((current) => ({ ...current, ...partialDashboard }));
            setLoadedAdminSections((current) => ({ ...current, [nextSection]: true }));
            setErrorMessage("");
          })
          .catch((error) => {
            setErrorMessage(error instanceof Error ? error.message : `${sectionLabels[nextSection]}の読込に失敗しました。`);
          });
      });
    }
  }

  function handleSubNavChange(targetId: string) {
    setActiveSubSectionBySection((current) => ({
      ...current,
      [activeSection]: targetId,
    }));
  }

  const {
    handleEmployeeAttendanceDailyEditRequestCreate,
    handleEmployeeLeaveRequestCreate,
    handleEmployeeUpdate,
  } = useEmployeePortalActions({
    setEmployeePortal,
    setErrorMessage,
    onRefresh: refresh,
  });

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
    handleRevokeCard,
    handleDeleteCard,
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
    handleDailyAttendanceCsvDownload,
    handleDailyAttendancePdfDownload,
    handleMonthlyAttendanceCsvDownload,
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
    harmosMigration: (
      <HarmosMigrationSection
        data={{ activePanel: currentSubNavId, importHistory: dashboard.importHistory }}
        actions={{ onRefresh: handleRefresh }}
      />
    ),
    reports: <ReportsSection {...reportsSectionProps} />,
    system: <SystemSection {...systemSectionProps} />,
    audit: <AuditSection {...auditSectionProps} />,
  } satisfies Record<AdminSectionKey, ReactNode>;
  const navBadges = {
    attendance: dashboard.attendanceApprovals.length,
    leave: dashboard.reportsHub.pendingLeaveCount || dashboard.workProcedures.filter((row) => row.status === "PENDING").length,
  };

  return (
    <AdminDashboardView
      isAuthenticated={isAuthenticated}
      loginProps={{
        loginId,
        password,
        authMessage,
        onLoginIdChange: setLoginId,
        onPasswordChange: setPassword,
        onLogin: handleLogin,
      }}
      currentAudience={currentAudience}
      employeePortalSectionProps={employeePortalSectionProps}
      adminShellProps={{
        activeSection,
        currentSectionTitle,
        currentUserName: currentUser?.name,
        currentMode: currentAudience || "ADMIN",
        canUseEmployeePortal,
        subNavItems: currentSubNavItems,
        activeSubNavId: currentSubNavId,
        isPending,
        errorMessage,
        navBadges,
        onModeChange: handlePortalModeChange,
        onActiveSectionChange: handleAdminSectionChange,
        onSubNavChange: handleSubNavChange,
        onRefresh: handleRefresh,
        onLogout: handleLogout,
      }}
      adminSections={adminSections}
      activeSection={activeSection}
    />
  );
}
