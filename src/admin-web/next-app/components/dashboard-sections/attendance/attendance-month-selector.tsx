type Props = {
  targetMonth: string;
  isLoading: boolean;
  disabled?: boolean;
  onMonthChange: (value: string) => void;
  onReload: () => Promise<void>;
};

function adjacentMonth(value: string, offset: number) {
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(value)) return "";
  const total = Number(value.slice(0, 4)) * 12 + Number(value.slice(5)) - 1 + offset;
  const year = Math.floor(total / 12);
  if (year < 1000 || year > 9999) return "";
  return String(year).padStart(4, "0") + "-" + String(total % 12 + 1).padStart(2, "0");
}

export function AttendanceMonthSelector({ targetMonth, isLoading, disabled, onMonthChange, onReload }: Props) {
  const previousMonth = adjacentMonth(targetMonth, -1);
  const nextMonth = adjacentMonth(targetMonth, 1);

  return (
    <section className="panel attendance-month-toolbar" aria-label="日次勤怠の対象月">
      <div className="attendance-month-controls">
        <button type="button" className="secondary" aria-label="前月を表示" disabled={disabled || !previousMonth} onClick={() => onMonthChange(previousMonth)}>
          前月
        </button>
        <label>
          対象月
          <input type="month" min="1000-01" max="9999-12" value={targetMonth} disabled={disabled} onChange={(event) => onMonthChange(event.target.value)} />
        </label>
        <button type="button" className="secondary" aria-label="翌月を表示" disabled={disabled || !nextMonth} onClick={() => onMonthChange(nextMonth)}>
          翌月
        </button>
      </div>
      <div className="button-row">
        <button type="button" className="secondary" disabled={disabled || isLoading} onClick={() => void onReload()}>
          {isLoading ? "読込中…" : "再表示"}
        </button>
      </div>
    </section>
  );
}
