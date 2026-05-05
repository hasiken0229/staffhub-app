import { useEffect } from "react";
import type { AuthAudience, PayrollDataDefinition } from "@/types";

type UseAdminDashboardEffectsParams = {
  adminUrl: () => string;
  bootstrap: (audience: AuthAudience) => Promise<void>;
  filteredPayrollDefinitions: PayrollDataDefinition[];
  loginUrl: () => string;
  payrollStatementType: "PAYROLL" | "BONUS";
  restoreStoredSession: () => { token: string | null; audience: AuthAudience | "" };
  setPayrollDefinitionActive: (value: boolean) => void;
  setPayrollDefinitionId: (value: string) => void;
  setPayrollDefinitionName: (value: string) => void;
  startTransition: (callback: () => void) => void;
};

export function useAdminDashboardEffects(params: UseAdminDashboardEffectsParams) {
  useEffect(() => {
    const { token, audience } = params.restoreStoredSession();
    if (!token || !audience) {
      if (typeof window !== "undefined" && window.location.pathname.includes("/dakoku/admin")) {
        const nextUrl = params.loginUrl();
        if (window.location.href !== nextUrl) {
          window.location.replace(nextUrl);
        }
      }
      return;
    }

    if (typeof window !== "undefined" && window.location.pathname.includes("/dakoku/login")) {
      window.location.replace(params.adminUrl());
      return;
    }

    params.startTransition(() => {
      void params.bootstrap(audience);
    });
  }, []);

  useEffect(() => {
    const activeDefinition = params.filteredPayrollDefinitions.find((definition) => definition.isActive);
    const fallbackDefinition = params.filteredPayrollDefinitions[0];
    const nextDefinition = activeDefinition ?? fallbackDefinition ?? null;

    if (nextDefinition === null) {
      params.setPayrollDefinitionId("");
      params.setPayrollDefinitionName("");
      params.setPayrollDefinitionActive(true);
      return;
    }

    params.setPayrollDefinitionId(String(nextDefinition.id));
    params.setPayrollDefinitionName(nextDefinition.definitionName);
    params.setPayrollDefinitionActive(nextDefinition.isActive);
  }, [params.payrollStatementType, params.filteredPayrollDefinitions]);
}
