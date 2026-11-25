using System.ComponentModel;
using System.Collections.ObjectModel;
using System.IO;
using System.Media;
using System.Net.Http;
using System.Runtime.CompilerServices;
using System.Windows;
using System.Windows.Media;
using System.Windows.Threading;
using StaffHub.PunchApp.Models;
using StaffHub.PunchApp.Services;

namespace StaffHub.PunchApp.ViewModels;

public sealed partial class MainWindowViewModel : INotifyPropertyChanged, IDisposable
{
    private ICardReaderService _deviceReader;
    private readonly MockCardReaderService _mockReader;
    private readonly OfflinePunchQueue _offlineQueue;
    private readonly AttendanceApiClient _apiClient;
    private readonly Action<string> _setClipboardText;
    private readonly Action<PunchTone> _playPunchTone;
    private readonly PendingPunchDeliveryProcessor _pendingPunchProcessor;
    private readonly PunchReviewReceiptStore _receiptStore;
    private readonly HashSet<string> _displayedReceipts = new(StringComparer.Ordinal);
    private readonly DispatcherTimer _clockTimer;
    private readonly DispatcherTimer _readyResetTimer;
    private readonly DispatcherTimer _automaticRetryTimer;
    private readonly DispatcherTimer _heartbeatTimer;
    private readonly CancellationTokenSource _backgroundOperationsCts = new();

    private const int AutomaticRetryBatchSize = 20;
    private static readonly TimeSpan StartupRecoveryDelay = TimeSpan.FromSeconds(3);
    private static readonly TimeSpan AutomaticRetryInterval = TimeSpan.FromSeconds(30);
    private static readonly TimeSpan HeartbeatInterval = TimeSpan.FromMinutes(1);
    private const int ClockDriftWarningSeconds = 30;
    private const int ClockDriftErrorSeconds = 120;

    private string _currentTimeText = DateTime.Now.ToString("yyyy/MM/dd HH:mm:ss");
    private string _currentClockText = DateTime.Now.ToString("HH:mm:ss");
    private string _networkStatus = "オンライン";
    private string _resultHeadline = "カードをかざしてください";
    private string _resultDetail = "RC-S380 の読取待機中";
    private string _employeeName = "-";
    private string _lastEventType = "-";
    private string _resultGlyph = "●";
    private string _feedbackStateLabel = "待機中";
    private Brush _feedbackBackground = new SolidColorBrush(Color.FromRgb(247, 250, 255));
    private Brush _feedbackAccent = new SolidColorBrush(Color.FromRgb(111, 143, 229));
    private Brush _offlineWarningBackground = new SolidColorBrush(Color.FromRgb(238, 244, 255));
    private string _simulatedCardUid = "0123456789ABCDEF";
    private string _readerStatus = "初期化中";
    private string _receiptStatus = "確認結果はありません";
    private string _clockDriftStatus = "時刻同期を確認中";
    private string _lastHeartbeatText = "端末通信を確認中";
    private string _lastCardUid = "-";
    private string _cardRegistrationMessage = "登録時はONにすると打刻せずUIDだけ読み取ります";
    private bool _cardRegistrationModeEnabled;
    private long? _selectedRegistrationEmployeeId;
    private bool _isCardRegistrationBusy;
    private int _resultPulseKey;
    private int _automaticRetryInProgress;
    private int _heartbeatInProgress;
    private bool _isDisposed;
    private int _readerReconnectInProgress;
    private bool _foregroundDeliveryInProgress;

    public MainWindowViewModel() : this(
        AppSettingsLoader.Load(), new OfflinePunchQueue(),
        new PunchReviewReceiptStore(PunchReviewReceiptStore.DefaultStoragePath()), null, Clipboard.SetText) { }

