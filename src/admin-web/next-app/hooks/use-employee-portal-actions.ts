import {
  createEmployeeAttendanceDailyEditRequest,
  createEmployeeLeaveRequest,
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
  async function syncEmployeeLeavePortalSummary() {
    const [home, leaveRequests, attendanceDailyEditRequests, leaveLedger] = await Promise.all([
      loadEmployeePortalHome(),
      loadEmployeeLeaveRequests(),
      loadEmployeeAttendanceDailyEditRequests(),
      loadEmployeeLeaveLedger(),
    ]);

    setEmployeePortal((current) => ({
      ...current,
      home,
      leaveRequests,
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
    await syncEmployeeLeavePortalSummary();
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
