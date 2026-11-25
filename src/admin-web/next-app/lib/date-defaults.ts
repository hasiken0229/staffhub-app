import { toDateValue, toMonthValue } from "@/lib/api/core";

function monthStart(date: Date) {
  return new Date(date.getFullYear(), date.getMonth(), 1);
}

function monthEnd(date: Date) {
  return new Date(date.getFullYear(), date.getMonth() + 1, 0);
}

function addMonths(date: Date, months: number) {
  return new Date(date.getFullYear(), date.getMonth() + months, date.getDate());
}

export function currentMonthValue() {
  return toMonthValue(new Date());
}

export function currentDateValue() {
  return toDateValue(new Date());
}

export function currentMonthStartValue() {
  return toDateValue(monthStart(new Date()));
}

export function currentMonthEndValue() {
  return toDateValue(monthEnd(new Date()));
}

export function monthDateRangeValue(value: string) {
  const match = /^(\d{4})-(\d{2})$/.exec(value);
  if (!match) {
    return { from: currentMonthStartValue(), to: currentMonthEndValue() };
  }

  const year = Number(match[1]);
  const month = Number(match[2]);
  if (month < 1 || month > 12) {
    return { from: currentMonthStartValue(), to: currentMonthEndValue() };
  }

  return {
    from: toDateValue(new Date(year, month - 1, 1)),
    to: toDateValue(new Date(year, month, 0)),
  };
}

export function nextMonthDateValue(day: number) {
  const base = addMonths(new Date(), 1);
  return toDateValue(new Date(base.getFullYear(), base.getMonth(), day));
}

export function fiscalYearStartValue() {
  const today = new Date();
  const year = today.getMonth() >= 3 ? today.getFullYear() : today.getFullYear() - 1;
  return `${year}-04-01`;
}

export function fiscalYearEndValue() {
  const today = new Date();
  const year = today.getMonth() >= 3 ? today.getFullYear() + 1 : today.getFullYear();
  return `${year}-03-31`;
}
