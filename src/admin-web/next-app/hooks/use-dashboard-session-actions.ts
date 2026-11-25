import { useEffect, useRef, useState, type Dispatch, type SetStateAction } from "react";
import {
  ApiRequestError,
  clearAdminToken,
  changeInitialPassword,
  loadAdminPayrollStatementDetail,
  loadAdminToken,
  loadCurrentUser,
  loadDashboardData,
  loadEmployeePortalData,
  loadPayrollImportBatchDetail,
  loadSessionAudience,
  loginPortal,
  logoutPortal,
} from "@/lib/api";
import { withBasePath } from "@/lib/base-path";
import loginMessages from "@/lib/login-messages.json";
import type {
  AuthAudience,
  CurrentUser,
  DashboardData,
  EmployeePortalData,
  PayrollImportBatchDetail,
  PayrollStatementDetail,
} from "@/types";

type UseDashboardSessionActionsParams = {
  currentAudience: AuthAudience | "";
  getEmployeeAttendanceMonth?: () => string;
  loginId: string;
  password: string;
  assignEmployeeId: string;
  grantEmployeeId: string;
  reportEmployeeId: string;
  selectedPayrollBatchId: number | null;
  payrollBatchEmployeeCodeFilter: string;
  payrollBatchEmployeeNameFilter: string;
  selectedAdminPayrollDetailId: number | null;
  selectedEmployeePayrollDetailId: number | null;
  emptyDashboard: DashboardData;
  emptyEmployeePortal: EmployeePortalData;
  setCurrentUser: Dispatch<SetStateAction<CurrentUser | null>>;
  setCurrentAudience: (value: AuthAudience | "") => void;
  setDashboard: Dispatch<SetStateAction<DashboardData>>;
  setEmployeePortal: Dispatch<SetStateAction<EmployeePortalData>>;
  setErrorMessage: (value: string) => void;
  setAuthMessage: (value: string) => void;
  setIsAuthenticated: (value: boolean) => void;
  setAssignEmployeeId: (value: string) => void;
  setGrantEmployeeId: (value: string) => void;
  setReportEmployeeId: (value: string) => void;
  setSelectedPayrollBatchId: (value: number | null) => void;
  setSelectedPayrollBatchDetail: Dispatch<SetStateAction<PayrollImportBatchDetail | null>>;
  setSelectedAdminPayrollDetail: Dispatch<SetStateAction<PayrollStatementDetail | null>>;
  setSelectedEmployeePayrollDetail: Dispatch<SetStateAction<PayrollStatementDetail | null>>;
};

