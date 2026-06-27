import {
  saveAttendanceAlertSetting,
  saveAttendanceDailyFieldSetting,
  saveAttendanceErrorRuleSetting,
  saveDepartmentSetting,
  saveEmploymentTypeSetting,
  saveLeaveTypeSetting,
  saveLocationSetting,
  savePaidLeaveSetting,
  saveRequestTypeSetting,
  saveWorkTypeSetting,
} from "@/lib/api";

export type SystemFormTarget =
  | "department"
  | "location"
  | "employment"
  | "workType"
  | "requestType"
  | "leaveType"
  | "paidLeaveSetting"
  | "attendanceAlert"
  | "attendanceErrorRule"
  | "dailyField";

type UseSystemActionsParams = {
  setSystemResult: (value: string) => void;
  onRefresh: () => Promise<void>;
};

export function useSystemActions(params: UseSystemActionsParams) {
  function readString(formData: FormData, key: string) {
    return String(formData.get(key) ?? "").trim();
  }

  function readNumber(formData: FormData, key: string) {
    const value = readString(formData, key);
    return value !== "" ? Number(value) : undefined;
  }

  function readBoolean(formData: FormData, key: string) {
    return formData.get(key) === "on";
  }

  function timeToMinutes(value: string) {
    const match = /^(\d{2}):(\d{2})$/.exec(value);
    if (!match) {
      return undefined;
    }
    return Number(match[1]) * 60 + Number(match[2]);
  }

  function calculateWorkTypeMinutes(startTime: string, endTime: string) {
    const start = timeToMinutes(startTime);
    const end = timeToMinutes(endTime);
    if (start == null || end == null) {
      return undefined;
    }
    return Math.max(0, end - start);
  }

  async function handleSystemForm(target: SystemFormTarget, formData: FormData) {
    try {
      if (target === "department") {
        await saveDepartmentSetting({
          id: readNumber(formData, "id"),
          name: readString(formData, "name"),
          sortOrder: readNumber(formData, "sortOrder"),
          isActive: readBoolean(formData, "isActive"),
        });
        params.setSystemResult("部門マスタを更新しました。");
      } else if (target === "location") {
        await saveLocationSetting({
          id: readNumber(formData, "id"),
          name: readString(formData, "name"),
          sortOrder: readNumber(formData, "sortOrder"),
          isActive: readBoolean(formData, "isActive"),
        });
        params.setSystemResult("拠点マスタを更新しました。");
      } else if (target === "employment") {
        await saveEmploymentTypeSetting({
          code: readString(formData, "code"),
          label: readString(formData, "label"),
          standardDayMinutes: readNumber(formData, "standardDayMinutes"),
          sortOrder: readNumber(formData, "sortOrder"),
          isActive: readBoolean(formData, "isActive"),
        });
        params.setSystemResult("雇用形態マスタを更新しました。");
      } else if (target === "workType") {
        const startTime = readString(formData, "startTime");
        const endTime = readString(formData, "endTime");
        await saveWorkTypeSetting({
          id: readNumber(formData, "id"),
          name: readString(formData, "name"),
          startTime,
          endTime,
          defaultBreakMinutes: readNumber(formData, "defaultBreakMinutes"),
          standardDayMinutes: calculateWorkTypeMinutes(startTime, endTime),
          sortOrder: readNumber(formData, "sortOrder"),
          isActive: readBoolean(formData, "isActive"),
        });
        params.setSystemResult("勤務区分マスタを更新しました。");
      } else if (target === "requestType") {
        await saveRequestTypeSetting({
          code: readString(formData, "code"),
          name: readString(formData, "name"),
          sortOrder: readNumber(formData, "sortOrder"),
          isActive: readBoolean(formData, "isActive"),
        });
        params.setSystemResult("申請区分マスタを更新しました。");
      } else if (target === "leaveType") {
        await saveLeaveTypeSetting({
          code: readString(formData, "code"),
          name: readString(formData, "name"),
          requiresBalance: readBoolean(formData, "requiresBalance"),
          allowsHalfDay: readBoolean(formData, "allowsHalfDay"),
          sortOrder: readNumber(formData, "sortOrder"),
          isActive: readBoolean(formData, "isActive"),
        });
        params.setSystemResult("休暇区分マスタを更新しました。");
      } else if (target === "paidLeaveSetting") {
        await savePaidLeaveSetting({
          id: readNumber(formData, "id"),
          settingName: readString(formData, "settingName"),
          annualGrantDays: readNumber(formData, "annualGrantDays") ?? 10,
          carryForwardMonths: readNumber(formData, "carryForwardMonths") ?? 24,
          note: readString(formData, "note") || undefined,
          isActive: readBoolean(formData, "isActive"),
        });
        params.setSystemResult("休暇設定を更新しました。");
      } else if (target === "attendanceAlert") {
        await saveAttendanceAlertSetting({
          code: readString(formData, "code"),
          name: readString(formData, "name"),
          thresholdMinutes: readNumber(formData, "thresholdMinutes"),
          note: readString(formData, "note") || undefined,
          enabled: readBoolean(formData, "enabled"),
        });
        params.setSystemResult("勤怠アラート設定を更新しました。");
      } else if (target === "attendanceErrorRule") {
        await saveAttendanceErrorRuleSetting({
          code: readString(formData, "code"),
          name: readString(formData, "name"),
          minWorkMinutes: readNumber(formData, "minWorkMinutes"),
          maxWorkMinutes: readNumber(formData, "maxWorkMinutes"),
          requiredBreakMinutes: readNumber(formData, "requiredBreakMinutes"),
          maxBreakMinutes: readNumber(formData, "maxBreakMinutes"),
          sortOrder: readNumber(formData, "sortOrder"),
          note: readString(formData, "note") || undefined,
          enabled: readBoolean(formData, "enabled"),
        });
        params.setSystemResult("勤怠エラールールを更新しました。");
      } else if (target === "dailyField") {
        await saveAttendanceDailyFieldSetting({
          fieldKey: readString(formData, "fieldKey"),
          label: readString(formData, "label"),
          displayOrder: readNumber(formData, "displayOrder"),
          enabled: readBoolean(formData, "enabled"),
        });
        params.setSystemResult("日次勤怠項目設定を更新しました。");
      }

      await params.onRefresh();
    } catch (error) {
      params.setSystemResult(error instanceof Error ? error.message : "システム管理の更新に失敗しました。");
    }
  }

  return {
    handleSystemForm,
  };
}
