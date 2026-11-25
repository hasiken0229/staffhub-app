export function punchTime(value?: string | null, day?: string) {
  const time = value?.match(/(?:T| |^)(\d{2}:\d{2})/)?.[1];
  if (!time) return "";
  return day && value && /^\d{4}-\d\d-\d\d/.test(value) && value.slice(0, 10) > day ? "翌日 " + time : time;
}

export function EmployeePunchTime({ raw, calculated, day }: { raw?: string | null; calculated?: string | null; day: string }) {
  const original = punchTime(raw, day);
  const adjusted = punchTime(calculated, day);
  return <span className="attendance-two-line-time"><span>{original ? "実打刻 " + original : "打刻なし"}</span>{adjusted && adjusted !== original ? <small>勤務計算 {adjusted}</small> : null}</span>;
}
