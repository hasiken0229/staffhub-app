import type { ComponentProps, ReactNode } from "react";
import { AdminPortalShell } from "@/components/admin-portal-shell";
import { EmployeePortalSection } from "@/components/dashboard-sections/employee-portal-section";
import { LoginSection } from "@/components/login-section";
import type { AdminSectionKey } from "@/lib/dashboard-defaults";
import type { AuthAudience } from "@/types";

type AdminDashboardViewProps = {
  isAuthenticated: boolean;
  loginProps: ComponentProps<typeof LoginSection>;
  currentAudience: AuthAudience | "";
  employeePortalSectionProps: ComponentProps<typeof EmployeePortalSection>;
  adminShellProps: Omit<ComponentProps<typeof AdminPortalShell>, "children">;
  adminSections: Record<AdminSectionKey, ReactNode>;
  activeSection: AdminSectionKey;
};

export function AdminDashboardView({
  isAuthenticated,
  loginProps,
  currentAudience,
  employeePortalSectionProps,
  adminShellProps,
  adminSections,
  activeSection,
}: AdminDashboardViewProps) {
  if (!isAuthenticated) {
    return <LoginSection {...loginProps} />;
  }

  if (currentAudience === "EMPLOYEE") {
    return <EmployeePortalSection {...employeePortalSectionProps} />;
  }

  return <AdminPortalShell {...adminShellProps}>{adminSections[activeSection]}</AdminPortalShell>;
}
