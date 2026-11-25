using StaffHub.PunchApp.Models;
using StaffHub.PunchApp.Services;

namespace StaffHub.PunchApp.ViewModels;

/// <summary>Presentation of a server-recorded punch, including retained-for-review warnings.</summary>
public sealed record PunchResultFeedback(bool RequiresReview, string Detail, string HistoryStatus)
{
    public static PunchResultFeedback From(PunchResult result)
    {
        var requiresReview = string.Equals(result.ResultType, "WARNING", StringComparison.OrdinalIgnoreCase);
        if (result.IsShortShiftTap)
            return new PunchResultFeedback(true, $"{result.OccurredAt:HH:mm:ss} / この再提示では退勤を確定していません。", PunchReviewReceipt.ShortShiftStatus);
        return new PunchResultFeedback(requiresReview,
            requiresReview
                ? $"{result.OccurredAt:HH:mm:ss} / 原本は保存済みです。管理者に確認してください"
                : $"{result.OccurredAt:HH:mm:ss}",
            requiresReview ? "原本保存済・要確認" : "送信済");
    }
}
