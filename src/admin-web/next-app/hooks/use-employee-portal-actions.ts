import {
  createEmployeeAttendanceDailyEditRequest,
  createEmployeeLeaveRequest,
  loadEmployeeAttendanceDaily,
  loadEmployeeAttendanceDailyEditRequests,
  loadEmployeeLeaveLedger,
  loadEmployeeLeaveRequests,
  loadEmployeePortalHome,
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
};

export function useEmployeePortalActions({ setEmployeePortal, setErrorMessage, onRefresh }: UseEmployeePortalActionsInput) {
  async function syncEmployeeLeavePortalSummary(targetMonth?: string) {
    const [home, leaveRequests, attendanceDaily, attendanceDailyEditRequests, leaveLedger] = await Promise.all([
      loadEmployeePortalHome(),
      loadEmployeeLeaveRequests(),
      loadEmployeeAttendanceDaily(targetMonth),
      loadEmployeeAttendanceDailyEditRequests(),
      loadEmployeeLeaveLedger(),
    ]);

    setEmployeePortal((current) => ({
      ...current,
      home,
      leaveRequests,
      attendanceDaily,
      attendanceDailyEditRequests,
      leaveLedger,
    }));
  }

  async function handleEmployeeLeaveRequestCreate(payload: LeaveRequestCreatePayload) {
    setErrorMessage("");
    await createEmployeeLeaveRequest(payload);
    await syncEmployeeLeavePortalSummary();
  }

  async function handleEmployeeAttendanceDailyEditRequestCreate(payload: AttendanceDailyEditRequestCreatePayload) {
    setErrorMessage("");
    await createEmployeeAttendanceDailyEditRequest(payload);
    await syncEmployeeLeavePortalSummary(payload.targetDate.slice(0, 7));
  }

  async function handleEmployeeUpdate(id: number, payload: EmployeeUpdatePayload) {
    setErrorMessage("");
    await updateEmployee(id, payload);
    await onRefresh();
  }

  return {
    handleEmployeeAttendanceDailyEditRequestCreate,
    handleEmployeeLeaveRequestCreate,
    handleEmployeeUpdate,
  };
}