export function useDashboardSessionActions(params: UseDashboardSessionActionsParams) {
  const [bootstrapFailure, setBootstrapFailure] = useState<{
    audience: AuthAudience; verified: boolean; message: string; retrying: boolean;
  } | null>(null);
  const [checkingSession, setCheckingSession] = useState(true);
  const usableAudience = useRef<AuthAudience | "">("");
  const latestParams = useRef(params);
  latestParams.current = params;
  const sessionGeneration = useRef(0);
  const requestedAudience = useRef<AuthAudience | "">(params.currentAudience);

  function invalidateSessionRequests(audience: AuthAudience | "" = "") {
    requestedAudience.current = audience;
    return ++sessionGeneration.current;
  }

  useEffect(() => () => {
    sessionGeneration.current += 1;
    requestedAudience.current = "";
  }, []);

  function normalizedOrigin() {
    if (typeof window === "undefined") {
      return "";
    }

    const url = new URL(window.location.origin);
    if (url.hostname.startsWith("www.")) {
      url.hostname = url.hostname.replace(/^www\./, "");
    }

    return url.origin;
  }

  function loginUrl() {
    const origin = normalizedOrigin();
    const path = withBasePath("/");
    return origin ? `${origin}${path}` : path;
  }

  function adminUrl() {
    const origin = normalizedOrigin();
    const path = withBasePath("/");
    return origin ? `${origin}${path}` : path;
  }

  function resetBootstrap() {
    usableAudience.current = "";
    setCheckingSession(false);
    setBootstrapFailure(null);
  }

  function restoreStoredSession() {
    resetBootstrap();
    const token = loadAdminToken();
    const audience = loadSessionAudience();
    invalidateSessionRequests(audience);
    setCheckingSession(Boolean(token && audience));
    params.setIsAuthenticated(false);
    params.setCurrentAudience(audience);

    return { token, audience };
  }

  function handleSessionExpired() {
    invalidateSessionRequests();
    resetBootstrap();
    clearAdminToken();
    params.setIsAuthenticated(false);
    params.setCurrentAudience("");
    params.setCurrentUser(null);
    params.setDashboard(params.emptyDashboard);
    params.setEmployeePortal(params.emptyEmployeePortal);
    params.setSelectedPayrollBatchId(null);
    params.setSelectedPayrollBatchDetail(null);
    params.setSelectedAdminPayrollDetail(null);
    params.setSelectedEmployeePayrollDetail(null);
    params.setAuthMessage(loginMessages.expired);
    if (typeof window !== "undefined" && window.location.pathname.includes("/dakoku/admin")) {
      window.sessionStorage.setItem("staffhub-login-notice", "session-expired");
      window.location.replace(loginUrl());
    }
  }

  async function bootstrap(audience: AuthAudience) {
    const generation = invalidateSessionRequests(audience);
    const isCurrent = () => sessionGeneration.current === generation && requestedAudience.current === audience;
    let verified = false;
    if (usableAudience.current !== audience) setCheckingSession(true);
    setBootstrapFailure((failure) => failure?.audience === audience ? { ...failure, retrying: true } : failure);
    try {
      const requestedMonth = latestParams.current.getEmployeeAttendanceMonth?.();
      const [identity, business] = await Promise.allSettled([
        loadCurrentUser(),
        audience === "ADMIN" ? loadDashboardData() : loadEmployeePortalData(requestedMonth, true),
      ]);

      if (!isCurrent()) {
        return;
      }
      if (identity.status === "rejected") throw identity.reason;
      verified = true;
      params.setCurrentUser(identity.value);
      params.setCurrentAudience(audience);
      params.setIsAuthenticated(true);

      if (business.status === "rejected") throw business.reason;
      const data = business.value;
      const currentParams = latestParams.current;
      if (audience === "ADMIN") {
        const adminData = data as Partial<DashboardData>;
        params.setDashboard((current) => ({ ...current, ...adminData }));
        const employees = adminData.employees ?? [];
        if (
          employees.length > 0 &&
          !employees.some((employee) => employee.id === Number(currentParams.assignEmployeeId))
        ) {
          params.setAssignEmployeeId(String(employees[0].id));
        }
        if (
          employees.length > 0 &&
          !employees.some((employee) => employee.id === Number(currentParams.grantEmployeeId))
        ) {
          params.setGrantEmployeeId(String(employees[0].id));
        }
        if (
          employees.length > 0 &&
          !employees.some((employee) => employee.id === Number(currentParams.reportEmployeeId))
        ) {
          params.setReportEmployeeId(String(employees[0].id));
        }
        if (currentParams.selectedPayrollBatchId != null) {
          const batchId = currentParams.selectedPayrollBatchId;
          const codeFilter = currentParams.payrollBatchEmployeeCodeFilter;
          const nameFilter = currentParams.payrollBatchEmployeeNameFilter;
          const isSelectedBatch = () => isCurrent()
            && latestParams.current.selectedPayrollBatchId === batchId
            && latestParams.current.payrollBatchEmployeeCodeFilter === codeFilter
            && latestParams.current.payrollBatchEmployeeNameFilter === nameFilter;
          void loadPayrollImportBatchDetail(batchId, {
            employeeCode: codeFilter || undefined,
            employeeName: nameFilter || undefined,
          }).then((detail) => {
            if (!isSelectedBatch()) { return; }
            params.setSelectedPayrollBatchDetail(detail);
          }).catch((error: unknown) => {
            if (isSelectedBatch()) {
              params.setErrorMessage(error instanceof Error ? error.message : "取込詳細の読込に失敗しました。");
            }
          });
        }
        if (currentParams.selectedAdminPayrollDetailId != null) {
          const statementId = currentParams.selectedAdminPayrollDetailId;
          const isSelectedStatement = () => isCurrent() && latestParams.current.selectedAdminPayrollDetailId === statementId;
          void loadAdminPayrollStatementDetail(statementId).then((detail) => {
            if (!isSelectedStatement()) { return; }
            params.setSelectedAdminPayrollDetail(detail);
          }).catch((error: unknown) => {
            if (isSelectedStatement()) {
              params.setErrorMessage(error instanceof Error ? error.message : "明細詳細の読込に失敗しました。");
            }
          });
        }
      } else {
        const portal = data as EmployeePortalData;
        const verifiedEmployee = identity.value.employeeId ?? identity.value.id;
        if (portal.home.employee?.id !== verifiedEmployee || (portal.home.todayAttendance && portal.home.todayAttendance.employeeId !== verifiedEmployee)) {
          usableAudience.current = "";
          params.setSelectedEmployeePayrollDetail(null);
          params.setEmployeePortal(params.emptyEmployeePortal);
          throw new Error("本人の勤怠情報を確認できませんでした。再試行してください。");
        }
        params.setEmployeePortal((current) => {
          const base = current.home.employee?.id === portal.home.employee?.id ? current : params.emptyEmployeePortal;
          return { ...base, home: { ...base.home, employee: portal.home.employee,
            todayAttendance: portal.home.todayAttendance, checkedAt: portal.home.checkedAt } };
        });
      }

      usableAudience.current = audience;
      setBootstrapFailure(null);
      params.setErrorMessage("");
    } catch (error) {
      if (!isCurrent()) {
        return;
      }
      const message = error instanceof Error ? error.message : "読込に失敗しました。";
      if (error instanceof ApiRequestError && error.status === 401) {
        handleSessionExpired();
      } else if (usableAudience.current !== audience) {
        setBootstrapFailure({ audience, verified, message, retrying: false });
      }
      params.setErrorMessage(message);
      throw error;
    } finally {
      if (isCurrent()) setCheckingSession(false);
    }
  }

  async function refresh() {
    const audience = latestParams.current.currentAudience;
    if (!audience) {
      return;
    }

    await bootstrap(audience);
  }

  async function openAuthenticatedPortal(audience: AuthAudience) {
    resetBootstrap();
    params.setAuthMessage(audience === "ADMIN" ? "管理者としてログインしました。" : "職員としてログインしました。");
    if (typeof window !== "undefined" && window.location.pathname !== withBasePath("/")) {
      window.location.assign(adminUrl());
      return;
    }
    await bootstrap(audience);
  }

  async function handleInitialPasswordChange(token: string, password: string, confirmation: string) {
    const generation = invalidateSessionRequests();
    const session = await changeInitialPassword(token, password, confirmation, () => sessionGeneration.current === generation);
    if (sessionGeneration.current === generation) await openAuthenticatedPortal(session.user.role).catch(() => undefined);
  }

  async function handleLogin(loginId = params.loginId, password = params.password) {
    const generation = invalidateSessionRequests();
    params.setAuthMessage("");
    try {
      const session = await loginPortal(loginId, password, undefined, () => sessionGeneration.current === generation);
      if (sessionGeneration.current !== generation) { return; }
      if ("requiresPasswordChange" in session) return session;
      await openAuthenticatedPortal(session.user.role);
    } catch (error) {
      if (sessionGeneration.current !== generation) { return; }
      params.setAuthMessage(error instanceof Error ? error.message : "ログインに失敗しました。");
    }
  }

  function handleLogout() {
    invalidateSessionRequests();
    resetBootstrap();
    // Do not wait for this request before leaving the page. `keepalive` lets
    // the browser complete it during navigation, and local cleanup remains
    // reliable if the network is unavailable.
    void logoutPortal().catch(() => undefined);
    clearAdminToken();
    params.setIsAuthenticated(false);
    params.setCurrentAudience("");
    params.setCurrentUser(null);
    params.setDashboard(params.emptyDashboard);
    params.setEmployeePortal(params.emptyEmployeePortal);
    params.setSelectedPayrollBatchId(null);
    params.setSelectedPayrollBatchDetail(null);
    params.setSelectedAdminPayrollDetail(null);
    params.setSelectedEmployeePayrollDetail(null);
    params.setErrorMessage("");
    params.setAuthMessage("ログアウトしました。");
    if (typeof window !== "undefined") {
      window.location.replace(loginUrl());
    }
  }

  return {
    adminUrl,
    bootstrap,
    bootstrapFailure,
    checkingSession,
    handleLogin,
    handleInitialPasswordChange,
    handleLogout,
    handleSessionExpired,
    loginUrl,
    refresh,
    restoreStoredSession,
  };
}
