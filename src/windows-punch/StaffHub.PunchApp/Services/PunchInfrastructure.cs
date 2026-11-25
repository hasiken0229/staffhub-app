using System.IO;
using System.Net;
using System.Net.Http;
using System.Net.Http.Json;
using System.Text.Json;
using StaffHub.PunchApp.Models;

namespace StaffHub.PunchApp.Services;

public interface ICardReaderService : IDisposable
{
    event EventHandler<string>? CardScanned;
    string StatusText { get; }
    void Start();
}

public interface IAttendancePunchSender
{
    Task<PunchResult> SendPunchAsync(PendingPunch pending, CancellationToken cancellationToken = default);
}

public sealed class MockCardReaderService : ICardReaderService
{
    public event EventHandler<string>? CardScanned;

    public string StatusText => "模擬カードリーダー";

    public void Start()
    {
    }

    public void SimulateScan(string cardUid)
    {
        if (!string.IsNullOrWhiteSpace(cardUid))
        {
            CardScanned?.Invoke(this, cardUid.Trim());
        }
    }

    public void Dispose()
    {
    }
}

public sealed class OfflinePunchQueue
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        WriteIndented = true,
    };

    private readonly object _sync = new();
    private readonly string _storagePath;
    private List<PendingPunch> _items;

    public OfflinePunchQueue(string? storagePath = null)
    {
        _storagePath = Path.GetFullPath(storagePath ?? DefaultStoragePath());
        _items = WithStorageLock(() => LoadItems(allowMissing: true));
    }

    public IReadOnlyList<PendingPunch> Items
    {
        get
        {
            lock (_sync)
            {
                return _items.ToArray();
            }
        }
    }

    public void Enqueue(PendingPunch item)
    {
        ArgumentNullException.ThrowIfNull(item);
        ValidateItem(item);
        lock (_sync)
        {
            WithStorageLock(() =>
            {
                // Another process/instance may have saved since this instance last read.
                // Never overwrite the file using an old in-memory snapshot.
                var current = LoadItems(allowMissing: _items.Count == 0);
                if (!current.Any(existing => string.Equals(existing.DedupeKey, item.DedupeKey, StringComparison.Ordinal)))
                {
                    current.Add(item);
                    PersistLocked(current);
                }
                _items = current;
                return true;
            });
        }
    }

    public void Remove(PendingPunch item)
    {
        ArgumentNullException.ThrowIfNull(item);
        lock (_sync)
        {
            WithStorageLock(() =>
            {
                var current = LoadItems(allowMissing: _items.Count == 0);
                var removed = current.RemoveAll(existing =>
                    string.Equals(existing.DedupeKey, item.DedupeKey, StringComparison.Ordinal));
                if (removed > 0)
                {
                    PersistLocked(current);
                }
                // A failed save leaves the last good snapshot (including its order) intact.
                _items = current;
                return true;
            });
        }
    }

    public static string DefaultStoragePath()
        => Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "StaffHub",
            "PunchApp",
            "pending-punches.json");

    private List<PendingPunch> LoadItems(bool allowMissing)
    {
        try
        {
            // File.Exists also returns false on access errors. Only a genuinely missing
            // file is an empty initial queue; unreadable/corrupt data must never become 0.
            var json = File.ReadAllText(_storagePath);
            var items = JsonSerializer.Deserialize<List<PendingPunch>>(json, JsonOptions)
                ?? throw new InvalidDataException("未送信キューの内容を確認できません。元ファイルを保持してください。");
            var keys = new HashSet<string>(StringComparer.Ordinal);
            foreach (var item in items)
            {
                ValidateItem(item);
                if (!keys.Add(item.DedupeKey))
                    throw new InvalidDataException("未送信キューに重複があります。元ファイルを保持してください。");
            }
            return items;
        }
        catch (FileNotFoundException) when (allowMissing)
        {
            return [];
        }
        catch (DirectoryNotFoundException) when (allowMissing)
        {
            return [];
        }
        catch (JsonException exception)
        {
            // Do not rename, repair or discard an unverified queue during normal startup.
            throw new InvalidDataException("未送信キューを読み取れません。元ファイルを保持してください。", exception);
        }
    }

    private static void ValidateItem(PendingPunch? item)
    {
        if (item is null || string.IsNullOrWhiteSpace(item.CardUid)
            || string.IsNullOrWhiteSpace(item.DedupeKey) || item.OccurredAt == default)
        {
            throw new InvalidDataException("未送信キューに確認が必要な打刻があります。元ファイルを保持してください。");
        }
    }

    private T WithStorageLock<T>(Func<T> operation)
    {
        var directory = Path.GetDirectoryName(_storagePath)
            ?? throw new InvalidOperationException("未送信キューの保存先が不正です。");
        Directory.CreateDirectory(directory);
        var started = Environment.TickCount64;
        FileStream storageLock;
        while (true)
        {
            try
            {
                storageLock = new FileStream(_storagePath + ".write.lock", FileMode.OpenOrCreate,
                    FileAccess.ReadWrite, FileShare.None);
                break;
            }
            catch (IOException exception) when (IsSharingViolation(exception)
                && Environment.TickCount64 - started < 2000)
            {
                Thread.Sleep(20);
            }
        }
        // A sidecar handle covers other Windows sessions too. Keep this outside network I/O.
        // Never delete a lock file: deleting it could split contenders across two files.
        using (storageLock) return operation();
    }

    internal static bool IsSharingViolation(IOException exception)
        => (exception.HResult & 0xffff) is 32 or 33;

    private void PersistLocked(List<PendingPunch> items)
    {
        var temporaryPath = _storagePath + $".{Guid.NewGuid():N}.tmp";
        try
        {
            using (var stream = new FileStream(temporaryPath, FileMode.CreateNew,
                FileAccess.Write, FileShare.None, 4096, FileOptions.WriteThrough))
            {
                JsonSerializer.Serialize(stream, items, JsonOptions);
                stream.Flush(flushToDisk: true);
            }
            File.Move(temporaryPath, _storagePath, overwrite: true);
        }
        finally
        {
            // Only our own incomplete temporary file; never the original queue.
            try { File.Delete(temporaryPath); }
            catch (IOException) { }
            catch (UnauthorizedAccessException) { }
        }
    }
}

