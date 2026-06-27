using System.ComponentModel;
using System.Collections.ObjectModel;
using System.IO;
using System.Media;
using System.Runtime.CompilerServices;
using System.Windows;
using System.Windows.Media;
using System.Windows.Threading;
using StaffHub.PunchApp.Models;
using StaffHub.PunchApp.Services;

namespace StaffHub.PunchApp.ViewModels;

public sealed class MainWindowViewModel : INotifyPropertyChanged, IDisposable
{
    private ICardReaderService _deviceReader;
    private readonly MockCardReaderService _mockReader;
    private readonly OfflinePunchQueue _offlineQueue;
    private readonly AttendanceApiClient _apiClient;
    private readonly DispatcherTimer _clockTimer;
    private readonly DispatcherTimer _readyResetTimer;

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
    private string _lastCardUid = "-";
    private string _cardRegistrationMessage = "登録時はONにすると打刻せずUIDだけ読み取ります";
    private bool _cardRegistrationModeEnabled;
    private long? _selectedRegistrationEmployeeId;
    private bool _isCardRegistrationBusy;
    private int _resultPulseKey;

    public MainWindowViewModel()
    {
        Settings = AppSettingsLoader.Load();
        _mockReader = new MockCardReaderService();
        _deviceReader = CreateDeviceReader();
        _offlineQueue = new OfflinePunchQueue();
        _apiClient = new AttendanceApiClient(Settings);

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
        UpdateClock();
        ReaderStatus = _deviceReader.StatusText;
    }

    private ICardReaderService CreateDeviceReader()
    {
        const string felicaDirectory = @"C:\Program Files\Common Files\Sony Shared\FeliCaLibrary";
        if (File.Exists(Path.Combine(felicaDirectory, "felica.dll")))
        {
            var currentPath = Environment.GetEnvironmentVariable("PATH") ?? string.Empty;
            if (!currentPath.Contains(felicaDirectory, StringComparison.OrdinalIgnoreCase))
            {
                Environment.SetEnvironmentVariable("PATH", currentPath + ";" + felicaDirectory);
            }
            return new FelicaLibraryCardReaderService(Settings);
        }

        return new RcS380CardReaderService(Settings);
    }

    public event PropertyChangedEventHandler? PropertyChanged;

    public AppSettings Settings { get; }
    public IReadOnlyList<PendingPunch> PendingPunches => _offlineQueue.Items;
    public ObservableCollection<PunchHistoryItem> PunchHistory { get; } = [];
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
            _readyResetTimer.Stop();
            if (value)
            {
                ResultHeadline = "カード登録モード";
                ResultDetail = "カードをかざすとUIDをコピーします";
                EmployeeName = "打刻は記録されません";
                LastEventType = "-";
                SetFeedbackState(FeedbackState.Ready);
                _ = LoadRegistrationEmployeesAsync();
            }
            else
            {
                ResetToReady();
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

    public async Task RetryPendingAsync()
    {
        var items = PendingPunches.ToList();
        foreach (var item in items)
        {
            try
            {
                await _apiClient.SendPunchAsync(item.CardUid);
                _offlineQueue.Remove(item);
            }
            catch
            {
                NetworkStatus = "オフライン";
                break;
            }
        }

        OnPropertyChanged(nameof(PendingPunches));
        OnPropertyChanged(nameof(PendingCount));
    }

    public async Task RestartReaderAsync()
    {
        _readyResetTimer.Stop();
        ResetToReady();

        if (!NeedsReaderReconnect(_deviceReader.StatusText))
        {
            ReaderStatus = _deviceReader.StatusText;
            return;
        }

        ReaderStatus = "カードリーダーを再接続中";

        await Task.Run(() =>
        {
            _deviceReader.Dispose();
            Thread.Sleep(500);
            _deviceReader = CreateDeviceReader();
            WireAndStartDeviceReader();
        });

        await Task.Delay(700);
        ReaderStatus = _deviceReader.StatusText;
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
            ResultHeadline = "カード登録完了";
            ResultDetail = result.CardUid;
            EmployeeName = result.EmployeeName;
            LastEventType = "登録済";
            SetFeedbackState(FeedbackState.Success);
            PlaySuccessSound();
            AddHistory(DateTimeOffset.Now, result.EmployeeName, "カード登録", result.CardUid, "登録済");
            ScheduleReadyReset();
        }
        catch (AttendanceApiException ex)
        {
            CardRegistrationMessage = ex.Message;
            ResultHeadline = "カード登録できませんでした";
            ResultDetail = ex.Message;
            EmployeeName = "-";
            LastEventType = "登録エラー";
            SetFeedbackState(FeedbackState.Error);
            PlayErrorSound();
            ScheduleReadyReset();
        }
        catch
        {
            CardRegistrationMessage = "通信エラーのためカード登録できませんでした。";
            ResultHeadline = "通信エラー";
            ResultDetail = "カード登録できませんでした";
            EmployeeName = "-";
            LastEventType = "登録エラー";
            SetFeedbackState(FeedbackState.Offline);
            PlayOfflineSound();
            ScheduleReadyReset();
        }
        finally
        {
            IsCardRegistrationBusy = false;
        }
    }

