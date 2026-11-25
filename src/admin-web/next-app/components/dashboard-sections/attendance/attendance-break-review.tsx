import type { AttendanceBreakReview as BreakReview } from "@/types/attendance";

export function AttendanceBreakReview({ review, savedOnly = false }: { review?: BreakReview; savedOnly?: boolean }) {
  return (
    <div aria-label="休憩の計算と取得確認">
      {savedOnly ? <p className="compact-empty">保存済みデータの休憩確認です。編集中の変更は保存後に再判定します。</p> : null}
      <p>
        {review ? `休憩 ${review.recordedMinutes}分 / ${review.sourceLabel}。${review.note}` : "表示される休憩は計算・保存値です。実際の取得状況は別途確認してください。"}
      </p>
      {review?.needsConfirmation ? <p className="banner" role="status">休憩取得の確認：{review.message}</p> : null}
      {review?.source !== "ADMIN_RULE" && <p className="compact-empty">一般的な休憩基準（実働6時間超で45分、8時間超で60分）による確認目安です。{review?.actualBreakStatus === "CONFIRMED" ? "取得を確認した休憩時間を表示しています。" : "適用区分や実際の取得状況を確認してください。休憩や控除は自動追加しません。"}</p>}
    </div>
  );
}
