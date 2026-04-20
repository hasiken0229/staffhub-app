import { useState } from "react";

export function useReportState() {
  const [reportMonth, setReportMonth] = useState("2026-03");
  const [reportFrom, setReportFrom] = useState("2026-03-01");
  const [reportTo, setReportTo] = useState("2026-03-31");
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
