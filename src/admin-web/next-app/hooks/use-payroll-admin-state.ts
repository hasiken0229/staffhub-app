import { useState } from "react";
import {
  currentDateValue,
  currentMonthEndValue,
  currentMonthStartValue,
  currentMonthValue,
  nextMonthDateValue,
} from "@/lib/date-defaults";
import type { PayrollImportBatchDetail, PayrollStatementDetail } from "@/types";

export function usePayrollAdminState() {
  const [payrollResult, setPayrollResult] = useState("");
  const [payrollDefinitionResult, setPayrollDefinitionResult] = useState("");
  const [payrollBatchResult, setPayrollBatchResult] = useState("");
  const [payrollStatementType, setPayrollStatementType] = useState<"PAYROLL" | "BONUS">("PAYROLL");
  const [payrollDefinitionId, setPayrollDefinitionId] = useState("");
  const [payrollDefinitionName, setPayrollDefinitionName] = useState("");
  const [payrollDefinitionActive, setPayrollDefinitionActive] = useState(true);
  const [payrollTargetYearMonth, setPayrollTargetYearMonth] = useState(currentMonthValue);
  const [payrollPeriodStartOn, setPayrollPeriodStartOn] = useState(currentMonthStartValue);
  const [payrollPeriodEndOn, setPayrollPeriodEndOn] = useState(currentMonthEndValue);
  const [payrollPayDate, setPayrollPayDate] = useState(() => nextMonthDateValue(25));
  const [payrollPublishDate, setPayrollPublishDate] = useState(currentDateValue);
  const [payrollRemarks, setPayrollRemarks] = useState("");
  const [payrollBatchTargetMonthFilter, setPayrollBatchTargetMonthFilter] = useState("");
  const [selectedPayrollBatchId, setSelectedPayrollBatchId] = useState<number | null>(null);
  const [selectedPayrollBatchDetail, setSelectedPayrollBatchDetail] = useState<PayrollImportBatchDetail | null>(null);
  const [payrollBatchEmployeeCodeFilter, setPayrollBatchEmployeeCodeFilter] = useState("");
  const [payrollBatchEmployeeNameFilter, setPayrollBatchEmployeeNameFilter] = useState("");
  const [selectedAdminPayrollDetail, setSelectedAdminPayrollDetail] = useState<PayrollStatementDetail | null>(null);
  const [selectedEmployeePayrollDetail, setSelectedEmployeePayrollDetail] = useState<PayrollStatementDetail | null>(null);

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