    internal MainWindowViewModel(AppSettings settings, OfflinePunchQueue offlineQueue,
        PunchReviewReceiptStore receiptStore, HttpClient? httpClient, Action<string> setClipboardText,
        Action<PunchTone>? playPunchTone = null)
    {
        Settings = settings;
        _mockReader = new MockCardReaderService();
        _deviceReader = CreateDeviceReader();
        _offlineQueue = offlineQueue;
        _apiClient = new AttendanceApiClient(settings, httpClient);
        _setClipboardText = setClipboardText;
        _playPunchTone = playPunchTone ?? PlayPunchTone;
        _receiptStore = receiptStore;
        _pendingPunchProcessor = new PendingPunchDeliveryProcessor(_offlineQueue, _apiClient, _receiptStore);
        RefreshReviewReceipts();

        if (_offlineQueue.Items.Count > 0)
        {
            _networkStatus = $"未送信 {_offlineQueue.Items.Count}件";
            _resultHeadline = "未送信の打刻があります";
            _resultDetail = "通信復旧後に自動で再送します";
        }

        WireAndStartDeviceReader();
        _mockReader.CardScanned += async (_, cardUid) => await HandleScannedOnUiAsync(cardUid);
        _mockReader.Start();

        _clockTimer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(1) };
        _clockTimer.Tick += (_, _) =>
        {
            UpdateClock();
            ReaderStatus = _deviceReader.StatusText;
        };
        _clockTimer.Start();
        _readyResetTimer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(5) };
        _readyResetTimer.Tick += (_, _) =>
        {
            _readyResetTimer.Stop();
            ResetToReady();
        };
        _automaticRetryTimer = new DispatcherTimer { Interval = AutomaticRetryInterval };
        _automaticRetryTimer.Tick += async (_, _) => await RunAutomaticRetryAsync();
        _automaticRetryTimer.Start();
        _heartbeatTimer = new DispatcherTimer { Interval = HeartbeatInterval };
        _heartbeatTimer.Tick += async (_, _) =>
        {
            await SendHeartbeatAsync();
            await RunAutomaticRetryAsync();
        };
        _heartbeatTimer.Start();
        UpdateClock();
        ReaderStatus = _deviceReader.StatusText;
        _ = StartBackgroundRecoveryAsync();
    }

    private ICardReaderService CreateDeviceReader()
        => CardReaderFactory.Create(Settings);

    public event PropertyChangedEventHandler? PropertyChanged;

    public AppSettings Settings { get; }
    public IReadOnlyList<PendingPunch> PendingPunches => _offlineQueue.Items;
    public ObservableCollection<PunchHistoryItem> PunchHistory { get; } = [];
    public ObservableCollection<PunchReviewReceipt> ReviewReceipts { get; } = [];
    public string ReceiptStatus { get => _receiptStatus; private set => SetField(ref _receiptStatus, value); }
    public ObservableCollection<RegistrationEmployee> RegistrationEmployees { get; } = [];

    public string CurrentTimeText
    {
        get => _currentTimeText;
        set => SetField(ref _currentTimeText, value);
    }

    public string CurrentClockText
    {
        get => _currentClockText;
        set => SetField(ref _currentClockText, value);
    }

    public string NetworkStatus
    {
        get => _networkStatus;
        set => SetField(ref _networkStatus, value);
    }

    public string ResultHeadline
    {
        get => _resultHeadline;
        set => SetField(ref _resultHeadline, value);
    }

    public string ResultDetail
    {
        get => _resultDetail;
        set => SetField(ref _resultDetail, value);
    }

    public string EmployeeName
    {
        get => _employeeName;
        set => SetField(ref _employeeName, value);
    }

    public string LastEventType
    {
        get => _lastEventType;
        set => SetField(ref _lastEventType, value);
    }

    public string ResultGlyph
    {
        get => _resultGlyph;
        set => SetField(ref _resultGlyph, value);
    }

    public string FeedbackStateLabel
    {
        get => _feedbackStateLabel;
        set => SetField(ref _feedbackStateLabel, value);
    }

    public Brush FeedbackBackground
    {
        get => _feedbackBackground;
        set => SetField(ref _feedbackBackground, value);
    }

    public Brush FeedbackAccent
    {
        get => _feedbackAccent;
        set => SetField(ref _feedbackAccent, value);
    }

    public Brush OfflineWarningBackground
    {
        get => _offlineWarningBackground;
        set => SetField(ref _offlineWarningBackground, value);
    }

    public int ResultPulseKey
    {
        get => _resultPulseKey;
        set => SetField(ref _resultPulseKey, value);
    }

    public string SimulatedCardUid
    {
        get => _simulatedCardUid;
        set => SetField(ref _simulatedCardUid, value);
    }

    public string ReaderStatus
    {
        get => _readerStatus;
        set => SetField(ref _readerStatus, value);
    }

    public string ClockDriftStatus
    {
        get => _clockDriftStatus;
        set => SetField(ref _clockDriftStatus, value);
    }

    public string LastHeartbeatText
    {
        get => _lastHeartbeatText;
        set => SetField(ref _lastHeartbeatText, value);
    }

    public string LastCardUid
    {
        get => _lastCardUid;
        set => SetField(ref _lastCardUid, value);
    }

    public bool CardRegistrationModeEnabled
    {
        get => _cardRegistrationModeEnabled;
        set
        {
            if (_cardRegistrationModeEnabled == value)
            {
                return;
            }

            SetField(ref _cardRegistrationModeEnabled, value);
            ++_scanGeneration;
            _foregroundDeliveryInProgress = false;
            ClearShortShiftPrompt();
            _readyResetTimer.Stop();
            ResetToReady();
            if (value)
            {
                _ = LoadRegistrationEmployeesAsync();
            }
        }
    }

    public string CardRegistrationMessage
    {
        get => _cardRegistrationMessage;
        set => SetField(ref _cardRegistrationMessage, value);
    }

    public long? SelectedRegistrationEmployeeId
    {
        get => _selectedRegistrationEmployeeId;
        set => SetField(ref _selectedRegistrationEmployeeId, value);
    }

    public bool IsCardRegistrationBusy
    {
        get => _isCardRegistrationBusy;
        set => SetField(ref _isCardRegistrationBusy, value);
    }

    public int PendingCount => PendingPunches.Count;

    public async Task SimulateScanAsync()
    {
        _mockReader.SimulateScan(SimulatedCardUid);
        await Task.CompletedTask;
    }

    public Task RetryPendingAsync()
        => RetryPendingAsync(isAutomatic: false, _backgroundOperationsCts.Token);

    private async Task StartBackgroundRecoveryAsync()
    {
        try
        {
            await Task.Delay(StartupRecoveryDelay, _backgroundOperationsCts.Token);
            await SendHeartbeatAsync(_backgroundOperationsCts.Token);
            await RunAutomaticRetryAsync();
        }
        catch (OperationCanceledException) when (_backgroundOperationsCts.IsCancellationRequested)
        {
            // The window is closing.
        }
    }

    private async Task RunAutomaticRetryAsync()
    {
        if (_isDisposed || PendingCount == 0 || Interlocked.Exchange(ref _automaticRetryInProgress, 1) != 0)
        {
            return;
        }

        try
        {
            await RetryPendingAsync(isAutomatic: true, _backgroundOperationsCts.Token);
        }
        catch (OperationCanceledException) when (_backgroundOperationsCts.IsCancellationRequested)
        {
            // The window is closing.
        }
        finally
        {
            Volatile.Write(ref _automaticRetryInProgress, 0);
        }
    }

    private async Task RetryPendingAsync(bool isAutomatic, CancellationToken cancellationToken)
    {
        if (_isDisposed)
        {
            return;
        }

        var beforeCount = PendingCount;
        if (beforeCount == 0)
        {
            if (!isAutomatic)
            {
                NetworkStatus = "オンライン";
            }

            return;
        }

        var generation = _scanGeneration;
        var summary = await _pendingPunchProcessor.RetryAsync(AutomaticRetryBatchSize, cancellationToken);
        RefreshPendingPunches();
        var newReceipts = RefreshReviewReceipts();
        if (_foregroundDeliveryInProgress || generation != _scanGeneration || ShowShortShiftActions || CardRegistrationModeEnabled)
        {
            NetworkStatus = summary.LastResult?.Outcome == PendingPunchDeliveryOutcome.ReceiptSaveFailed
                ? "確認結果の保存エラー" : OnlineStatusForPendingPunches();
            return;
        }

        if (summary.LastResult is { Outcome: PendingPunchDeliveryOutcome.ReceiptSaveFailed } receiptFailure)
        {
            DisplayQueueRemovalFailure(receiptFailure.Pending, receiptFailure);
            return;
        }

        if (summary.ReviewWarnings is { Count: > 0 } taps && taps.All(warning => warning.IsShortShiftTap)
            && summary.RejectedCount == 0 && summary.RetainedCount == 0 && newReceipts.All(entry => entry.WasShortShiftTap))
        {
            NetworkStatus = OnlineStatusForPendingPunches();
            ResultHeadline = "再提示の時刻を保存しました";
            ResultDetail = PunchReviewReceipt.ShortShiftReason;
            EmployeeName = "-";
            LastEventType = "再提示";
            RecordReviewWarnings(taps);
            SetFeedbackState(FeedbackState.Ready);
            ScheduleReadyReset();
            return;
        }
        if (newReceipts.Count > 0)
        {
            NetworkStatus = summary.RemainingCount > 0 ? $"未送信 {summary.RemainingCount}件・自動再送待機" : "オンライン";
            ResultHeadline = "受付拒否・確認対象の打刻があります";
            ResultDetail = $"{newReceipts.Count}件 / 時刻と理由を右の履歴・保守パネルで確認してください";
            EmployeeName = "-";
            LastEventType = "要確認";
            SetFeedbackState(FeedbackState.Error);
            PlayErrorSound();
            ScheduleReadyReset();
            return;
        }

        if (summary.ReviewWarnings is { Count: > 0 } warnings)
        {
            NetworkStatus = summary.RemainingCount > 0
                ? $"未送信 {summary.RemainingCount}件・自動再送待機"
                : "オンライン";
            ResultHeadline = "管理者の確認が必要な打刻があります";
            ResultDetail = $"{warnings.Count}件の原本を保存済みです。管理者に確認してください";
            EmployeeName = "-";
            LastEventType = "-";
            RecordReviewWarnings(warnings);
            SetFeedbackState(FeedbackState.Error);
            PlayErrorSound();
            ScheduleReadyReset();
            return;
        }

        if (summary.RemainingCount == 0)
        {
            NetworkStatus = "オンライン";
            if (!isAutomatic)
            {
                ResultHeadline = summary.RejectedCount > 0
                    ? "未送信打刻を確認しました"
                    : "未送信打刻を再送しました";
                ResultDetail = summary.RejectedCount > 0
                    ? $"送信 {summary.DeliveredCount}件 / 受け付け不可 {summary.RejectedCount}件"
                    : $"{summary.DeliveredCount}件を送信しました";
                SetFeedbackState(summary.RejectedCount > 0 ? FeedbackState.Error : FeedbackState.Success);
                ScheduleReadyReset();
            }

            return;
        }

        NetworkStatus = $"未送信 {summary.RemainingCount}件・自動再送待機";
        if (!isAutomatic)
        {
            ResultHeadline = "未送信打刻を保持しています";
            ResultDetail = FormatRetryDeferredDetail(summary.LastResult);
            SetFeedbackState(FeedbackState.Offline);
            ScheduleReadyReset();
        }
    }

    private async Task SendHeartbeatAsync(CancellationToken cancellationToken = default)
    {
        if (_isDisposed || Interlocked.Exchange(ref _heartbeatInProgress, 1) != 0)
        {
            return;
        }

        try
        {
            var result = await _apiClient.SendHeartbeatAsync(PendingCount, ReaderStatus, cancellationToken);
            LastHeartbeatText = $"最終通信 {DateTimeOffset.Now:HH:mm:ss}";
            ClockDriftStatus = FormatClockDriftStatus(result.ClockDriftSeconds);
            NetworkStatus = PendingCount > 0 ? $"オンライン・未送信 {PendingCount}件" : "オンライン";
        }
        catch (OperationCanceledException) when (_backgroundOperationsCts.IsCancellationRequested)
        {
            // The window is closing.
        }
        catch (AttendanceApiException exception)
        {
            LastHeartbeatText = $"端末通信エラー: {exception.Code}";
            ClockDriftStatus = "時刻同期を確認できません";
            NetworkStatus = PendingCount > 0 ? $"未送信 {PendingCount}件・自動再送待機" : "端末通信エラー";
        }
        catch
        {
            LastHeartbeatText = "端末通信を確認できません";
            ClockDriftStatus = "時刻同期を確認できません";
            NetworkStatus = PendingCount > 0 ? $"未送信 {PendingCount}件・自動再送待機" : "オフライン";
        }
        finally
        {
            Volatile.Write(ref _heartbeatInProgress, 0);
        }
    }

    public async Task RestartReaderAsync()
    {
        if (_isDisposed || Interlocked.Exchange(ref _readerReconnectInProgress, 1) != 0) return;
        try
        {
        _readyResetTimer.Stop();
        ResetToReady();

        if (!NeedsReaderReconnect(_deviceReader.StatusText)
            && !(_deviceReader is IReaderWorkerStatus status && status.NeedsReconnect))
        {
            ReaderStatus = _deviceReader.StatusText;
            return;
        }

        ReaderStatus = "カードリーダーを再接続中";

        await Task.Run(() => _deviceReader.Dispose());
        if (_isDisposed) return;
        if (_deviceReader is IReaderWorkerStatus oldWorker && oldWorker.IsWorkerActive)
        {
            ReaderStatus = _deviceReader.StatusText;
            return;
        }
        _deviceReader = CreateDeviceReader();
        WireAndStartDeviceReader();

        await Task.Delay(700, _backgroundOperationsCts.Token);
        ReaderStatus = _deviceReader.StatusText;
        }
        catch (OperationCanceledException) when (_isDisposed) { }
        catch (Exception error) { ReaderStatus = $"カードリーダー再接続エラー: {error.GetType().Name}"; }
        finally { Volatile.Write(ref _readerReconnectInProgress, 0); }
    }

    public async Task RegisterLastCardAsync()
    {
        if (IsCardRegistrationBusy)
        {
            return;
        }

        if (string.IsNullOrWhiteSpace(LastCardUid) || LastCardUid == "-")
        {
            CardRegistrationMessage = "先にカードをかざしてUIDを読み取ってください。";
            return;
        }

        if (SelectedRegistrationEmployeeId is null)
        {
            CardRegistrationMessage = "登録先の職員を選択してください。";
            return;
        }

        IsCardRegistrationBusy = true;
        try
        {
            var result = await _apiClient.AssignCardAsync(SelectedRegistrationEmployeeId.Value, LastCardUid);
            CardRegistrationMessage = $"{result.EmployeeCode} / {result.EmployeeName} にカードを登録しました。";
            AddHistory(DateTimeOffset.Now, result.EmployeeName, "カード登録", result.CardUid, "登録済");
        }
        catch (AttendanceApiException ex)
        {
            CardRegistrationMessage = ex.Message;
        }
        catch
        {
            CardRegistrationMessage = "カード登録の結果を確認できません。登録状況を確認してください。";
        }
        finally
        {
            IsCardRegistrationBusy = false;
        }
    }

    public void Dispose()
    {
        if (_isDisposed)
        {
            return;
        }

        _isDisposed = true;
        _backgroundOperationsCts.Cancel();
        _clockTimer.Stop();
        _readyResetTimer.Stop();
        _automaticRetryTimer.Stop();
        _heartbeatTimer.Stop();
        _deviceReader.Dispose();
        _mockReader.Dispose();
    }

    private async Task HandleScannedOnUiAsync(string cardUid)
    {
        var operation = Application.Current.Dispatcher.InvokeAsync(async () =>
        {
            await HandleScannedAsync(cardUid);
        });
        await operation.Task.Unwrap();

        if (!_isDisposed)
        {
            ReaderStatus = _deviceReader.StatusText;
        }
    }

    internal async Task HandleScannedAsync(string cardUid)
    {
        var generation = ++_scanGeneration;
        _foregroundDeliveryInProgress = false;
        var confirmed = TakeShortShiftConfirmation(cardUid);
        _readyResetTimer.Stop();
        LastCardUid = cardUid;
        SimulatedCardUid = cardUid;

        if (CardRegistrationModeEnabled)
        {
            CopyCardUidToClipboard(cardUid);
            ResultHeadline = "登録用にカードを読み取りました";
            ResultDetail = CardRegistrationMessage;
            EmployeeName = "打刻は記録されません";
            LastEventType = "保守パネルを閉じると打刻に戻ります";
            SetFeedbackState(FeedbackState.Registration);
            AddHistory(DateTimeOffset.Now, "登録用", "UID読取", "打刻対象外", "登録用読取");
            ScheduleReadyReset();
            return;
        }

        SetFeedbackState(FeedbackState.Sending);
        ResultHeadline = confirmed is null ? "カードを読み取りました" : "短時間の退勤を確定しています";
        ResultDetail = "サーバーに確認しています";
        EmployeeName = "確認中";
        LastEventType = "-";
        _playPunchTone(PunchTone.CardRead);
        var pending = confirmed ?? _apiClient.CreatePendingPunch(cardUid);

        try
        {
            // Persist before the network request so a power loss during transmission cannot lose the punch.
            _offlineQueue.Enqueue(pending);
            OnPropertyChanged(nameof(PendingPunches));
            OnPropertyChanged(nameof(PendingCount));
        }
        catch
        {
            NetworkStatus = "端末保存エラー";
            ResultHeadline = "打刻を端末に保存できませんでした";
            ResultDetail = "保存先の空き容量とアクセス権を確認してください";
            EmployeeName = "-";
            LastEventType = "-";
            SetFeedbackState(FeedbackState.Error);
            PlayErrorSound();
            ScheduleReadyReset();
            return;
        }

        PendingPunchDeliveryResult delivery;
        _foregroundDeliveryInProgress = true;
        try
        {
            delivery = await _pendingPunchProcessor.DeliverAsync(pending, _backgroundOperationsCts.Token);
        }
        catch (OperationCanceledException) when (_backgroundOperationsCts.IsCancellationRequested)
        {
            return;
        }
        finally
        {
            if (generation == _scanGeneration) _foregroundDeliveryInProgress = false;
        }

        RefreshPendingPunches();
        RefreshReviewReceipts();

        if (generation != _scanGeneration)
        {
            // A later scan or registration-mode switch owns the shared screen.
            if (delivery.PunchResult is { } recorded)
                AddServerPunchHistory(recorded);
            else if (delivery.Outcome == PendingPunchDeliveryOutcome.DeterministicallyRejected)
                AddHistory(pending.OccurredAt, "-", "-", delivery.ApiException!.Message, "拒否");
            return;
        }
        switch (delivery.Outcome)
        {
            case PendingPunchDeliveryOutcome.Delivered:
                DisplayDeliveredPunch(delivery.PunchResult!);
                if (!pending.ConfirmShortShift && CanOfferShortShift(delivery.PunchResult!))
                    OfferShortShiftConfirmation(pending, delivery.PunchResult!);
                break;
            case PendingPunchDeliveryOutcome.DeterministicallyRejected:
                DisplayRejectedPunch(pending, delivery.ApiException!);
                break;
            case PendingPunchDeliveryOutcome.QueueRemovalFailed:
            case PendingPunchDeliveryOutcome.ReceiptSaveFailed:
                DisplayQueueRemovalFailure(pending, delivery);
                break;
            case PendingPunchDeliveryOutcome.AlreadyRemoved:
                NetworkStatus = OnlineStatusForPendingPunches();
                ResultHeadline = "送信結果を確認してください";
                ResultDetail = "同じ打刻は再送しません。履歴または管理画面で結果を確認してください";
                EmployeeName = "-";
                LastEventType = "-";
                SetFeedbackState(FeedbackState.Ready);
                FeedbackStateLabel = "確認待ち";
                ScheduleReadyReset();
                break;
            default:
                DisplayDeferredPunch(pending, delivery);
                break;
        }

        var earlierWarnings = delivery.ReviewWarnings?
            .Where(warning => warning.AttendanceEventId != delivery.PunchResult?.AttendanceEventId).ToArray();
        if (earlierWarnings is { Length: > 0 })
        {
            RecordReviewWarnings(earlierWarnings);
            var reviewCount = earlierWarnings.Count(warning => !warning.IsShortShiftTap);
            if (reviewCount > 0)
            {
                ResultDetail += $" / 先に保存した{reviewCount}件は管理者確認が必要です";
                SetFeedbackState(FeedbackState.Error);
                PlayErrorSound();
            }
        }
    }

    private void RecordReviewWarnings(IEnumerable<PunchResult> warnings)
    {
        foreach (var warning in warnings) AddServerPunchHistory(warning);
    }

    private void DisplayDeliveredPunch(PunchResult result)
    {
        var feedback = PunchResultFeedback.From(result);
        NetworkStatus = OnlineStatusForPendingPunches();
        ResultHeadline = result.ResultMessage;
        ResultDetail = feedback.Detail;
        EmployeeName = result.Employee?.Name ?? "-";
        LastEventType = FormatEventType(result.EventType);
        SetFeedbackState(feedback.RequiresReview ? FeedbackState.Error : FeedbackState.Success);
        if (feedback.RequiresReview) PlayErrorSound();
        else _playPunchTone(PunchTone.PunchRecorded);
        AddServerPunchHistory(result);
        ScheduleReadyReset();
    }

    private void DisplayRejectedPunch(PendingPunch pending, AttendanceApiException exception)
    {
        NetworkStatus = OnlineStatusForPendingPunches();
        EmployeeName = "-";
        LastEventType = "カードUID: " + pending.CardUid;

        if (exception.Code == "CARD_NOT_REGISTERED")
        {
            CopyCardUidToClipboard(pending.CardUid);
            ResultHeadline = "未登録カードです";
            ResultDetail = $"{pending.CardUid} をコピーしました";
            CardRegistrationMessage = "未登録カードUIDをコピーしました。管理画面に貼り付けてください。";
            SetFeedbackState(FeedbackState.Error);
            PlayErrorSound();
            AddHistory(pending.OccurredAt, "未登録", "-", "未登録カード", pending.CardUid);
            ScheduleReadyReset();
            return;
        }

        ResultHeadline = "打刻を受け付けできませんでした";
        ResultDetail = exception.Message;
        SetFeedbackState(FeedbackState.Error);
        PlayErrorSound();
        AddHistory(pending.OccurredAt, "-", "-", exception.Message, "拒否");
        ScheduleReadyReset();
    }

    private void DisplayQueueRemovalFailure(PendingPunch pending, PendingPunchDeliveryResult delivery)
    {
        if (delivery.Outcome == PendingPunchDeliveryOutcome.ReceiptSaveFailed)
        {
            NetworkStatus = "確認結果の保存エラー";
            ResultHeadline = "打刻原本は端末に保持しています";
            ResultDetail = $"{pending.OccurredAt:HH:mm:ss} / 確認結果を保存できません。管理者に確認してください";
            EmployeeName = "-";
            LastEventType = "要確認";
            SetFeedbackState(FeedbackState.Error);
            PlayErrorSound();
            ScheduleReadyReset();
            return;
        }
        NetworkStatus = "送信済・保存確認要";
        ResultHeadline = delivery.PunchResult?.ResultMessage ?? "打刻を確認しました";
        ResultDetail = $"{pending.OccurredAt:HH:mm:ss} / 未送信データの削除を確認できません";
        EmployeeName = delivery.PunchResult?.Employee?.Name ?? "-";
        LastEventType = delivery.PunchResult is null ? "-" : FormatEventType(delivery.PunchResult.EventType);
        SetFeedbackState(FeedbackState.Error);
        AddHistory(pending.OccurredAt, EmployeeName, LastEventType, "送信済・再送確認待ち", "要確認");
        ScheduleReadyReset();
    }

    private void DisplayDeferredPunch(PendingPunch pending, PendingPunchDeliveryResult delivery)
    {
        NetworkStatus = $"未送信 {PendingCount}件・自動再送待機";
        ResultHeadline = "打刻を一時保存しました";
        var waitingForEarlier = delivery.Outcome == PendingPunchDeliveryOutcome.WaitingForEarlierPunch;
        ResultDetail = waitingForEarlier
            ? $"{pending.OccurredAt:HH:mm:ss} / 先に保存した打刻の確認後、順番に送信します"
            : $"{pending.OccurredAt:HH:mm:ss} / 通信復旧後に自動再送します";
        EmployeeName = "-";
        LastEventType = "-";
        SetFeedbackState(FeedbackState.Offline);
        PlayOfflineSound();
        AddHistory(pending.OccurredAt, "-", "-", waitingForEarlier
            ? "先に保存した打刻の確認待ち"
            : delivery.ApiException?.Code ?? "通信またはサーバーエラー", "未送信");
        ScheduleReadyReset();
    }

    private void ScheduleReadyReset()
    {
        _readyResetTimer.Stop();
        _readyResetTimer.Interval = TimeSpan.FromSeconds(_shortShiftCandidate is null ? 5 : ShortShiftPromptSeconds);
        _readyResetTimer.Start();
    }

    private void RefreshPendingPunches()
    {
        OnPropertyChanged(nameof(PendingPunches));
        OnPropertyChanged(nameof(PendingCount));
    }

    private IReadOnlyList<PunchReviewReceipt> RefreshReviewReceipts()
    {
        try
        {
            var entries = _receiptStore.ReadAll();
            ReviewReceipts.Clear();
            foreach (var entry in entries.Reverse()) ReviewReceipts.Add(entry);
            var added = entries.Where(entry => _displayedReceipts.Add(entry.DedupeKey)).ToArray();
            foreach (var entry in added)
            {
                PunchHistory.Insert(0, new PunchHistoryItem(entry.TimeText, "氏名・カードUIDは保存していません", entry.WasShortShiftTap ? "再提示" : "要確認",
                    entry.ReasonText, entry.StatusText, entry.WasShortShiftTap ? entry.AttendanceEventId : null));
                while (PunchHistory.Count > 10) PunchHistory.RemoveAt(PunchHistory.Count - 1);
            }
            ReceiptStatus = entries.Count > 0
                ? $"確認結果 {entries.Count}件（再起動後も保持・詳細は保守パネル）"
                : "受付拒否・確認対象の保存結果はありません";
            return added;
        }
        catch
        {
            ReceiptStatus = "確認結果を読み取れません。元ファイルを保持し管理者へ連絡してください";
            return [];
        }
    }

    private string OnlineStatusForPendingPunches()
        => PendingCount > 0 ? $"オンライン・未送信 {PendingCount}件" : "オンライン";

    private static string FormatRetryDeferredDetail(PendingPunchDeliveryResult? lastResult)
    {
        if (lastResult?.ApiException is { } apiException)
        {
            return $"{apiException.Code}: {apiException.Message}。未送信データは保持しています。";
        }

        return "通信またはサーバーの応答を確認できません。未送信データは保持し、自動で再送します。";
    }

    private static string FormatClockDriftStatus(int clockDriftSeconds)
    {
        var absoluteSeconds = Math.Abs(clockDriftSeconds);
        var difference = clockDriftSeconds switch
        {
            > 0 => $"端末時計が{absoluteSeconds}秒進んでいます",
            < 0 => $"端末時計が{absoluteSeconds}秒遅れています",
            _ => "サーバー時刻と一致しています",
        };

        if (absoluteSeconds > ClockDriftErrorSeconds)
        {
            return $"前回の端末通信での時刻判定　要確認（{difference}）";
        }

        if (absoluteSeconds > ClockDriftWarningSeconds)
        {
            return $"前回の端末通信での時刻判定　注意（{difference}）";
        }

        return $"前回の端末通信での時刻判定　正常（{difference}）";
    }

    private void ResetToReady()
    {
        ClearShortShiftPrompt();
        ResultHeadline = CardRegistrationModeEnabled ? "登録するカードをかざしてください" : "カードをかざしてください";
        ResultDetail = CardRegistrationModeEnabled ? "打刻は記録されません。保守パネルを閉じると打刻に戻ります" : _deviceReader.StatusText;
        EmployeeName = "-";
        LastEventType = "-";
        SetFeedbackState(CardRegistrationModeEnabled ? FeedbackState.Registration : FeedbackState.Ready);
    }

    private async Task LoadRegistrationEmployeesAsync()
    {
        if (IsCardRegistrationBusy)
        {
            return;
        }

        IsCardRegistrationBusy = true;
        CardRegistrationMessage = "職員一覧を読み込み中です。";
        try
        {
            var employees = await _apiClient.GetCardRegistrationEmployeesAsync();
            RegistrationEmployees.Clear();
            foreach (var employee in employees)
            {
                RegistrationEmployees.Add(employee);
            }

            if (SelectedRegistrationEmployeeId is null && RegistrationEmployees.Count > 0)
            {
                SelectedRegistrationEmployeeId = RegistrationEmployees[0].Id;
            }

            CardRegistrationMessage = $"職員一覧を読み込みました（{RegistrationEmployees.Count}件）。";
        }
        catch (AttendanceApiException ex)
        {
            CardRegistrationMessage = ex.Message;
        }
        catch
        {
            CardRegistrationMessage = "職員一覧を読み込めませんでした。通信状態を確認してください。";
        }
        finally
        {
            IsCardRegistrationBusy = false;
        }
    }

    public void CopyLastCardUid()
    {
        if (string.IsNullOrWhiteSpace(LastCardUid) || LastCardUid == "-")
        {
            CardRegistrationMessage = "まだカードUIDが読み取られていません。";
            return;
        }

        CopyCardUidToClipboard(LastCardUid);
    }

    private void CopyCardUidToClipboard(string cardUid)
    {
        try
        {
            _setClipboardText(cardUid);
            CardRegistrationMessage = $"カードUID {cardUid} をコピーしました。";
        }
        catch
        {
            CardRegistrationMessage = $"カードUID {cardUid} を表示しました。コピーできない場合は手入力してください。";
        }
    }

    private void WireAndStartDeviceReader()
    {
        _deviceReader.CardScanned += async (_, cardUid) => await HandleScannedOnUiAsync(cardUid);
        _deviceReader.Start();
    }

    private static bool NeedsReaderReconnect(string status)
        => status.Contains("失敗", StringComparison.OrdinalIgnoreCase)
            || status.Contains("エラー", StringComparison.OrdinalIgnoreCase)
            || status.Contains("オープンできません", StringComparison.OrdinalIgnoreCase);

    private void AddServerPunchHistory(PunchResult result)
    {
        // A live response and the privacy-preserving receipt represent one physical tap.
        var receipt = PunchHistory.FirstOrDefault(item => item.ShortShiftEventId == result.AttendanceEventId);
        if (receipt is not null) PunchHistory.Remove(receipt);
        AddHistory(result.OccurredAt, result.Employee?.Name ?? "-", result.IsShortShiftTap ? "再提示" : FormatEventType(result.EventType),
            result.IsShortShiftTap ? PunchReviewReceipt.ShortShiftReason : result.ResultMessage,
            PunchResultFeedback.From(result).HistoryStatus, result.IsShortShiftTap ? result.AttendanceEventId : null);
    }

    private void AddHistory(DateTimeOffset occurredAt, string employeeName, string eventType, string result, string status, long? shortShiftEventId = null)
    {
        PunchHistory.Insert(0, new PunchHistoryItem(
            occurredAt.ToString("HH:mm:ss"),
            employeeName,
            eventType,
            result,
            status, shortShiftEventId));

        while (PunchHistory.Count > 10)
        {
            PunchHistory.RemoveAt(PunchHistory.Count - 1);
        }
    }

    private void UpdateClock()
    {
        var now = DateTime.Now;
        CurrentTimeText = now.ToString("yyyy/MM/dd HH:mm:ss");
        CurrentClockText = now.ToString("HH:mm:ss");
    }

    private void SetFeedbackState(FeedbackState state)
    {
        switch (state)
        {
            case FeedbackState.Sending:
                ResultGlyph = "…";
                FeedbackStateLabel = "送信中";
                FeedbackBackground = BrushFromRgb(239, 245, 255);
                FeedbackAccent = BrushFromRgb(39, 77, 135);
                OfflineWarningBackground = FeedbackBackground;
                break;
            case FeedbackState.Registration:
                ResultGlyph = "i";
                FeedbackStateLabel = "カード登録専用";
                FeedbackBackground = BrushFromRgb(245, 241, 252);
                FeedbackAccent = BrushFromRgb(90, 52, 132);
                OfflineWarningBackground = BrushFromRgb(245, 241, 252);
                break;
            case FeedbackState.Success:
                ResultGlyph = "✓";
                FeedbackStateLabel = "打刻完了";
                FeedbackBackground = BrushFromRgb(230, 250, 239);
                FeedbackAccent = BrushFromRgb(22, 128, 76);
                OfflineWarningBackground = BrushFromRgb(230, 250, 239);
                break;
            case FeedbackState.Error:
                ResultGlyph = "!";
                FeedbackStateLabel = "確認が必要";
                FeedbackBackground = BrushFromRgb(255, 235, 238);
                FeedbackAccent = BrushFromRgb(190, 45, 70);
                OfflineWarningBackground = BrushFromRgb(255, 235, 238);
                break;
            case FeedbackState.Offline:
                ResultGlyph = "!";
                FeedbackStateLabel = "オフライン保存";
                FeedbackBackground = BrushFromRgb(255, 247, 214);
                FeedbackAccent = BrushFromRgb(158, 108, 0);
                OfflineWarningBackground = BrushFromRgb(255, 236, 153);
                break;
            default:
                ResultGlyph = "●";
                FeedbackStateLabel = "待機中";
                FeedbackBackground = BrushFromRgb(247, 250, 255);
                FeedbackAccent = BrushFromRgb(111, 143, 229);
                OfflineWarningBackground = BrushFromRgb(238, 244, 255);
                break;
        }

        ResultPulseKey++;
    }

    private static SolidColorBrush BrushFromRgb(byte red, byte green, byte blue)
        => new(Color.FromRgb(red, green, blue));

    private static void PlayPunchTone(PunchTone tone)
    {
        if (tone == PunchTone.CardRead)
            PlayToneSequence(SystemSounds.Beep, (740, 65, 0));
        else
            PlayToneSequence(SystemSounds.Asterisk, (980, 100, 40), (1568, 150, 0));
    }

    private static void PlayErrorSound()
        => PlayToneSequence(SystemSounds.Hand, (260, 160, 80), (260, 160, 80), (196, 360, 0));

    private static void PlayOfflineSound()
        => PlayToneSequence(SystemSounds.Exclamation, (520, 220, 70), (392, 520, 0));

    private static void PlayToneSequence(SystemSound fallbackSound, params (int Frequency, int Duration, int Pause)[] tones)
    {
        _ = Task.Run(() =>
        {
            try
            {
                foreach (var tone in tones)
                {
                    Console.Beep(tone.Frequency, tone.Duration);
                    if (tone.Pause > 0)
                    {
                        Thread.Sleep(tone.Pause);
                    }
                }
            }
            catch
            {
                fallbackSound.Play();
            }
        });
    }

    private static string FormatEventType(string? eventType) => eventType switch
    {
        "CLOCK_IN" => "出勤",
        "CLOCK_OUT" => "退勤",
        _ => eventType ?? "-"
    };

    private void SetField<T>(ref T field, T value, [CallerMemberName] string? propertyName = null)
    {
        if (EqualityComparer<T>.Default.Equals(field, value))
        {
            return;
        }

        field = value;
        OnPropertyChanged(propertyName);
    }

    private void OnPropertyChanged([CallerMemberName] string? propertyName = null)
        => PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(propertyName));
}

internal enum FeedbackState
{
    Ready,
    Sending,
    Registration,
    Success,
    Error,
    Offline,
}

internal enum PunchTone { CardRead, PunchRecorded }

public sealed record PunchHistoryItem(
    string TimeText,
    string EmployeeName,
    string EventTypeText,
    string ResultText,
    string StatusText,
    long? ShortShiftEventId = null);
