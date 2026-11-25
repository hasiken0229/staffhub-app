import { useState } from "react";
import {
  currentMonthEndValue,
  currentMonthStartValue,
  currentMonthValue,
} from "@/lib/date-defaults";
import { payrollDateDefaults, previousDateValue } from "@/lib/payroll-dates";
import type { PayrollImportBatchDetail, PayrollStatementDetail } from "@/types";

export function usePayrollAdminState() {
  const [payrollResult, setPayrollResult] = useState("");
  const [payrollDefinitionResult, setPayrollDefinitionResult] = useState("");
  const [payrollBatchResult, setPayrollBatchResult] = useState("");
  const [payrollStatementType, setPayrollStatementType] = useState<"PAYROLL" | "BONUS">("PAYROLL");
  const [payrollDefinitionId, setPayrollDefinitionId] = useState("");
  const [payrollDefinitionName, setPayrollDefinitionName] = useState("");
  const [payrollDefinitionActive, setPayrollDefinitionActive] = useState(true);
  const [payrollTargetYearMonth, setPayrollTargetYearMonthValue] = useState(currentMonthValue);
  const [payrollPeriodStartOn, setPayrollPeriodStartOn] = useState(currentMonthStartValue);
  const [payrollPeriodEndOn, setPayrollPeriodEndOn] = useState(currentMonthEndValue);
  const [payrollPayDate, setPayrollPayDateValue] = useState(() => payrollDateDefaults(currentMonthValue()).payDate);
  const [payrollPublishDate, setPayrollPublishDate] = useState(() => payrollDateDefaults(currentMonthValue()).publishDate);
  const [payrollRemarks, setPayrollRemarks] = useState("");
  const [payrollBatchTargetMonthFilter, setPayrollBatchTargetMonthFilter] = useState("");
  const [selectedPayrollBatchId, setSelectedPayrollBatchId] = useState<number | null>(null);
  const [selectedPayrollBatchDetail, setSelectedPayrollBatchDetail] = useState<PayrollImportBatchDetail | null>(null);
  const [payrollBatchEmployeeCodeFilter, setPayrollBatchEmployeeCodeFilter] = useState("");
  const [payrollBatchEmployeeNameFilter, setPayrollBatchEmployeeNameFilter] = useState("");
  const [selectedAdminPayrollDetail, setSelectedAdminPayrollDetail] = useState<PayrollStatementDetail | null>(null);
  const [selectedEmployeePayrollDetail, setSelectedEmployeePayrollDetail] = useState<PayrollStatementDetail | null>(null);

  function setPayrollTargetYearMonth(value: string) {
    setPayrollTargetYearMonthValue(value);
    if (payrollStatementType === "PAYROLL") {
      const dates = payrollDateDefaults(value);
      setPayrollPayDateValue(dates.payDate);
      setPayrollPublishDate(dates.publishDate);
    }
  }

  function setPayrollPayDate(value: string) {
    setPayrollPayDateValue(value);
    if (payrollStatementType === "PAYROLL") setPayrollPublishDate(previousDateValue(value));
  }

  return {
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
  };
}
