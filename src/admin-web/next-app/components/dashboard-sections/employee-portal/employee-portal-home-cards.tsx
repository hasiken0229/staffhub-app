import { employeeDayStateLabel } from "@/lib/employee-attendance-state";
import type { EmployeePortalTab } from "../employee-portal-section";
import type { EmployeePortalSectionProps } from "./employee-portal-types";
import { EmployeePunchTime, punchTime } from "./employee-punch-time";

type Props = {
  data: EmployeePortalSectionProps["data"];
  onTabChange: (tab: EmployeePortalTab) => void;
  freshness: "fresh" | "loading" | "offline" | "error";
  onRefresh: () => void;
};

export function EmployeePortalHomeCards({ data, onTabChange, freshness, onRefresh }: Props) {
  const { todayAttendance: today, checkedAt } = data.employeePortal.home;
  const mostRecent = today?.rawClockOutAt ? "退勤 " + punchTime(today.rawClockOutAt, today.targetDate)
    : today?.rawClockInAt ? "出勤 " + punchTime(today.rawClockInAt, today.targetDate) : "まだありません";
  const stamp = checkedAt ? checkedAt.slice(0, 10) + " " + punchTime(checkedAt) : "未取得";
  const status = { fresh: "最終確認", loading: "更新中・前回確認", offline: "オフライン・前回確認", error: "更新できませんでした・前回確認" }[freshness];
  return <section id="employee-portal-home" className="panel" aria-label="職員ホーム">
    <p>出勤・退勤は園の打刻端末にカードをかざしてください。</p>
    <button type="button" className="portal-home-card portal-home-card-primary" onClick={() => onTabChange("today")}>
      <span className="portal-card-label">今日の勤怠 {today?.targetDate}</span>
      <strong>{today ? employeeDayStateLabel(today) : "未取得"}</strong>
      <span>直近の実打刻　{mostRecent}</span>
      {today ? <span className="portal-card-detail">出勤 <EmployeePunchTime raw={today.rawClockInAt} calculated={today.clockInAt} day={today.targetDate} />　退勤 <EmployeePunchTime raw={today.rawClockOutAt} calculated={today.clockOutAt} day={today.targetDate} /></span> : null}
      <span>今日の詳細を見る</span>
    </button>
    <div className="button-row"><span role="status">{status} {stamp}</span><button type="button" className="secondary" onClick={onRefresh} disabled={freshness === "loading"}>今日の勤怠を更新</button></div>
  </section>;
}

export function EmployeeAttention({ data, onTabChange }: Pick<Props, "data" | "onTabChange">) {
  const { returnedRequestCount = 0, unreadNotificationCount = 0 } = data.employeePortal.home;
  if (!returnedRequestCount && !unreadNotificationCount) return null;
  return <section className="portal-home-list" aria-label="確認が必要な項目">
    {returnedRequestCount > 0 ? <button type="button" className="portal-home-card" onClick={() => onTabChange("requests")}>差戻しの申請 {returnedRequestCount}件を確認</button> : null}
    {unreadNotificationCount > 0 ? <button type="button" className="portal-home-card" onClick={() => onTabChange("notices")}>未読通知 {unreadNotificationCount}件を確認</button> : null}
  </section>;
}
