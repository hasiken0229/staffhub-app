import type { DashboardData } from "@/types";

type DailyAttendanceGraphProps = {
  row: DashboardData["dailyGrid"][number];
};

export function DailyAttendanceGraph(props: DailyAttendanceGraphProps) {
  const segments = props.row.graphSegments ?? [];

  if (segments.length === 0) {
    return <span>-</span>;
  }

  return (
    <div className="mini-graph" aria-label="勤務グラフ">
      {segments.map((segment, index) => {
        if (segment.kind === "WORK" && segment.startHour != null && segment.endHour != null) {
          const start = segment.startHour * 60 + (segment.startMinute ?? 0);
          const end = segment.endHour * 60 + (segment.endMinute ?? 0);
          const width = Math.max(4, ((end - start) / (24 * 60)) * 100);
          const left = Math.max(0, (start / (24 * 60)) * 100);

          return (
            <span
              key={`${segment.kind}-${index}`}
              className="mini-graph-bar is-work"
              style={{ left: `${left}%`, width: `${width}%` }}
              title={`勤務 ${segment.startHour}:${String(segment.startMinute ?? 0).padStart(2, "0")} - ${segment.endHour}:${String(segment.endMinute ?? 0).padStart(2, "0")}`}
            />
          );
        }

        const className =
          segment.kind === "PAID_LEAVE"
            ? "mini-graph-chip is-paid"
            : segment.kind === "SPECIAL_LEAVE"
              ? "mini-graph-chip is-special"
              : "mini-graph-chip is-absence";

        const label =
          segment.kind === "PAID_LEAVE"
            ? `有給 ${segment.unit ?? 0}日`
            : segment.kind === "SPECIAL_LEAVE"
              ? "特休"
              : "欠勤";

        return (
          <span key={`${segment.kind}-${index}`} className={className}>
            {label}
          </span>
        );
      })}
    </div>
  );
}
