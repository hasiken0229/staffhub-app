using StaffHub.PunchApp.Models;

namespace StaffHub.PunchApp.ViewModels;

public sealed partial class MainWindowViewModel
{
    private const int ShortShiftPromptSeconds = 30;
    private PendingPunch? _shortShiftCandidate;
    private bool _awaitingConfirmationCard;
    private long _shortShiftDeadline;
    private long _scanGeneration;
    private bool _loadingDeviceReceipts;
    private string _todayReceiptsText = "";

    public bool ShowShortShiftActions => _shortShiftCandidate is not null;
    public bool CanChooseShortShift => ShowShortShiftActions && !_awaitingConfirmationCard;
    public string TodayReceiptsText
    {
        get => _todayReceiptsText;
        private set => SetField(ref _todayReceiptsText, value);
    }

    private static bool CanOfferShortShift(PunchResult result) =>
        result.ResultType == "WARNING" && result.ReasonCode == "SHORT_SHIFT_CONFIRMATION_REQUIRED";

    private void OfferShortShiftConfirmation(PendingPunch original, PunchResult result)
    {
        _shortShiftCandidate = original;
        _shortShiftDeadline = Environment.TickCount64 + ShortShiftPromptSeconds * 1000;
        FeedbackStateLabel = "退勤は未確定";
        ResultHeadline = $"{result.OccurredAt:HH:mm}に退勤しますか？";
        ResultDetail = "記録を確認しただけなら「勤務を続ける」を選んでください。";
        OnPropertyChanged(nameof(ShowShortShiftActions));
        OnPropertyChanged(nameof(CanChooseShortShift));
        ScheduleReadyReset();
    }

    public void ChooseShortShiftClockOut()
    {
        if (!CanChooseShortShift) return;
        if (Environment.TickCount64 >= _shortShiftDeadline)
        {
            ResetToReady();
            return;
        }
        _awaitingConfirmationCard = true;
        _shortShiftDeadline = Environment.TickCount64 + ShortShiftPromptSeconds * 1000;
        ResultDetail = "退勤するご本人のカードをもう一度かざしてください。";
        OnPropertyChanged(nameof(CanChooseShortShift));
        ScheduleReadyReset();
    }

    public void DismissShortShiftPrompt()
    {
        if (!ShowShortShiftActions) return;
        _readyResetTimer.Stop();
        ResetToReady();
    }

    private PendingPunch? TakeShortShiftConfirmation(string cardUid)
    {
        var candidate = _shortShiftCandidate;
        var confirmed = candidate is not null && _awaitingConfirmationCard && !CardRegistrationModeEnabled
            && Environment.TickCount64 < _shortShiftDeadline
            && string.Equals(candidate.CardUid, cardUid, StringComparison.OrdinalIgnoreCase);
        ClearShortShiftPrompt();
        return confirmed ? candidate! with { ConfirmShortShift = true } : null;
    }

    private void ClearShortShiftPrompt()
    {
        if (_shortShiftCandidate is null) return;
        _shortShiftCandidate = null;
        _awaitingConfirmationCard = false;
        OnPropertyChanged(nameof(ShowShortShiftActions));
        OnPropertyChanged(nameof(CanChooseShortShift));
    }

    public async Task LoadTodayReceiptsAsync()
    {
        if (_loadingDeviceReceipts) return;
        _loadingDeviceReceipts = true;
        TodayReceiptsText = "受付記録を照会しています…";
        try
        {
            var result = await _apiClient.GetTodayReceiptsAsync(_backgroundOperationsCts.Token);
            var lines = result.Receipts.Select(item =>
                $"{item.OccurredAt:HH:mm:ss}　{FormatEventType(item.EventType)}　{item.EmployeeName}");
            TodayReceiptsText = $"{result.CheckedAt:MM/dd HH:mm:ss} 現在\n" +
                (result.Receipts.Count == 0 ? "この端末の本日の確定打刻はありません。" : string.Join("\n", lines));
        }
        catch (OperationCanceledException) when (_isDisposed) { }
        catch (Exception)
        {
            TodayReceiptsText = "記録を取得できません。通信復旧後にこの欄を開き直してください。";
        }
        finally { _loadingDeviceReceipts = false; }
    }
}
