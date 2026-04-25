import { DailyEditRequestForm } from "./employee-portal/daily-edit-request-form";
import { EmployeePortalLists } from "./employee-portal/employee-portal-lists";
import { EmployeePortalShell } from "./employee-portal/employee-portal-shell";
import type { EmployeePortalSectionProps } from "./employee-portal/employee-portal-types";
import { LeaveRequestForm } from "./employee-portal/leave-request-form";
import { useEmployeePortalForms } from "./employee-portal/use-employee-portal-forms";

export function EmployeePortalSection(props: EmployeePortalSectionProps) {
  const employeeName = props.data.employeePortal.home.employee?.name ?? props.data.currentUserName ?? "職員";
  const { leaveRequestFormProps, dailyEditRequestFormProps } = useEmployeePortalForms(props);

  return (
    <EmployeePortalShell employeeName={employeeName} data={props.data} actions={props.actions} formatters={props.formatters}>
      <LeaveRequestForm {...leaveRequestFormProps} />
      <DailyEditRequestForm {...dailyEditRequestFormProps} />
      <EmployeePortalLists data={props.data} actions={props.actions} formatters={props.formatters} />
    </EmployeePortalShell>
  );
}
