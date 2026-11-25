import { act, renderHook } from "@testing-library/react";
import { beforeEach, expect, it, vi } from "vitest";
import { ApiRequestError, clearAdminToken, loadAdminPayrollStatementDetail, loadCurrentUser, loadDashboardData, loadEmployeePayrollStatementDetail, loadEmployeePortalData, loadSessionAudience } from "@/lib/api";
import { emptyData, emptyEmployeePortal } from "@/lib/dashboard-defaults";
import { useDashboardSessionActions } from "@/hooks/use-dashboard-session-actions";
import type { CurrentUser, DashboardData, EmployeePortalData, PayrollStatementDetail } from "@/types";

vi.mock("@/lib/api", () => ({
  ApiRequestError: class extends Error { constructor(message: string, public status: number) { super(message); } },
  clearAdminToken: vi.fn(), loadAdminToken: vi.fn().mockReturnValue("token"), loadSessionAudience: vi.fn(),
  loadCurrentUser: vi.fn(), loadDashboardData: vi.fn(), loadEmployeePortalData: vi.fn(),
  loadAdminPayrollStatementDetail: vi.fn(), loadEmployeePayrollStatementDetail: vi.fn(),
  loadPayrollImportBatchDetail: vi.fn(), loginPortal: vi.fn(), logoutPortal: vi.fn().mockResolvedValue({ success: true }),
}));

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((done, fail) => { resolve = done; reject = fail; });
  return { promise, resolve, reject };
}
function params(): Parameters<typeof useDashboardSessionActions>[0] {
  return {
    currentAudience: "ADMIN", loginId: "admin", password: "password", assignEmployeeId: "", grantEmployeeId: "", reportEmployeeId: "",
    selectedPayrollBatchId: null, payrollBatchEmployeeCodeFilter: "", payrollBatchEmployeeNameFilter: "",
    selectedAdminPayrollDetailId: null, selectedEmployeePayrollDetailId: null,
    emptyDashboard: emptyData, emptyEmployeePortal,
    setCurrentUser: vi.fn(), setCurrentAudience: vi.fn(), setDashboard: vi.fn(), setEmployeePortal: vi.fn(),
    setErrorMessage: vi.fn(), setAuthMessage: vi.fn(), setIsAuthenticated: vi.fn(), setAssignEmployeeId: vi.fn(),
    setGrantEmployeeId: vi.fn(), setReportEmployeeId: vi.fn(), setSelectedPayrollBatchId: vi.fn(),
    setSelectedPayrollBatchDetail: vi.fn(), setSelectedAdminPayrollDetail: vi.fn(), setSelectedEmployeePayrollDetail: vi.fn(),
  };
}
const user: CurrentUser = { id: 1, role: "ADMIN", name: "管理者", isAdmin: true };
beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(loadCurrentUser).mockResolvedValue(user);
  vi.mocked(loadDashboardData).mockResolvedValue(emptyData);
  vi.mocked(loadEmployeePortalData).mockResolvedValue({ ...emptyEmployeePortal, home: { ...emptyEmployeePortal.home, employee: { id: 1, name: "確認職員", employeeCode: "T1" } } });
  vi.mocked(loadSessionAudience).mockReturnValue("");
});

it("管理者応答が遅れても後から選んだ職員画面を上書きしない", async () => {
  const input = params(); const old = deferred<DashboardData>();
  vi.mocked(loadDashboardData).mockReturnValueOnce(old.promise);
  const { result, rerender } = renderHook((values) => useDashboardSessionActions(values), { initialProps: input });
  let first!: Promise<void>;
  act(() => { first = result.current.bootstrap("ADMIN"); });
  rerender({ ...input, currentAudience: "EMPLOYEE" });
  await act(async () => result.current.bootstrap("EMPLOYEE"));
  await act(async () => { old.resolve(emptyData); await first; });
  expect(input.setDashboard).not.toHaveBeenCalled();
  expect(input.setEmployeePortal).toHaveBeenCalledOnce();
  expect(input.setCurrentAudience).toHaveBeenCalledExactlyOnceWith("EMPLOYEE");
  expect(input.setIsAuthenticated).toHaveBeenCalledExactlyOnceWith(true);
});

