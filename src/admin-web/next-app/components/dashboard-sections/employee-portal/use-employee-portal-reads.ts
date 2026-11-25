import { useCallback, useEffect, useRef, useState } from "react";
import type { EmployeePortalData } from "@/types";
import { loadEmployeeAttention, loadEmployeeLeaveOptions, loadEmployeeLeaveRequests, loadEmployeeNotifications, loadEmployeePayrollStatements, loadEmployeeLeaveLedger, loadEmployeeToday } from "@/lib/api/dashboard";
import { loadEmployeeAttendanceDaily, loadEmployeeAttendanceDailyEditRequests } from "@/lib/api/attendance";
import { markNotificationRead } from "@/lib/api/leave";
import type { EmployeePortalTab } from "../employee-portal-section";
import type { EmployeePortalSectionProps } from "./employee-portal-types";

type Update = (current: EmployeePortalData) => EmployeePortalData;

async function readSection(tab: EmployeePortalTab, month: string, loadMonth: NonNullable<EmployeePortalSectionProps["actions"]["onAttendanceMonthChange"]> = loadEmployeeAttendanceDaily): Promise<Update> {
  switch (tab) {
    case "home": {
      const { returnedRequestCount, unreadNotificationCount } = await loadEmployeeAttention();
      return (data) => ({ ...data, home: { ...data.home, returnedRequestCount, unreadNotificationCount } });
    }
    case "leave": {
      const { pendingLeaveCount, paidLeaveBalance, hourlyPaidLeaveBalance, leaveTypes } = await loadEmployeeLeaveOptions();
      return (data) => ({ ...data, home: { ...data.home, pendingLeaveCount, paidLeaveBalance, hourlyPaidLeaveBalance, leaveTypes } });
    }
    case "daily-edit": {
      const [attendanceDaily, attendanceDailyEditRequests] = await Promise.all([loadMonth(month), loadEmployeeAttendanceDailyEditRequests()]);
      return (data) => ({ ...data, attendanceTargetMonth: month, attendanceDaily, attendanceDailyEditRequests });
    }
    case "requests": {
      const [leaveRequests, attendanceDailyEditRequests] = await Promise.all([loadEmployeeLeaveRequests(), loadEmployeeAttendanceDailyEditRequests()]);
      return (data) => ({ ...data, leaveRequests, attendanceDailyEditRequests });
    }
    case "payroll": {
      const payroll = await loadEmployeePayrollStatements();
      return (data) => ({ ...data, payroll });
    }
    case "ledger": {
      const leaveLedger = await loadEmployeeLeaveLedger();
      return (data) => ({ ...data, leaveLedger });
    }
    case "notices": {
      const notifications = await loadEmployeeNotifications();
      return (data) => ({ ...data, notifications });
    }
    default: return (data) => data;
  }
}

export function useEmployeePortalReads(props: EmployeePortalSectionProps, tab: EmployeePortalTab, month: string) {
  const employeeId = props.data.employeePortal.home.employee?.id;
  const update = props.actions.onPortalUpdate;
  const monthRef = useRef({ month, loadMonth: props.actions.onAttendanceMonthChange });
  monthRef.current = { month, loadMonth: props.actions.onAttendanceMonthChange };
  const [section, setSection] = useState<{ tab: EmployeePortalTab; status: "loading" | "ready" | "error" }>({ tab, status: "loading" });
  const [retry, setRetry] = useState(0);
  const [freshness, setFreshness] = useState<"fresh" | "loading" | "offline" | "error">("fresh");
  const refreshTodayRef = useRef<() => void>(() => {});
  const checkedAt = props.data.employeePortal.home.checkedAt;

  useEffect(() => {
    if (!update || employeeId == null || tab === "today") return;
    let active = true;
    setSection({ tab, status: "loading" });
    void readSection(tab, monthRef.current.month, monthRef.current.loadMonth).then((apply) => {
      if (!active) return;
      update((data) => data.home.employee?.id === employeeId ? apply(data) : data);
      setSection({ tab, status: "ready" });
    }).catch(() => { if (active) setSection({ tab, status: "error" }); });
    return () => { active = false; };
  }, [tab, retry, update, employeeId]);

  useEffect(() => {
    if (!update || employeeId == null) return;
    let active = true;
    let pending = false;
    let sequence = 0;
    function offline() { ++sequence; pending = false; setFreshness("offline"); }
    async function refresh() {
      if (!navigator.onLine) { offline(); return; }
      if (pending) return;
      pending = true;
      const issued = ++sequence;
      setFreshness("loading");
      try {
        const result = await loadEmployeeToday();
        if (!active || issued !== sequence) return;
        if (result.employee?.id !== employeeId || (result.todayAttendance && result.todayAttendance.employeeId !== employeeId)) throw new Error("本人確認が必要です");
        update!((data) => data.home.employee?.id === employeeId ? { ...data, home: { ...data.home, todayAttendance: result.todayAttendance, checkedAt: result.checkedAt } } : data);
        setFreshness(navigator.onLine ? "fresh" : "offline");
      } catch { if (active && issued === sequence) setFreshness(navigator.onLine ? "error" : "offline"); }
      finally { if (issued === sequence) pending = false; }
    }
    function visible() { if (document.visibilityState === "visible") void refresh(); }
    function restored(event: PageTransitionEvent) { if (event.persisted) visible(); }
    if (!navigator.onLine) offline();
    refreshTodayRef.current = refresh;
    window.addEventListener("online", refresh);
    window.addEventListener("offline", offline);
    window.addEventListener("pageshow", restored);
    document.addEventListener("visibilitychange", visible);
    return () => {
      active = false;
      refreshTodayRef.current = () => {};
      window.removeEventListener("online", refresh);
      window.removeEventListener("offline", offline);
      window.removeEventListener("pageshow", restored);
      document.removeEventListener("visibilitychange", visible);
    };
  }, [update, employeeId]);

  useEffect(() => {
    if (!checkedAt || !update) return;
    const midnight = Date.parse(checkedAt.slice(0, 10) + "T00:00:00+09:00") + 86_400_000;
    const remaining = midnight - Date.parse(checkedAt);
    if (!Number.isFinite(remaining)) return;
    const timer = window.setTimeout(() => refreshTodayRef.current(), Math.max(100, remaining + 100));
    return () => window.clearTimeout(timer);
  }, [checkedAt, update]);

  const refreshToday = () => update ? refreshTodayRef.current() : props.actions.onRefresh();
  const refreshSection = () => update ? setRetry((n) => n + 1) : props.actions.onRefresh();
  const onNotificationRead = useCallback(async (id: number, source: string) => {
    if (!update) return props.actions.onNotificationRead(id, source);
    await markNotificationRead(id, source);
    update((data) => {
      if (data.home.employee?.id !== employeeId) return data;
      const wasUnread = data.notifications.some((row) => row.id === id && row.sourceType === source && !row.isRead);
      return { ...data, notifications: data.notifications.map((row) => row.id === id && row.sourceType === source ? { ...row, isRead: true } : row),
        home: { ...data.home, unreadNotificationCount: Math.max(0, data.home.unreadNotificationCount - Number(wasUnread)) } };
    });
  }, [employeeId, update, props.actions.onNotificationRead]);
  return { freshness, refreshToday, refreshSection, onNotificationRead, sectionStatus: !update || tab === "today" ? "ready" : section.tab === tab ? section.status : "loading" };
}
