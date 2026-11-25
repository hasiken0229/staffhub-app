"use client";

import { type ReactNode, useCallback, useEffect, useMemo, useRef, useState, useTransition } from "react";
import { AdminDashboardView } from "@/components/admin-dashboard-view";
import { ConfirmDialog } from "@/components/confirm-dialog";
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
import { downloadFileHistory, loadAdminSectionData, type AttendanceSectionLoadOptions } from "@/lib/api";
import { hasAvailableMonthClosePrecheck } from "@/lib/attendance-utils";
import { monthDateRangeValue } from "@/lib/date-defaults";
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
  const [downloadMessage, setDownloadMessage] = useState("");
  useEffect(() => {
    const handleDownload = (event: Event) => setDownloadMessage(`${(event as CustomEvent<{ fileName: string }>).detail.fileName} のダウンロードを開始しました。保存先でファイルを確認してください。`);
    window.addEventListener("staffhub:download-started", handleDownload);
    return () => window.removeEventListener("staffhub:download-started", handleDownload);
  }, []);

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
  const updateAttentionTotal = useCallback((total: number) => {
    setDashboard(current => current.reportsHub.pendingAttendanceApprovalCount === total ? current : {
      ...current, reportsHub: {...current.reportsHub, pendingAttendanceApprovalCount: total},
    });
  }, []);
  const [employeePortal, setEmployeePortal] = useState<EmployeePortalData>(emptyEmployeePortal);
  const employeeAttendanceMonthRef = useRef(new Date().toLocaleDateString("sv-SE").slice(0, 7));
  const getEmployeeAttendanceMonth = useCallback(() => employeeAttendanceMonthRef.current, []);
  const [currentUser, setCurrentUser] = useState<CurrentUser | null>(null);
  const [activeSection, setActiveSection] = useState<AdminSectionKey>(initialActiveSection);
  const [loadedAdminSections, setLoadedAdminSections] = useState<Partial<Record<AdminSectionKey, boolean>>>({ dashboard: true });
  const [activeSubSectionBySection, setActiveSubSectionBySection] = useState<Partial<Record<AdminSectionKey, string>>>(initialSubSections);
  const [reportsMenuResetKey, setReportsMenuResetKey] = useState(0);
  const [errorMessage, setErrorMessage] = useState("");
  const [attendanceLoadError, setAttendanceLoadError] = useState("");
  const [attendancePrecheckReadyMonth, setAttendancePrecheckReadyMonth] = useState<string | null>(null);
  const [attendanceIsLoading, setAttendanceIsLoading] = useState(false);
  const [hasUnsavedEmployeeEdit, setHasUnsavedEmployeeEdit] = useState(false);
  const [isLogoutConfirmOpen, setIsLogoutConfirmOpen] = useState(false);
  const [pendingDiscardAction, setPendingDiscardAction] = useState<(() => void) | null>(null);
  const [employeeImportResult, setEmployeeImportResult] = useState("");
  const [systemResult, setSystemResult] = useState("");
  const [isPending, startTransition] = useTransition();
  const [isSectionLoading, setIsSectionLoading] = useState(false);
  const inFlightAdminSectionsRef = useRef(new Set<AdminSectionKey>());
  const pendingLoadCountRef = useRef(0);
  const attendanceLoadSequenceRef = useRef(0);
  const currentAttendanceMonthRef = useRef("");
  const sectionAudienceRef = useRef<AuthAudience | "">("");
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
    bootstrapFailure,
    checkingSession,
    handleLogin,
    handleInitialPasswordChange,
    handleLogout,
    handleSessionExpired,
    loginUrl,
    refresh,
    restoreStoredSession,
  } = useDashboardSessionActions({
    currentAudience,
    getEmployeeAttendanceMonth,
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
  const { applyAuditFilters, resetAuditFilters, loadAuditPage } = useAuditActions({
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
  currentAttendanceMonthRef.current = attendanceFilterMonth;
  sectionAudienceRef.current = currentAudience;
  const attendanceMonthDataFresh = attendancePrecheckReadyMonth === attendanceFilterMonth
    && hasAvailableMonthClosePrecheck(dashboard.attendanceMonthClosePrecheck, attendanceFilterMonth)
    && dashboard.attendanceMonthlyClose.targetYearMonth === attendanceFilterMonth
    && dashboard.attendanceMonthlyClose.isAvailable !== false
    && !attendanceLoadError
    && !attendanceIsLoading;
  const canCloseAttendanceMonth = attendanceMonthDataFresh && dashboard.attendanceMonthClosePrecheck?.canClose === true;
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
    attendanceMonthDataFresh,
    canCloseAttendanceMonth,
    onLoadAttendance: (options) => loadAdminSection("attendance", options),
    setAttendanceIsLoading,
    setAttendanceLoadError,
    setAttendancePrecheckReadyMonth,
    setAttendanceDecisionResult,
    setAttendanceCloseResult,
    setAttendanceFilterMonth: (month) => { currentAttendanceMonthRef.current = month; setAttendanceFilterMonth(month); },
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
    refreshHourlyPaidLeave,
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
    handlePayrollBatchPreview,
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
    onRefreshPayroll: refreshPayrollSection,
    onEmployeePayrollViewed: (id, revision) => setEmployeePortal((current) => ({ ...current,
      payroll: current.payroll.map((row) => row.id === id && (row.revision ?? 1) === revision ? { ...row, viewed: true } : row),
    })),
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
  const attendanceLoadOptions = useMemo<AttendanceSectionLoadOptions>(() => ({
    targetMonth: attendanceFilterMonth,
    employeeCode: attendanceFilterEmployeeCode,
    departmentName: attendanceFilterDepartmentName,
    approvalStatus: attendanceApprovalStatus,
    eventFrom: attendanceEventFrom,
    eventTo: attendanceEventTo,
    errorCode: attendanceErrorCode,
    errorHandlingStatus: attendanceErrorHandlingStatus,
    monthCloseApprovalStatus: attendanceMonthCloseApprovalStatus,
    monthCloseStatus: attendanceMonthCloseStatusFilter,
  }), [
    attendanceApprovalStatus,
    attendanceErrorCode,
    attendanceErrorHandlingStatus,
    attendanceEventFrom,
    attendanceEventTo,
    attendanceFilterDepartmentName,
    attendanceFilterEmployeeCode,
    attendanceFilterMonth,
    attendanceMonthCloseApprovalStatus,
    attendanceMonthCloseStatusFilter,
  ]);
  function handleAttendanceFilterMonthChange(value: string) {
    currentAttendanceMonthRef.current = value;
    attendanceLoadSequenceRef.current += 1;
    setAttendanceIsLoading(false);
    setAttendanceFilterMonth(value);
    setAttendancePrecheckReadyMonth(null);
    const range = monthDateRangeValue(value);
    setAttendanceEventFrom(range.from);
    setAttendanceEventTo(range.to);
  }

  const loadAdminSection = useCallback(async (section: AdminSectionKey, options: AttendanceSectionLoadOptions) => {
    const isAttendance = section === "attendance";
    if (!isAttendance && inFlightAdminSectionsRef.current.has(section)) return;
    const sequence = isAttendance ? ++attendanceLoadSequenceRef.current : 0;
    const isCurrent = () => sectionAudienceRef.current === "ADMIN" && (!isAttendance ||
      sequence === attendanceLoadSequenceRef.current && options.targetMonth === currentAttendanceMonthRef.current);
    if (!isAttendance) inFlightAdminSectionsRef.current.add(section);
    pendingLoadCountRef.current += 1;
    setIsSectionLoading(true);
    if (isAttendance) {
      setAttendanceLoadError("");
      setAttendancePrecheckReadyMonth(null);
      setAttendanceIsLoading(true);
    }
    try {
      const partialDashboard = await loadAdminSectionData(section, options);
      if (!isCurrent()) return;
      startTransition(() => {
        setDashboard((current) => isCurrent() ? { ...current, ...partialDashboard } : current);
        setLoadedAdminSections((current) => isCurrent() ? { ...current, [section]: true } : current);
      });
      setErrorMessage("");
      if (isAttendance && hasAvailableMonthClosePrecheck(partialDashboard.attendanceMonthClosePrecheck, options.targetMonth ?? "")) {
        setAttendancePrecheckReadyMonth(options.targetMonth ?? null);
      }
    } catch (error) {
      if (!isCurrent()) return;
      const message = error instanceof Error ? error.message : `${sectionLabels[section]}の読込に失敗しました。`;
      setErrorMessage(message);
      if (isAttendance) {
        setAttendanceLoadError(message);
        setAttendancePrecheckReadyMonth(null);
      }
    } finally {
      if (!isAttendance) inFlightAdminSectionsRef.current.delete(section);
      if (isAttendance && sequence === attendanceLoadSequenceRef.current) setAttendanceIsLoading(false);
      pendingLoadCountRef.current = Math.max(0, pendingLoadCountRef.current - 1);
      setIsSectionLoading(pendingLoadCountRef.current > 0);
    }
  }, [startTransition]);

  async function refreshPayrollSection() {
    if (inFlightAdminSectionsRef.current.has("payroll")) {
      return false;
    }

    inFlightAdminSectionsRef.current.add("payroll");
    pendingLoadCountRef.current += 1;
    setIsSectionLoading(true);
    try {
      const partialDashboard = await loadAdminSectionData("payroll");
      startTransition(() => {
        setDashboard((current) => ({ ...current, ...partialDashboard }));
        setLoadedAdminSections((current) => ({ ...current, payroll: true }));
      });
      setErrorMessage("");
      return true;
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "給与一覧の再取得に失敗しました。");
      return false;
    } finally {
      inFlightAdminSectionsRef.current.delete("payroll");
      pendingLoadCountRef.current = Math.max(0, pendingLoadCountRef.current - 1);
      setIsSectionLoading(pendingLoadCountRef.current > 0);
    }
  }

  async function refreshCurrentView() {
    if (currentAudience !== "ADMIN") {
      pendingLoadCountRef.current += 1;
      setIsSectionLoading(true);
      try {
        await refresh();
      } catch (error) {
        setErrorMessage(error instanceof Error ? error.message : "最新情報の読込に失敗しました。");
      } finally {
        pendingLoadCountRef.current = Math.max(0, pendingLoadCountRef.current - 1);
        setIsSectionLoading(pendingLoadCountRef.current > 0);
      }
      return;
    }

    await loadAdminSection(activeSection, attendanceLoadOptions);
  }
  const retryAttendanceLoad = useCallback(
    async () => loadAdminSection("attendance", attendanceLoadOptions),
    [attendanceLoadOptions, loadAdminSection],
  );
  const handleRefresh = () => void refreshCurrentView();
  const canUseEmployeePortal = Boolean(currentUser?.isAdmin && currentUser.canUseEmployeePortal);
  const isDataPending = isPending || isSectionLoading;

  useEffect(() => {
    window.sessionStorage.setItem("staffhub.activeSection", activeSection);
  }, [activeSection]);

  useEffect(() => {
    window.sessionStorage.setItem("staffhub.activeSubSections", JSON.stringify(activeSubSectionBySection));
  }, [activeSubSectionBySection]);

  useEffect(() => {
    window.addEventListener("staffhub:unauthorized", handleSessionExpired);
    return () => window.removeEventListener("staffhub:unauthorized", handleSessionExpired);
  }, [handleSessionExpired]);

  useEffect(() => {
    if (currentAudience !== "ADMIN" || loadedAdminSections[activeSection]) {
      return;
    }

    void loadAdminSection(activeSection, attendanceLoadOptions);
  }, [activeSection, attendanceLoadOptions, currentAudience, loadAdminSection, loadedAdminSections]);

  function handlePortalModeChange(mode: AuthAudience) {
    if (!canUseEmployeePortal || mode === currentAudience) {
      return;
    }

    if (hasUnsavedEmployeeEdit) {
      setPendingDiscardAction(() => () => {
        setErrorMessage("");
        setCurrentAudience(mode);
        startTransition(() => {
          void bootstrap(mode).catch(() => undefined);
        });
      });
      return;
    }

    setErrorMessage("");
    setCurrentAudience(mode);
    startTransition(() => {
      void bootstrap(mode).catch(() => undefined);
    });
  }

  function handleAdminSectionChange(section: string, targetId?: string) {
    if (!(section in sectionLabels)) {
      return;
    }

    const nextSection = section as AdminSectionKey;
    if (nextSection !== "employees" && hasUnsavedEmployeeEdit) {
      setPendingDiscardAction(() => () => applyAdminSectionChange(nextSection, targetId));
      return;
    }
    applyAdminSectionChange(nextSection, targetId);
  }

  function applyAdminSectionChange(nextSection: AdminSectionKey, targetId?: string) {
    const nextSubNavItems = sectionSubNavItems[nextSection] ?? [];
    if (nextSection === "reports") {
      setReportsMenuResetKey((current) => current + 1);
    }
    setActiveSection(nextSection);
    setActiveSubSectionBySection((current) => {
      const requestedTarget = targetId ?? (nextSection !== activeSection ? current[nextSection] : undefined);
      const selected = nextSubNavItems.find((item) => item.targetId === requestedTarget) ?? nextSubNavItems[0];
      return { ...current, [nextSection]: selected?.targetId ?? "" };
    });
  }

  function handleLogoutWithUnsavedEditCheck() {
    if (hasUnsavedEmployeeEdit) {
      setIsLogoutConfirmOpen(true);
      return;
    }

    handleLogout();
  }

  function handleSubNavChange(targetId: string) {
    setActiveSubSectionBySection((current) => ({
      ...current,
      [activeSection]: targetId,
    }));
  }

  function handleDashboardNavigation(section: "attendance" | "leave", targetId: string) {
    handleAdminSectionChange(section, targetId);
  }

  function handleAttendanceMonthSelection(targetMonth: string) {
    if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(targetMonth) || targetMonth < "1000-01" || targetMonth === attendanceFilterMonth) return;
    handleAttendanceFilterMonthChange(targetMonth);
    setAttendanceDecisionResult("");
    setAttendanceCloseResult("");
    if (loadedAdminSections.attendance) {
      const range = monthDateRangeValue(targetMonth);
      void loadAdminSection("attendance", { ...attendanceLoadOptions, targetMonth, eventFrom: range.from, eventTo: range.to });
    }
  }

  function handleReportMonthCloseNavigation(targetMonth: string) {
    if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(targetMonth)) return;
    handleAttendanceFilterMonthChange(targetMonth);
    setAttendanceFilterEmployeeCode("");
    setAttendanceFilterDepartmentName("");
    setAttendanceApprovalStatus("");
    setAttendanceMonthCloseApprovalStatus("");
    setAttendanceMonthCloseStatusFilter("");
    setActiveSection("attendance");
    setActiveSubSectionBySection((current) => ({ ...current, attendance: "attendance-close" }));
    // Unloaded sections are fetched by the section effect. Previously loaded sections need an explicit reload.
    if (loadedAdminSections.attendance) {
      const range = monthDateRangeValue(targetMonth);
      void loadAdminSection("attendance", { ...attendanceLoadOptions, targetMonth, employeeCode: "", departmentName: "", approvalStatus: "", monthCloseApprovalStatus: "", monthCloseStatus: "", eventFrom: range.from, eventTo: range.to });
    }
  }

  const {
    handleEmployeeAttendanceMonthChange,
    handleEmployeeAttendanceDailyEditRequestCreate,
    handleEmployeeAttendanceDailyEditRequestCancel,
    handleEmployeeRequestStatusReload,
    handleEmployeeLeaveRequestCancel,
    handleEmployeeLeaveRequestCreate,
    handleEmployeeUpdate,
  } = useEmployeePortalActions({
    getAttendanceTargetMonth: getEmployeeAttendanceMonth,
    onAttendanceTargetMonthChange: (month) => { employeeAttendanceMonthRef.current = month; },
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
    setEmployeePortal,
    currentUser,
    currentAudience,
    currentSubNavId,
    handleDashboardNavigation,
    handleReportMonthCloseNavigation,
    isPending: isDataPending,
    errorMessage,
    attendanceLoadError,
    attendanceIsLoading: attendanceIsLoading || isPending,
    attendanceMonthDataFresh,
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
    onAttendanceFilterMonthChange: handleAttendanceMonthSelection,
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
    handleLogout: handleLogoutWithUnsavedEditCheck,
    handlePortalModeChange,
    handleEmployeeImport,
    handleTemplateDownload,
    handleEmployeeUpdate,
    setHasUnsavedEmployeeEdit,
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
    retryAttendanceLoad,
    refreshHourlyPaidLeave,
    applyWorkProcedureFilters,
    resetWorkProcedureFilters,
    handleWorkProcedureDecision,
    handleBulkWorkProcedureDecision,
    handleGrantPaidLeave,
    handleAdjustPaidLeave,
    handleCreateNotice,
    handlePayrollDefinitionSave,
    handlePayrollBatchPreview,
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
    loadAuditPage,
    handleEmployeeRequestStatusReload,
    handleEmployeeLeaveRequestCancel,
    handleEmployeeLeaveRequestCreate,
    handleEmployeeAttendanceMonthChange,
    handleEmployeeAttendanceDailyEditRequestCreate,
    handleEmployeeAttendanceDailyEditRequestCancel,
    handleLoadEmployeePayrollDetail,
    handleNotificationRead,
  });

  const adminSections = {
    dashboard: <DashboardOverviewSection {...dashboardOverviewSectionProps} />,
    employees: <EmployeesSection {...employeesSectionProps} />,
    cards: <CardsSection {...cardsSectionProps} />,
    attendance: <AttendanceSection {...attendanceSectionProps} onAttentionTotalChange={updateAttentionTotal} />,
    leave: <LeaveSection {...leaveSectionProps} />,
    notices: <NoticesSection {...noticesSectionProps} />,
    payroll: <PayrollSection {...payrollSectionProps} onPanelChange={handleSubNavChange} />,
    harmosMigration: (
      <HarmosMigrationSection
        data={{ activePanel: currentSubNavId, importHistory: dashboard.importHistory }}
        actions={{ onRefresh: handleRefresh, onFileHistoryDownload: downloadFileHistory }}
      />
    ),
    reports: <ReportsSection {...reportsSectionProps} menuResetKey={reportsMenuResetKey} />,
    system: <SystemSection {...systemSectionProps} />,
    audit: <AuditSection {...auditSectionProps} />,
  } satisfies Record<AdminSectionKey, ReactNode>;
  const navBadges = {
    attendance: dashboard.reportsHub.pendingAttendanceApprovalCount,
    ...(dashboard.reportsHub.checkedAt ? {
      leave: dashboard.reportsHub.pendingLeaveCount,
    } : {}),
  };

  return (
    <>
      {downloadMessage ? <div role="status" className="download-status">{downloadMessage}<button type="button" onClick={() => setDownloadMessage("")}>閉じる</button></div> : null}
    <AdminDashboardView
      isAuthenticated={isAuthenticated}
      recovery={bootstrapFailure}
      checkingSession={checkingSession}
      onRetrySession={() => { if (bootstrapFailure) void bootstrap(bootstrapFailure.audience).catch(() => undefined); }}
      onLogout={handleLogout}
      loginProps={{
        loginId,
        password,
        authMessage,
        onLoginIdChange: setLoginId,
        onPasswordChange: setPassword,
        onLogin: handleLogin,
        onInitialPasswordChange: handleInitialPasswordChange,
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
        isPending: isDataPending,
        errorMessage,
        navBadges,
        onModeChange: handlePortalModeChange,
        onActiveSectionChange: handleAdminSectionChange,
        onSubNavChange: handleSubNavChange,
        onRefresh: handleRefresh,
        onLogout: handleLogoutWithUnsavedEditCheck,
      }}
      adminSections={adminSections}
      activeSection={activeSection}
    />
    <ConfirmDialog
      open={isLogoutConfirmOpen}
      title="保存していない変更があります"
      message="ログアウトすると、職員情報の保存していない変更は破棄されます。"
      confirmLabel="変更を破棄してログアウト"
      onCancel={() => setIsLogoutConfirmOpen(false)}
      onConfirm={() => { setIsLogoutConfirmOpen(false); handleLogout(); }}
    />
    <ConfirmDialog
      open={pendingDiscardAction !== null}
      title="保存していない変更があります"
      message="画面を移ると、職員情報の保存していない変更は破棄されます。"
      confirmLabel="変更を破棄して移動"
      onCancel={() => setPendingDiscardAction(null)}
      onConfirm={() => {
        const action = pendingDiscardAction;
        setPendingDiscardAction(null);
        action?.();
      }}
    />
    </>
  );
}
