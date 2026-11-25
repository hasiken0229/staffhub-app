export type RegisterEntryKind = "GRANT" | "MIGRATION_OPENING" | "USE" | "ADJUST_PLUS" | "ADJUST_MINUS" | "EXPIRE" | "ATTENDANCE_CANDIDATE";
export type RegisterUnit = "DAY" | "HALF_DAY" | "HOUR";
export type RegisterEntry = {
  date: string;
  kind: RegisterEntryKind;
  unit: RegisterUnit | null;
  days: number | null;
  minutes: number | null;
  deltaDays: number | null;
  balanceAfterDays: number | null;
  status: "RECORDED" | "REFERENCE" | "FUTURE";
  evidence: string;
  sourceType: string;
  sourceId: number | null;
};
export type RegisterBaseDate = {
  date: string | null;
  recordedOn: string;
  kind: "GRANT" | "MIGRATION_OPENING";
  days: number;
  expiresOn: string | null;
  evidence: string | null;
};
type HourlyBalance = { annualMinutes: number; totalMinutes: number | null };
export type FiscalLeaveYear = {
  fiscalYear: number;
  openingDate: string;
  openingReason?: "NOT_EMPLOYED" | null;
  opening: { days: number | null; annualMinutes: number | null; totalMinutes: number | null };
  closingDate: string | null;
  closing: { days: number | null; annualMinutes: number | null; totalMinutes: number | null };
  status: string;
  message: string;
  entries: {
    date: string;
    kind: "OPENING" | "USE" | "GRANT" | "ADJUSTMENT" | "EXPIRE" | "BALANCE" | "CARRY";
    includedInOpening?: boolean;
    status: "RECORDED" | "REFERENCE" | "FUTURE";
    usedDays: number | null;
    usedMinutes: number | null;
    dayChange: number | null;
    minutesChange: number | null;
    daysAfter: number | null;
    annualMinutesAfter: number | null;
    totalMinutesAfter: number | null;
  }[];
};
export type PaidLeaveRegister = {
  employee: { id: number; code: string; name: string; status: string };
  from: string;
  to: string;
  openingBalanceAsOf: string;
  balanceAsOf: string;
  openingBalanceDays: number | null;
  closingBalanceDays: number | null;
  openingHourlyBalance: HourlyBalance | null;
  closingHourlyBalance: HourlyBalance | null;
  fiscalLedger: { years: FiscalLeaveYear[] };
  review: {
    status: "REVIEW_REQUIRED" | "NO_OPEN_DIFFERENCE" | "NOT_ESTABLISHED";
    message: string;
    asOf: string | null;
    officialDays: number | null;
    appDays: number | null;
    sourceFile: string | null;
    sourceRow: number | null;
  };
  warnings: { date: string; kind: "OVERLAPPING_LEDGER_DEDUCTIONS" | "RESTATED_GRANTS" | "MISSING_GRANT_HISTORY" | "MIGRATION_BALANCE_DIFFERENCE"; message: string }[];
  baseDates: RegisterBaseDate[];
  entries: RegisterEntry[];
  totals: { usedDays: number; usedMinutes: number; halfDayCount: number };
};

