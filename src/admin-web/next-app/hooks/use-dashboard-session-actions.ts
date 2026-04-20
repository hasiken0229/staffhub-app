import type { Dispatch, SetStateAction } from "react";
import {
  clearAdminToken,
  loadAdminPayrollStatementDetail,
  loadAdminToken,
  loadCurrentUser,
  loadDashboardData,
  loadEmployeePortalData,
  loadEmployeePayrollStatementDetail,
  loadPayrollImportBatchDetail,
  loadSessionAudience,
  loginPortal,
} from "@/lib/api";
import { withBasePath } from "@/lib/base-path";
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

  function restoreStoredSession() {
    const token = loadAdminToken();
    const audience = loadSessionAudience();
    params.setIsAuthenticated(Boolean(token));
    params.setCurrentAudience(audience);

    return { token, audience };
  }

  async function bootstrap(audience: AuthAudience) {
    try {
      const [user, data] = await Promise.all([
        loadCurrentUser(),
        audience === "ADMIN" ? loadDashboardData() : loadEmployeePortalData(),
      ]);

      params.setCurrentUser(user);
      params.setCurrentAudience(audience);

      if (audience === "ADMIN") {
        const adminData = data as DashboardData;
        params.setDashboard(adminData);
        if (
          adminData.employees.length > 0 &&
          !adminData.employees.some((employee) => employee.id === Number(params.assignEmployeeId))
        ) {
          params.setAssignEmployeeId(String(adminData.employees[0].id));
        }
        if (
          adminData.employees.length > 0 &&
          !adminData.employees.some((employee) => employee.id === Number(params.grantEmployeeId))
        ) {
          params.setGrantEmployeeId(String(adminData.employees[0].id));
        }
        if (
          adminData.employees.length > 0 &&
          !adminData.employees.some((employee) => employee.id === Number(params.reportEmployeeId))
        ) {
          params.setReportEmployeeId(String(adminData.employees[0].id));
        }
        if (params.selectedPayrollBatchId != null) {
          void loadPayrollImportBatchDetail(params.selectedPayrollBatchId, {
            employeeCode: params.payrollBatchEmployeeCodeFilter || undefined,
            employeeName: params.payrollBatchEmployeeNameFilter || undefined,
          }).then((detail) => {
            params.setSelectedPayrollBatchDetail(detail);
            params.setSelectedAdminPayrollDetail(null);
          });
        }
        if (params.selectedAdminPayrollDetailId != null) {
          void loadAdminPayrollStatementDetail(params.selectedAdminPayrollDetailId).then((detail) => {
            params.setSelectedAdminPayrollDetail(detail);
          });
        }
      } else {
        params.setEmployeePortal(data as EmployeePortalData);
        if (params.selectedEmployeePayrollDetailId != null) {
          void loadEmployeePayrollStatementDetail(params.selectedEmployeePayrollDetailId).then((detail) => {
            params.setSelectedEmployeePayrollDetail(detail);
          });
        }
      }

      params.setErrorMessage("");
    } catch (error) {
      const message = error instanceof Error ? error.message : "読込に失敗しました。";
      params.setErrorMessage(message);
      throw error;
    }
  }

  async function refresh() {
    if (!params.currentAudience) {
      return;
    }

    await bootstrap(params.currentAudience);
  }

  async function handleLogin() {
    try {
      const session = await loginPortal(params.loginId, params.password);
      const audience = session.user.role;
      params.setIsAuthenticated(true);
      params.setAuthMessage(audience === "ADMIN" ? "管理者としてログインしました。" : "職員としてログインしました。");
      if (typeof window !== "undefined" && window.location.pathname !== withBasePath("/")) {
        window.location.replace(adminUrl());
        return;
      }
      await bootstrap(audience);
    } catch (error) {
      params.setAuthMessage(error instanceof Error ? error.message : "ログインに失敗しました。");
    }
  }

  function handleLogout() {
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
    handleLogin,
    handleLogout,
    loginUrl,
    refresh,
    restoreStoredSession,
  };
}