public sealed class AttendanceApiException : Exception
{
    public AttendanceApiException(HttpStatusCode statusCode, string code, string message)
        : base(message)
    {
        StatusCode = statusCode;
        Code = code;
    }

    public HttpStatusCode StatusCode { get; }
    public string Code { get; }

    // Only these responses are known to have been recorded as a rejected attendance event
    // by the API. All other failures stay queued so a temporary server, authentication, or
    // configuration problem cannot discard an employee's punch.
    public bool IsDeterministicRejection
        => StatusCode == HttpStatusCode.BadRequest
            && Code is "CARD_NOT_REGISTERED" or "TOO_MANY_PUNCHES" or "SHORT_INTERVAL_PUNCH";
}

public sealed class AttendanceApiClient : IAttendancePunchSender
{
    private readonly HttpClient _httpClient;
    private readonly AppSettings _settings;
    private readonly TimeProvider _clock;
    private ServerClockSample? _serverClock;
    // Only unsynchronized scans need this process-local stopwatch. The queue stores a boolean,
    // and a restarted process naturally cannot claim to know how much real time passed.
    private readonly System.Collections.Concurrent.ConcurrentDictionary<string, long> _unsyncedCaptures = new();

    public AttendanceApiClient(AppSettings settings, HttpClient? httpClient = null, TimeProvider? clock = null)
    {
        _settings = settings;
        _clock = clock ?? TimeProvider.System;
        _httpClient = httpClient ?? new HttpClient { Timeout = TimeSpan.FromSeconds(15) };
        _httpClient.BaseAddress = new Uri(settings.ApiBaseUrl.TrimEnd('/') + "/");
    }

    private static string ApplicationVersion
        => typeof(AttendanceApiClient).Assembly.GetName().Version?.ToString() ?? "unknown";

