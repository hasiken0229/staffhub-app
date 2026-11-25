import { hasJapaneseHolidayCalendar, isJapanesePublicHoliday } from "@/lib/japanese-holidays";
export { hasJapaneseHolidayCalendar, isJapanesePublicHoliday } from "@/lib/japanese-holidays";

function parseDate(value: string) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
  const date = new Date(`${value}T00:00:00Z`);
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value ? date : null;
}

export function previousDateValue(value: string) {
  const date = parseDate(value);
  if (!date) return "";
  date.setUTCDate(date.getUTCDate() - 1);
  return date.toISOString().slice(0, 10);
}

export function previousBusinessDateValue(value: string) {
  const date = parseDate(value);
  if (!date) return "";
  while (hasJapaneseHolidayCalendar(date.toISOString().slice(0, 7))) {
    const dateValue = date.toISOString().slice(0, 10);
    if (date.getUTCDay() !== 0 && date.getUTCDay() !== 6 && !isJapanesePublicHoliday(dateValue)) {
      return dateValue;
    }
    date.setUTCDate(date.getUTCDate() - 1);
  }
  return "";
}

export function payrollDateDefaults(targetMonth: string) {
  const payDate = hasJapaneseHolidayCalendar(targetMonth) ? previousBusinessDateValue(`${targetMonth}-28`) : "";
  return { payDate, publishDate: previousDateValue(payDate) };
}