it("古い画面の401で最新のログイン状態を解除しない", async () => {
  const input = params(); const old = deferred<DashboardData>();
  vi.mocked(loadDashboardData).mockReturnValueOnce(old.promise);
  const { result } = renderHook(() => useDashboardSessionActions(input));
  let first!: Promise<void>;
  act(() => { first = result.current.bootstrap("ADMIN"); });
  await act(async () => result.current.bootstrap("EMPLOYEE"));
  await act(async () => { old.reject(new ApiRequestError("認証切れ", 401, "UNAUTHENTICATED")); await first; });
  expect(clearAdminToken).not.toHaveBeenCalled();
  expect(input.setAuthMessage).not.toHaveBeenCalled();
  expect(input.setCurrentAudience).toHaveBeenLastCalledWith("EMPLOYEE");
});

it("セッション復元で無効になった応答とアンマウント後の応答を捨てる", async () => {
  const input = params(); const old = deferred<DashboardData>();
  vi.mocked(loadDashboardData).mockReturnValueOnce(old.promise);
  const { result, unmount } = renderHook(() => useDashboardSessionActions(input));
  let first!: Promise<void>;
  act(() => { first = result.current.bootstrap("ADMIN"); result.current.restoreStoredSession(); });
  await act(async () => { old.resolve(emptyData); await first; });
  expect(input.setDashboard).not.toHaveBeenCalled();
  expect(input.setIsAuthenticated).toHaveBeenCalledExactlyOnceWith(false);
  const next = deferred<EmployeePortalData>();
  vi.mocked(loadEmployeePortalData).mockReturnValueOnce(next.promise);
  act(() => { first = result.current.bootstrap("EMPLOYEE"); });
  unmount();
  await act(async () => { next.resolve(emptyEmployeePortal); await first; });
  expect(input.setEmployeePortal).not.toHaveBeenCalled();
});

it("給与詳細の選択変更後に届いた古い詳細を反映しない", async () => {
  const input = { ...params(), selectedAdminPayrollDetailId: 10 };
  const old = deferred<PayrollStatementDetail>();
  vi.mocked(loadAdminPayrollStatementDetail).mockReturnValueOnce(old.promise);
  const { result, rerender } = renderHook((values) => useDashboardSessionActions(values), { initialProps: input });
  await act(async () => result.current.bootstrap("ADMIN"));
  rerender({ ...input, selectedAdminPayrollDetailId: 11 });
  await act(async () => { old.resolve({ id: 10 } as PayrollStatementDetail); await old.promise; });
  expect(input.setSelectedAdminPayrollDetail).not.toHaveBeenCalled();
});

it("ホーム再開時は以前に開いた給与明細を取得しない", async () => {
  const input = { ...params(), selectedEmployeePayrollDetailId: 10 };
  const { result } = renderHook(() => useDashboardSessionActions(input));
  await act(async () => result.current.bootstrap("EMPLOYEE"));
  expect(loadEmployeePayrollStatementDetail).not.toHaveBeenCalled();
  expect(input.setEmployeePortal).toHaveBeenCalledOnce();
});

it("認証した本人と異なるホーム応答は画面に渡さない", async () => {
  const input = params();
  vi.mocked(loadEmployeePortalData).mockResolvedValueOnce({ ...emptyEmployeePortal, home: { ...emptyEmployeePortal.home, employee: { id: 77, name: "架空の別職員", employeeCode: "QA" } } });
  const { result } = renderHook(() => useDashboardSessionActions(input));
  await act(async () => { await expect(result.current.bootstrap("EMPLOYEE")).rejects.toThrow("本人の勤怠情報を確認できません"); });
  expect(input.setEmployeePortal).toHaveBeenCalledExactlyOnceWith(emptyEmployeePortal);
});
