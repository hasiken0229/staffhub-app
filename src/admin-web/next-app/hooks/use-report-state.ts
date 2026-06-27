import { useState } from "react";
import { currentMonthEndValue, currentMonthStartValue, currentMonthValue } from "@/lib/date-defaults";

export function useReportState() {
  const [reportMonth, setReportMonth] = useState(currentMonthValue);
  const [reportFrom, setReportFrom] = useState(currentMonthStartValue);
  const [reportTo, setReportTo] = useState(currentMonthEndValue);
  const [reportEmployeeId, setReportEmployeeId] = useState("1");
  const [reportResult, setReportResult] = useState("");

  return {
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
  };
}