    public PendingPunch CreatePendingPunch(string cardUid, DateTimeOffset? occurredAt = null)
    {
        var sample = Volatile.Read(ref _serverClock);
        var capturedAt = occurredAt ?? _clock.GetLocalNow();
        // Monotonic elapsed time continues independently of manual changes to the OS clock.
        // An explicitly supplied historical timestamp has no simultaneous clock observation.
        DateTimeOffset? reference = sample is not null && occurredAt is null
            ? sample.ServerTime + _clock.GetElapsedTime(sample.Timestamp) : null;
        var pending = new PendingPunch(cardUid, capturedAt, $"{_settings.DeviceCode}-{Guid.NewGuid():N}", reference);
        if (reference is null && occurredAt is null)
        {
            _unsyncedCaptures[pending.DedupeKey] = _clock.GetTimestamp();
            pending = pending with { AwaitingClockReference = true };
        }
        return pending;
    }

    public Task<PunchResult> SendPunchAsync(string cardUid, CancellationToken cancellationToken = default)
        => SendPunchAsync(CreatePendingPunch(cardUid), cancellationToken);

    public async Task<PunchResult> SendPunchAsync(PendingPunch pending, CancellationToken cancellationToken = default)
    {
        long? ageSeconds = pending.AwaitingClockReference && _unsyncedCaptures.TryGetValue(pending.DedupeKey, out var captured)
            ? (long)_clock.GetElapsedTime(captured).TotalSeconds : null;
        var request = new PunchRequest(
            _settings.DeviceCode,
            _settings.DeviceSecret,
            pending.CardUid,
            pending.OccurredAt,
            pending.DedupeKey,
            ApplicationVersion,
            pending.ClockReferenceAt,
            _clock.GetLocalNow())
        {
            ConfirmShortShift = pending.ConfirmShortShift,
            CaptureAgeSeconds = ageSeconds,
            CaptureClockUnknown = pending.AwaitingClockReference && ageSeconds is null,
        };

        var response = await _httpClient.PostAsJsonAsync("api/attendance/punch", request, cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            var failure = await CreateApiExceptionAsync(response, cancellationToken, "打刻APIの呼び出しに失敗しました。");
            if (failure.IsDeterministicRejection) _unsyncedCaptures.TryRemove(pending.DedupeKey, out _);
            throw failure;
        }

        var body = await response.Content.ReadFromJsonAsync<ApiEnvelope<PunchResult>>(cancellationToken: cancellationToken);
        var result = body?.Data ?? throw new InvalidOperationException("打刻応答の解析に失敗しました。");
        _unsyncedCaptures.TryRemove(pending.DedupeKey, out _);
        return result;
    }

    public async Task<TodayDeviceReceipts> GetTodayReceiptsAsync(CancellationToken cancellationToken)
    {
        var credentials = new DeviceAuthRequest(_settings.DeviceCode, _settings.DeviceSecret);
        using var response = await _httpClient.PostAsJsonAsync("api/attendance/devices/today-receipts", credentials, cancellationToken);
        if (!response.IsSuccessStatusCode)
            throw await CreateApiExceptionAsync(response, cancellationToken, "この端末の受付記録を取得できませんでした。");
        var body = await response.Content.ReadFromJsonAsync<ApiEnvelope<TodayDeviceReceipts>>(cancellationToken: cancellationToken);
        return body?.Data ?? throw new InvalidOperationException("受付記録を読み取れませんでした。");
    }

