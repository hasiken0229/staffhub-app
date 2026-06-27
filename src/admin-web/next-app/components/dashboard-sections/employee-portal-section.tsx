import { useState } from "react";
import { DailyEditRequestForm } from "./employee-portal/daily-edit-request-form";
import { EmployeePortalLists } from "./employee-portal/employee-portal-lists";
import { EmployeePortalShell } from "./employee-portal/employee-portal-shell";
import { EmployeePortalHomeCards } from "./employee-portal/employee-portal-home-cards";
import type { EmployeePortalSectionProps } from "./employee-portal/employee-portal-types";
import { LeaveRequestForm } from "./employee-portal/leave-request-form";
import { useEmployeePortalForms } from "./employee-portal/use-employee-portal-forms";

export type EmployeePortalTab = "home" | "leave" | "daily-edit" | "requests" | "payroll" | "notices" | "ledger";

export function EmployeePortalSection(props: EmployeePortalSectionProps) {
  const employeeName = props.data.employeePortal.home.employee?.name ?? props.data.currentUserName ?? "職員";
  const { leaveRequestFormProps, dailyEditRequestFormProps } = useEmployeePortalForms(props);
  const [activeTab, setActiveTab] = useState<EmployeePortalTab>("home");

  return (
    <EmployeePortalShell
      employeeName={employeeName}
      data={props.data}
      actions={props.actions}
      onTabChange={setActiveTab}
      activeTab={activeTab}
    >
      {activeTab === "home" ? (
        <EmployeePortalHomeCards data={props.data} formatters={props.formatters} onTabChange={setActiveTab} />
      ) : null}
      {activeTab === "leave" ? <LeaveRequestForm {...leaveRequestFormProps} /> : null}
      {activeTab === "daily-edit" ? <DailyEditRequestForm {...dailyEditRequestFormProps} /> : null}
      {activeTab === "requests" || activeTab === "payroll" || activeTab === "notices" || activeTab === "ledger" ? (
        <EmployeePortalLists data={props.data} actions={props.actions} formatters={props.formatters} activeTab={activeTab} />
      ) : null}
    </EmployeePortalShell>
  );
}
