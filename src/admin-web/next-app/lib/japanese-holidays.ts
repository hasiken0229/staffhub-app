import calendar from "@/lib/japanese-holidays.json";

const holidays = new Set(calendar.dates);
const names: Record<string, string> = calendar.names;

export function hasJapaneseHolidayCalendar(targetMonth: string) {
  const match = /^(\d{4})-(0[1-9]|1[0-2])$/.exec(targetMonth);
  return match !== null && Number(match[1]) >= calendar.firstYear && Number(match[1]) <= calendar.lastYear;
}

export function isJapanesePublicHoliday(value: string) {
  return holidays.has(value);
}

export function japaneseHolidayName(value: string) {
  return holidays.has(value) ? names[value] : undefined;
}
