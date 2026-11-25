import { buildQuery, fetchJson } from "@/lib/api/core";
import type { HourlyPaidLeaveBalance } from "@/types";

export type LeaveWorkdayPreview = { dates: string[]; excludedDates: string[]; unconfirmedDates: string[] };

export type EmployeeBalanceAtDate = {
  asOf: string;
  currentBalance: number;
  availableDays?: number;
  workdays?: LeaveWorkdayPreview;
  futureApprovedDays: number;
  hourlyManagementConfigured?: boolean;
  hourlyBalance?: HourlyPaidLeaveBalance | null;
};

export function loadEmployeeBalanceAtDate(asOf: string, through?: string) {
  return fetchJson<EmployeeBalanceAtDate>(`/api/leave/balance${buildQuery({ asOf, through })}`);
}