    public void Dispose()
    {
        _clockTimer.Stop();
        _readyResetTimer.Stop();
        _deviceReader.Dispose();
        _mockReader.Dispose();
    }

    private async Task HandleScannedOnUiAsync(string cardUid)
    {
        await Application.Current.Dispatcher.InvokeAsync(async () =>
        {
            await HandleScannedAsync(cardUid);
            ReaderStatus = _deviceReader.StatusText;
        });
    }

    private async Task HandleScannedAsync(string cardUid)
    {
        LastCardUid = cardUid;
        SimulatedCardUid = cardUid;

        if (CardRegistrationModeEnabled)
        {
            CopyCardUidToClipboard(cardUid);
            NetworkStatus = "オンライン";
            ResultHeadline = "カードUIDを読み取りました";
            ResultDetail = $"{cardUid} をコピーしました";
            EmployeeName = "管理画面のカードUID欄へ貼り付けてください";
            LastEventType = "登録用読取";
            SetFeedbackState(FeedbackState.Success);
            PlaySuccessSound();
            AddHistory(DateTimeOffset.Now, "登録用", "UID読取", "カードUIDをコピー", cardUid);
            ScheduleReadyReset();
            return;
        }

        ResultHeadline = "カードを読み取りました";
        ResultDetail = "打刻を送信中です";
        EmployeeName = "確認中";
        LastEventType = "-";
        PlayScanAcceptedSound();

        try
        {
            var result = await _apiClient.SendPunchAsync(cardUid);
            NetworkStatus = "オンライン";
            ResultHeadline = result.ResultMessage;
            ResultDetail = $"{result.OccurredAt:HH:mm:ss}";
            EmployeeName = result.Employee?.Name ?? "-";
            LastEventType = FormatEventType(result.EventType);
            SetFeedbackState(FeedbackState.Success);
            AddHistory(result.OccurredAt, result.Employee?.Name ?? "-", FormatEventType(result.EventType), result.ResultMessage, "送信済");
            ScheduleReadyReset();
        }
        catch (AttendanceApiException ex)
        {
            NetworkStatus = "オンライン";
            EmployeeName = "-";
            LastEventType = "カードUID: " + cardUid;

            if (ex.Code == "CARD_NOT_REGISTERED")
            {
                CopyCardUidToClipboard(cardUid);
                ResultHeadline = "未登録カードです";
                ResultDetail = $"{cardUid} をコピーしました";
                CardRegistrationMessage = "未登録カードUIDをコピーしました。管理画面に貼り付けてください。";
                SetFeedbackState(FeedbackState.Error);
                PlayErrorSound();
                AddHistory(DateTimeOffset.Now, "未登録", "-", "未登録カード", cardUid);
                ScheduleReadyReset();
                return;
            }

            ResultHeadline = "打刻を受け付けできませんでした";
            ResultDetail = ex.Message;
            SetFeedbackState(FeedbackState.Error);
            PlayErrorSound();
            AddHistory(DateTimeOffset.Now, "-", "-", ex.Message, "拒否");
            ScheduleReadyReset();
        }
        catch
        {
            NetworkStatus = "オフライン";
            var pending = new PendingPunch(cardUid, DateTimeOffset.Now, $"{Settings.DeviceCode}-{Guid.NewGuid():N}");
            _offlineQueue.Enqueue(pending);
            ResultHeadline = "通信できないため一時保存しました";
            ResultDetail = $"{pending.OccurredAt:HH:mm:ss} / 未送信キューへ退避";
            EmployeeName = "-";
            LastEventType = "-";
            SetFeedbackState(FeedbackState.Offline);
            PlayOfflineSound();
            AddHistory(pending.OccurredAt, "-", "-", "通信失敗", "未送信");
            OnPropertyChanged(nameof(PendingPunches));
            OnPropertyChanged(nameof(PendingCount));
            ScheduleReadyReset();
        }
    }

    private void ScheduleReadyReset()
    {
        _readyResetTimer.Stop();
        _readyResetTimer.Start();
    }

    private void ResetToReady()
    {
        ResultHeadline = "カードをかざしてください";
        ResultDetail = CardRegistrationModeEnabled ? "登録モード中: 打刻せずUIDだけ読み取ります" : "RC-S380 の読取待機中";
        EmployeeName = "-";
        LastEventType = "-";
        SetFeedbackState(FeedbackState.Ready);
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
            Clipboard.SetText(cardUid);
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

    private void AddHistory(DateTimeOffset occurredAt, string employeeName, string eventType, string result, string status)
    {
        PunchHistory.Insert(0, new PunchHistoryItem(
            occurredAt.ToString("HH:mm:ss"),
            employeeName,
            eventType,
            result,
            status));

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

    private static void PlayScanAcceptedSound()
        => PlayToneSequence(SystemSounds.Asterisk, (980, 120, 45), (1568, 520, 0));

    private static void PlaySuccessSound()
        => PlayToneSequence(SystemSounds.Asterisk, (980, 120, 45), (1568, 520, 0));

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
    Success,
    Error,
    Offline,
}

public sealed record PunchHistoryItem(
    string TimeText,
    string EmployeeName,
    string EventTypeText,
    string ResultText,
    string StatusText);
