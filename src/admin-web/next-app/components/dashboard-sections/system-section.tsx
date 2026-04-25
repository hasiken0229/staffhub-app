import { SystemSectionPanels, type SystemFormTarget, type SystemSectionProps } from "./system/system-section-panels";

export type { SystemFormTarget };

export function SystemSection(props: SystemSectionProps) {
  return <SystemSectionPanels {...props} />;
}
