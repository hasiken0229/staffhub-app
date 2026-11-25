import { applyBreakOvertime, loadAttendanceDailyDetail, loadAttendanceDailyHistories } from "@/lib/api/attendance";
import type { BreakOvertimeConfirmation } from "@/types";

export async function recordBreakOvertime(id: number, inputs: BreakOvertimeConfirmation, refreshList: () => Promise<void>) {
  const receipt = await applyBreakOvertime(id, inputs);
  if (receipt.dailyId !== id) throw new Error("計上結果の勤務日が一致しません。日次を再取得してください。");
  const [daily, history, list] = await Promise.allSettled([
    loadAttendanceDailyDetail(id), loadAttendanceDailyHistories(id), refreshList(),
  ]);
  const detail = daily.status === "fulfilled" && daily.value.id === id ? daily.value : null;
  return {
    detail,
    histories: history.status === "fulfilled" ? history.value : [],
    message: `確認した勤怠を計上しました。実働 ${receipt.workMinutes}分、時間外 ${receipt.overtimeMinutes}分。`,
    refreshError: !detail || history.status === "rejected" || list.status === "rejected"
      ? "計上済みです。最新の表示を取得できなかったため、一覧から日次詳細を開き直してください。" : "",
  };
}
