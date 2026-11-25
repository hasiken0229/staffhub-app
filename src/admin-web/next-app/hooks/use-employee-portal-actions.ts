import {
  cancelEmployeeLeaveRequest,
  cancelEmployeeAttendanceDailyEditRequest,
  createEmployeeAttendanceDailyEditRequest,
  createEmployeeLeaveRequest,
  loadEmployeeAttendanceDaily,
  loadEmployeeAttendanceDailyEditRequests,
  loadEmployeeLeaveRequests,
  loadEmployeeLeaveOptions,
  updateEmployee,
} from "@/lib/api";
import type {
  AttendanceDailyEditRequestCreatePayload,
  EmployeePortalData,
  EmployeeUpdatePayload,
  LeaveRequestCreatePayload,
} from "@/types";

type UseEmployeePortalActionsInput = {
  setEmployeePortal: (updater: (current: EmployeePortalData) => EmployeePortalData) => void;
  setErrorMessage: (message: string) => void;
  onRefresh: () => Promise<void>;
  getAttendanceTargetMonth?: () => string;
  onAttendanceTargetMonthChange?: (month: string) => void;
};

export function useEmployeePortalActions({ setEmployeePortal, setErrorMessage, onRefresh, getAttendanceTargetMonth, onAttendanceTargetMonthChange }: UseEmployeePortalActionsInput) {
  async function syncEmployeeLeavePortalSummary() {
    const [leave, leaveRequests] = await Promise.all([loadEmployeeLeaveOptions(), loadEmployeeLeaveRequests()]);
    setEmployeePortal((current) => ({ ...current, home: { ...current.home, ...leave }, leaveRequests }));
  }

  async function syncAttendanceSummary() {
    const targetMonth = getAttendanceTargetMonth?.();
    const [attendanceDaily, attendanceDailyEditRequests] = await Promise.all([
      loadEmployeeAttendanceDaily(targetMonth), loadEmployeeAttendanceDailyEditRequests(),
    ]);
    setEmployeePortal((current) => ({ ...current, attendanceDailyEditRequests,
      ...(getAttendanceTargetMonth && targetMonth !== getAttendanceTargetMonth() ? {} : { attendanceDaily, attendanceTargetMonth: targetMonth }) }));
  }

  async function syncRequestStatus() {
    const [leaveRequests, attendanceDailyEditRequests] = await Promise.all([loadEmployeeLeaveRequests(), loadEmployeeAttendanceDailyEditRequests()]);
    setEmployeePortal((current) => ({ ...current, leaveRequests, attendanceDailyEditRequests }));
  }

  async function handleEmployeeLeaveRequestCreate(payload: LeaveRequestCreatePayload) {
    setErrorMessage("");
    await createEmployeeLeaveRequest(payload);
    try {
      await syncEmployeeLeavePortalSummary();
      return true;
    } catch {
      setErrorMessage("申請は登録済みですが、一覧の更新に失敗しました。最新情報を再読込してください。");
      return false;
    }
  }

  async function handleEmployeeLeaveRequestCancel(id: number) {
    setErrorMessage("");
    try {
      await cancelEmployeeLeaveRequest(id);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "休暇申請の取消に失敗しました。");
      throw error;
    }

    try {
      await syncEmployeeLeavePortalSummary();
    } catch {
      setErrorMessage("取消は完了しましたが、一覧の更新に失敗しました。最新情報を再読込してください。");
    }
  }

  async function handleEmployeeAttendanceDailyEditRequestCreate(payload: AttendanceDailyEditRequestCreatePayload) {
    setErrorMessage("");
    await createEmployeeAttendanceDailyEditRequest(payload);
    try {
      await syncAttendanceSummary();
      return true;
    } catch {
      setErrorMessage("修正申請は登録済みですが、勤怠一覧の更新に失敗しました。再試行は一覧更新だけ行ってください。");
      return false;
    }
  }

  async function handleEmployeeAttendanceDailyEditRequestCancel(id: number) {
    setErrorMessage("");
    await cancelEmployeeAttendanceDailyEditRequest(id);
    try { await syncAttendanceSummary(); }
    catch { setErrorMessage("取消は完了しましたが、一覧の更新に失敗しました。再送せず最新情報を再読込してください。"); }
  }

  async function handleEmployeeUpdate(id: number, payload: EmployeeUpdatePayload) {
    setErrorMessage("");
    await updateEmployee(id, payload);
    await onRefresh();
  }

  async function handleEmployeeAttendanceMonthChange(month: string) {
    if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(month)) throw new Error("対象月を入力してください。");
    onAttendanceTargetMonthChange?.(month);
    setEmployeePortal((current) => ({ ...current, attendanceTargetMonth: month, attendanceDaily: [] }));
    const rows = await loadEmployeeAttendanceDaily(month);
    if (!getAttendanceTargetMonth || getAttendanceTargetMonth() === month) {
      setEmployeePortal((current) => ({ ...current, attendanceTargetMonth: month, attendanceDaily: rows }));
    }
    return rows;
  }

  return {
    handleEmployeeRequestStatusReload: syncRequestStatus,
    handleEmployeeAttendanceMonthChange,
    handleEmployeeAttendanceDailyEditRequestCreate,
    handleEmployeeAttendanceDailyEditRequestCancel,
    handleEmployeeLeaveRequestCancel,
    handleEmployeeLeaveRequestCreate,
    handleEmployeeUpdate,
  };
}
