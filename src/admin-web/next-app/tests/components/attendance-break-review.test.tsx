import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { AttendanceBreakReview } from "@/components/dashboard-sections/attendance/attendance-break-review";
import type { AttendanceBreakReview as BreakReview } from "@/types/attendance";

const automatic: BreakReview = {
  source: "AUTOMATIC",
  sourceLabel: "自動計算",
  recordedMinutes: 45,
  actualBreakStatus: "UNCONFIRMED",
  requiredMinutes: 60,
  shortageMinutes: 15,
  needsConfirmation: true,
  note: "勤務ルールによる計算値です。自動設定された休憩区間は、実際に取得した時刻の記録ではありません。",
  message: "採用実働が8時間を超え、計算・保存された休憩が60分未満です。実際の休憩取得と勤務記録を確認してください。休憩不足を確定する表示ではありません。",
};

describe("break calculation review guidance", () => {
  it("separates automatic allocation from observed breaks and presents a review warning", () => {
    render(<AttendanceBreakReview review={automatic}/>);
    expect(screen.getByText(/休憩 45分 \/ 自動計算/)).toHaveTextContent("実際に取得した時刻の記録ではありません");
    expect(screen.getByRole("status")).toHaveTextContent("休憩取得の確認");
    expect(screen.getByRole("status")).toHaveTextContent("休憩不足を確定する表示ではありません");
    expect(screen.getByText(/一般的な休憩基準/)).toHaveTextContent("休憩や控除は自動追加しません");
  });

  it("shows imported provenance without declaring the actual break verified", () => {
    render(<AttendanceBreakReview review={{...automatic, source:"IMPORTED", sourceLabel:"移行元の保存値", recordedMinutes:60, shortageMinutes:0, needsConfirmation:false, message:null, note:"移行元の休憩分を保持しています。実際の取得状況は移行元の原本等で確認してください。"}}/>);
    expect(screen.getByText(/休憩 60分 \/ 移行元の保存値/)).toHaveTextContent("原本等で確認してください");
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("shows the rule used for a policy settlement while preserving an individually verified break label", () => {
    const { rerender } = render(<AttendanceBreakReview review={{ ...automatic, source: "ADMIN_RULE", sourceLabel: "管理者指定の勤務ルール", recordedMinutes: 60, shortageMinutes: 0, needsConfirmation: false, message: null, note: "勤務ルールで休憩60分を計上しました。" }}/>);
    expect(screen.getByText(/休憩 60分 \/ 管理者指定の勤務ルール/)).toBeVisible();
    expect(screen.queryByText(/休憩や控除は自動追加しません|取得を確認した休憩時間/)).not.toBeInTheDocument();
    rerender(<AttendanceBreakReview review={{ ...automatic, source: "MANUAL_OR_RETAINED", sourceLabel: "確認済みの実休憩", actualBreakStatus: "CONFIRMED" }}/>);
    expect(screen.getByText(/取得を確認した休憩時間/)).toBeVisible();
  });

  it("identifies saved-value review while editing so it cannot be mistaken for an unsaved preview", () => {
    render(<AttendanceBreakReview review={automatic} savedOnly/>);
    expect(screen.getByText(/保存済みデータの休憩確認/)).toHaveTextContent("編集中の変更は保存後に再判定");
  });

  it("keeps an explicit unconfirmed explanation for responses without review metadata", () => {
    render(<AttendanceBreakReview/>);
    expect(screen.getByText(/表示される休憩は計算・保存値/)).toHaveTextContent("実際の取得状況は別途確認");
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });
});