    public async Task<HeartbeatResult> SendHeartbeatAsync(
        int pendingOfflineCount,
        string readerStatus,
        CancellationToken cancellationToken = default)
    {
        var started = _clock.GetTimestamp();
        var request = new HeartbeatRequest(
            _settings.DeviceCode,
            _settings.DeviceSecret,
            ApplicationVersion,
            _clock.GetLocalNow(),
            Math.Max(0, pendingOfflineCount),
            string.IsNullOrWhiteSpace(readerStatus) ? "状態不明" : readerStatus.Trim());
        var response = await _httpClient.PostAsJsonAsync("api/attendance/devices/heartbeat", request, cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            throw await CreateApiExceptionAsync(response, cancellationToken, "端末状態の送信に失敗しました。");
        }

        var body = await response.Content.ReadFromJsonAsync<ApiEnvelope<HeartbeatResult>>(cancellationToken: cancellationToken);
        var result = body?.Data ?? throw new InvalidOperationException("端末状態応答の解析に失敗しました。");
        var finished = _clock.GetTimestamp();
        var roundTrip = _clock.GetElapsedTime(started, finished);
        if (result.Success && result.DeviceActive && result.ServerTime != default && roundTrip < TimeSpan.FromSeconds(30))
        {
            // The server timestamp was taken within this round trip; estimate the return leg.
            var estimateAtReceipt = result.ServerTime + TimeSpan.FromTicks(roundTrip.Ticks / 2);
            Volatile.Write(ref _serverClock, new ServerClockSample(estimateAtReceipt, finished));
        }
        return result;
    }

    public async Task<IReadOnlyList<RegistrationEmployee>> GetCardRegistrationEmployeesAsync(CancellationToken cancellationToken = default)
    {
        var request = new DeviceAuthRequest(_settings.DeviceCode, _settings.DeviceSecret);
        var response = await _httpClient.PostAsJsonAsync("api/attendance/card-registration/employees", request, cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            throw await CreateApiExceptionAsync(response, cancellationToken);
        }

        var body = await response.Content.ReadFromJsonAsync<ApiEnvelope<List<RegistrationEmployee>>>(cancellationToken: cancellationToken);
        return body?.Data ?? [];
    }

    public async Task<CardAssignmentResult> AssignCardAsync(long employeeId, string cardUid, CancellationToken cancellationToken = default)
    {
        var request = new CardAssignmentRequest(_settings.DeviceCode, _settings.DeviceSecret, employeeId, cardUid);
        var response = await _httpClient.PostAsJsonAsync("api/attendance/card-registration/assign", request, cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            throw await CreateApiExceptionAsync(response, cancellationToken);
        }

        var body = await response.Content.ReadFromJsonAsync<ApiEnvelope<CardAssignmentResult>>(cancellationToken: cancellationToken);
        return body?.Data ?? throw new InvalidOperationException("カード登録応答の解析に失敗しました。");
    }

    private static async Task<AttendanceApiException> CreateApiExceptionAsync(
        HttpResponseMessage response,
        CancellationToken cancellationToken,
        string fallbackMessage = "APIの呼び出しに失敗しました。")
    {
        try
        {
            var error = await response.Content.ReadFromJsonAsync<ApiErrorEnvelope>(cancellationToken: cancellationToken);
            var code = error?.Error?.Code ?? "API_ERROR";
            var message = error?.Error?.Message ?? fallbackMessage;
            return new AttendanceApiException(response.StatusCode, code, message);
        }
        catch (JsonException)
        {
            return new AttendanceApiException(response.StatusCode, "API_ERROR", fallbackMessage);
        }
        catch (NotSupportedException)
        {
            return new AttendanceApiException(response.StatusCode, "API_ERROR", fallbackMessage);
        }
    }

    private sealed class ApiEnvelope<T>
    {
        public T? Data { get; set; }
    }

    private sealed class ApiErrorEnvelope
    {
        public ApiErrorBody? Error { get; set; }
    }

    private sealed class ApiErrorBody
    {
        public string? Code { get; set; }
        public string? Message { get; set; }
    }

    private sealed record DeviceAuthRequest(
        string DeviceCode,
        string DeviceSecret);

    private sealed record ServerClockSample(DateTimeOffset ServerTime, long Timestamp);

    private sealed record HeartbeatRequest(
        string DeviceCode,
        string DeviceSecret,
        string? AppVersion,
        DateTimeOffset LastSeenAt,
        int PendingOfflineCount,
        string ReaderStatus);

    private sealed record CardAssignmentRequest(
        string DeviceCode,
        string DeviceSecret,
        long EmployeeId,
        string CardUid);
}
