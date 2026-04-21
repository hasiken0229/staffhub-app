import {
  formatApprovalStatus,
  formatDateOnly,
  formatDateTime,
  formatEmployeeStatus,
  formatEmploymentType,
  formatNoticeType,
  formatTimeOnly,
  formatWorkMinutes,
} from "@/lib/api";
import type {
  AuditSectionProps,
  CardsSectionProps,
  DashboardOverviewSectionProps,
  EmployeesSectionProps,
  NoticesSectionProps,
  SystemSectionProps,
  UseDashboardSectionPropsParams,
} from "@/hooks/dashboard-section-props/types";

export function buildDashboardOverviewSectionProps(params: UseDashboardSectionPropsParams): DashboardOverviewSectionProps {
  return {
    data: {
      dashboard: params.dashboard,
      activePanel: params.currentSubNavId,
    },
    formatters: {
      formatDateOnly,
      formatTimeOnly,
      formatWorkMinutes,
      formatApprovalStatus,
    },
  };
}

export function buildEmployeesSectionProps(params: UseDashboardSectionPropsParams): EmployeesSectionProps {
  return {
    data: {
      employees: params.dashboard.employees,
      activePanel: params.currentSubNavId,
    },
    form: {
      employeeImportResult: params.employeeImportResult,
    },
    actions: {
      onEmployeeImport: params.handleEmployeeImport,
      onTemplateDownload: () => params.handleTemplateDownload("employees"),
      onEmployeeUpdate: params.handleEmployeeUpdate,
    },
    formatters: {
      formatEmploymentType,
      formatEmployeeStatus,
    },
  };
}

export function buildCardsSectionProps(params: UseDashboardSectionPropsParams): CardsSectionProps {
  return {
    data: {
      cards: params.dashboard.cards,
      employees: params.dashboard.employees,
      activePanel: params.currentSubNavId,
    },
    form: {
      assignEmployeeId: params.assignEmployeeId,
      assignCardUid: params.assignCardUid,
      assignResult: params.assignResult,
    },
    actions: {
      onAssignEmployeeIdChange: params.setAssignEmployeeId,
      onAssignCardUidChange: params.setAssignCardUid,
      onAssignCard: params.handleAssignCard,
    },
    formatters: {
      formatDateTime,
    },
  };
}

export function buildNoticesSectionProps(params: UseDashboardSectionPropsParams): NoticesSectionProps {
  return {
    data: {
      notices: params.dashboard.notices,
      activePanel: params.currentSubNavId,
    },
    form: {
      noticeType: params.noticeType,
      noticeTitle: params.noticeTitle,
      noticeBody: params.noticeBody,
      noticeStartAt: params.noticeStartAt,
      noticeEndAt: params.noticeEndAt,
      noticeResult: params.noticeResult,
    },
    actions: {
      onNoticeTypeChange: params.setNoticeType,
      onNoticeTitleChange: params.setNoticeTitle,
      onNoticeBodyChange: params.setNoticeBody,
      onNoticeStartAtChange: params.setNoticeStartAt,
      onNoticeEndAtChange: params.setNoticeEndAt,
      onCreateNotice: params.handleCreateNotice,
    },
    formatters: {
      formatNoticeType,
      formatDateTime,
    },
  };
}

export function buildSystemSectionProps(params: UseDashboardSectionPropsParams): SystemSectionProps {
  return {
    data: {
      dashboard: params.dashboard,
      systemResult: params.systemResult,
      activePanel: params.currentSubNavId,
    },
    actions: {
      onSystemForm: params.handleSystemForm,
    },
    formatters: {
      formatEmploymentType,
    },
  };
}

export function buildAuditSectionProps(params: UseDashboardSectionPropsParams): AuditSectionProps {
  return {
    data: {
      auditLogs: params.dashboard.auditLogs,
      activePanel: params.currentSubNavId,
    },
    filters: {
      auditActorFilter: params.auditActorFilter,
      auditActionFilter: params.auditActionFilter,
      auditFrom: params.auditFrom,
      auditTo: params.auditTo,
    },
    actions: {
      onAuditActorFilterChange: params.setAuditActorFilter,
      onAuditActionFilterChange: params.setAuditActionFilter,
      onAuditFromChange: params.setAuditFrom,
      onAuditToChange: params.setAuditTo,
      onApplyAuditFilters: params.applyAuditFilters,
      onResetAuditFilters: params.resetAuditFilters,
    },
    formatters: {
      formatDateTime,
    },
  };
}
