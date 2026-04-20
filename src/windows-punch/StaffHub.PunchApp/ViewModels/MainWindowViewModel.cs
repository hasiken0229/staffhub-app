using System.ComponentModel;
using System.Collections.ObjectModel;
using System.IO;
using System.Runtime.CompilerServices;
using System.Windows;
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

    private string _currentTimeText = DateTime.Now.ToString("yyyy/MM/dd HH:mm:ss");
    private string _currentClockText = DateTime.Now.ToString("HH:mm:ss");
    private string _networkStatus = "オンライン";
    private string _resultHeadline = "カードをかざしてください";
    private string _resultDetail = "RC-S380 の読取待機中";
    private string _employeeName = "-";
    private string _lastEventType = "-";
    private string _simulatedCardUid = "0123456789ABCDEF";
    private string _readerStatus = "初期化中";
    private string _lastCardUid = "-";

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
        ResultHeadline = "カードをかざしてください";
        ResultDetail = "RC-S380 の読取待機中";

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

    public void Dispose()
    {
        _clockTimer.Stop();
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

        try
        {
            var result = await _apiClient.SendPunchAsync(cardUid);
            NetworkStatus = "オンライン";
            ResultHeadline = result.ResultMessage;
            ResultDetail = $"{result.OccurredAt:HH:mm:ss}";
            EmployeeName = result.Employee?.Name ?? "-";
            LastEventType = FormatEventType(result.EventType);
            AddHistory(result.OccurredAt, result.Employee?.Name ?? "-", FormatEventType(result.EventType), result.ResultMessage, "送信済");
        }
        catch (AttendanceApiException ex)
        {
            NetworkStatus = "オンライン";
            EmployeeName = "-";
            LastEventType = "カードUID: " + cardUid;
            SimulatedCardUid = cardUid;

            if (ex.Code == "CARD_NOT_REGISTERED")
            {
                ResultHeadline = "未登録カードです";
                ResultDetail = $"{cardUid} を管理画面で登録してください";
                AddHistory(DateTimeOffset.Now, "未登録", "-", "未登録カード", cardUid);
                return;
            }

            ResultHeadline = "打刻を受け付けできませんでした";
            ResultDetail = ex.Message;
            AddHistory(DateTimeOffset.Now, "-", "-", ex.Message, "拒否");
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
            AddHistory(pending.OccurredAt, "-", "-", "通信失敗", "未送信");
            OnPropertyChanged(nameof(PendingPunches));
            OnPropertyChanged(nameof(PendingCount));
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

public sealed record PunchHistoryItem(
    string TimeText,
    string EmployeeName,
    string EventTypeText,
    string ResultText,
    string StatusText);
