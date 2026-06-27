import {
  formatApprovalStatus,
  formatDateOnly,
  formatDateTime,
  formatImportType,
  formatLeaveLedgerEntryType,
  formatMonthDay,
  formatPayrollBatchStatus,
  formatTimeOnly,
} from "@/lib/api";
import type { PayrollSectionProps } from "@/components/dashboard-sections/payroll/payroll-section-types";
import type { ReportsSectionProps, UseDashboardSectionPropsParams } from "@/hooks/dashboard-section-props/types";

export function buildPayrollSectionProps(params: UseDashboardSectionPropsParams): PayrollSectionProps {
  return {
    data: {
      payrollTypeLabel: params.payrollTypeLabel,
      filteredPayrollDefinitions: params.filteredPayrollDefinitions,
      filteredPayrollBatches: params.filteredPayrollBatches,
      filteredPayrollStatements: params.filteredPayrollStatements,
      filteredPayrollHistory: params.filteredPayrollHistory,
      selectedPayrollBatchDetail: params.selectedPayrollBatchDetail,
      selectedAdminPayrollDetail: params.selectedAdminPayrollDetail,
      activePanel: params.currentSubNavId,
    },
    form: {
      payrollStatementType: params.payrollStatementType,
      payrollDefinitionId: params.payrollDefinitionId,
      payrollDefinitionName: params.payrollDefinitionName,
      payrollDefinitionActive: params.payrollDefinitionActive,
      payrollDefinitionResult: params.payrollDefinitionResult,
      payrollBatchResult: params.payrollBatchResult,
      payrollResult: params.payrollResult,
      payrollTargetYearMonth: params.payrollTargetYearMonth,
      payrollPeriodStartOn: params.payrollPeriodStartOn,
      payrollPeriodEndOn: params.payrollPeriodEndOn,
      payrollPayDate: params.payrollPayDate,
      payrollPublishDate: params.payrollPublishDate,
      payrollRemarks: params.payrollRemarks,
      payrollBatchTargetMonthFilter: params.payrollBatchTargetMonthFilter,
      payrollBatchEmployeeCodeFilter: params.payrollBatchEmployeeCodeFilter,
      payrollBatchEmployeeNameFilter: params.payrollBatchEmployeeNameFilter,
    },
    actions: {
      onPayrollStatementTypeChange: params.setPayrollStatementType,
      onPayrollDefinitionIdChange: params.setPayrollDefinitionId,
      onPayrollDefinitionNameChange: params.setPayrollDefinitionName,
      onPayrollDefinitionActiveChange: params.setPayrollDefinitionActive,
      onPayrollTargetYearMonthChange: params.setPayrollTargetYearMonth,
      onPayrollPeriodStartOnChange: params.setPayrollPeriodStartOn,
      onPayrollPeriodEndOnChange: params.setPayrollPeriodEndOn,
      onPayrollPayDateChange: params.setPayrollPayDate,
      onPayrollPublishDateChange: params.setPayrollPublishDate,
      onPayrollRemarksChange: params.setPayrollRemarks,
      onPayrollBatchTargetMonthFilterChange: params.setPayrollBatchTargetMonthFilter,
      onPayrollBatchEmployeeCodeFilterChange: params.setPayrollBatchEmployeeCodeFilter,
      onPayrollBatchEmployeeNameFilterChange: params.setPayrollBatchEmployeeNameFilter,
      onPayrollDefinitionSelect: (definition) => {
        params.setPayrollDefinitionId(String(definition.id));
        params.setPayrollDefinitionName(definition.definitionName);
        params.setPayrollDefinitionActive(definition.isActive);
      },
      onPayrollDefinitionSave: params.handlePayrollDefinitionSave,
      onTemplateDownload: (kind) => params.handleTemplateDownload(kind),
      onPayrollBatchCreate: params.handlePayrollBatchCreate,
      onOpenPayrollBatchDetail: params.openPayrollBatchDetail,
      onSearchPayrollBatchDetail: params.searchPayrollBatchDetail,
      onDeletePayrollBatch: params.handleDeletePayrollBatch,
      onExportPayrollBatch: params.handleExportPayrollBatch,
      onLoadAdminPayrollDetail: params.handleLoadAdminPayrollDetail,
      onAdminPayrollDownload: params.handleAdminPayrollDownload,
      onPayrollDownload: params.handlePayrollDownload,
      onDeletePayrollStatement: params.handleDeletePayrollStatement,
      onFileHistoryDownload: params.handleFileHistoryDownload,
    },
    formatters: {
      formatDateOnly,
      formatDateTime,
      formatMonthDay,
      formatImportType,
      formatPayrollBatchStatus,
    },
  };
}

export function buildReportsSectionProps(params: UseDashboardSectionPropsParams): ReportsSectionProps {
  return {
    data: {
      dashboard: params.dashboard,
      employees: params.dashboard.employees,
      reportTodayAttendance: params.dashboard.reportTodayAttendance,
      reportAttendanceApprovals: params.dashboard.reportAttendanceApprovals,
      paidLeaveReport: params.dashboard.paidLeaveReport,
      reportFileHistory: params.reportFileHistory,
      reportResult: params.reportResult,
      activePanel: params.currentSubNavId,
    },
    filters: {
      reportMonth: params.reportMonth,
      reportFrom: params.reportFrom,
      reportTo: params.reportTo,
      reportEmployeeId: params.reportEmployeeId,
    },
    actions: {
      onReportMonthChange: params.setReportMonth,
      onReportFromChange: params.setReportFrom,
      onReportToChange: params.setReportTo,
      onReportEmployeeIdChange: params.setReportEmployeeId,
      onDownloadMonthlyAttendanceCsv: params.handleMonthlyAttendanceCsvDownload,
      onDownloadMonthlyPayrollCsv: params.handleMonthlyPayrollCsvDownload,
      onDownloadDailyAttendanceCsv: params.handleDailyAttendanceCsvDownload,
      onDownloadDailyAttendancePdf: params.handleDailyAttendancePdfDownload,
      onDownloadMonthlyWorksPdf: params.handleMonthlyWorksPdfDownload,
      onFileHistoryDownload: params.handleFileHistoryDownload,
    },
    formatters: {
      formatDateOnly,
      formatDateTime,
      formatTimeOnly,
      formatApprovalStatus,
      formatImportType,
      formatLeaveLedgerEntryType,
    },
  };
}
