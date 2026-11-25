using System.IO;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace StaffHub.PunchApp.Services;

public sealed record PunchReviewReceipt(string DedupeKey, DateTimeOffset OccurredAt,
    DateTimeOffset ReceivedAt, string Code, long? AttendanceEventId)
{
    public const string ShortShiftStatus = "再提示時は退勤未確定";
    public const string ShortShiftReason = "再提示した時点では、退勤は未確定でした。この時刻に退勤した場合は勤怠履歴を確認してください。";
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingDefault)]
    public bool WasShortShiftTap { get; init; }

    [JsonIgnore] public string TimeText => OccurredAt.ToString("MM/dd HH:mm:ss");
    [JsonIgnore] public string ReasonText => Code switch
    {
        "SERVER_REVIEW_REQUIRED" when WasShortShiftTap => ShortShiftReason,
        "CARD_NOT_REGISTERED" => "未登録カードのため受付拒否",
        "TOO_MANY_PUNCHES" => "当日の打刻回数超過のため受付拒否",
        "SHORT_INTERVAL_PUNCH" => "直前の打刻から3分以内のため受付拒否",
        _ => "原本保存済み・管理者による勤怠確認が必要",
    };
    [JsonIgnore] public string StatusText => WasShortShiftTap ? ShortShiftStatus
        : Code == "SERVER_REVIEW_REQUIRED" ? "原本保存済・要確認" : "受付拒否・要確認";
    [JsonIgnore] public string ReferenceText => AttendanceEventId is > 0 ? $"イベントID: {AttendanceEventId}" : $"送信キー: {DedupeKey}";
}

public interface IPunchReviewReceiptStore
{
    IReadOnlyList<PunchReviewReceipt> ReadAll();
    void Record(PendingPunchDeliveryResult result);
}

/// <summary>Minimal confirmation ledger. Never stores card UID, employee name or free-form server text.</summary>
public sealed class PunchReviewReceiptStore : IPunchReviewReceiptStore
{
    private static readonly JsonSerializerOptions Options = new(JsonSerializerDefaults.Web) { WriteIndented = true };
    private readonly string _path;
    private readonly Func<DateTimeOffset> _now;
    private readonly object _sync = new();
    private bool _hasSeenFile;

    public PunchReviewReceiptStore(string path, Func<DateTimeOffset>? now = null)
    {
        _path = Path.GetFullPath(path);
        _now = now ?? (() => DateTimeOffset.Now);
    }

    public static string DefaultStoragePath() => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "StaffHub", "PunchApp", "punch-review-receipts.json");

    public IReadOnlyList<PunchReviewReceipt> ReadAll() => WithLock(() => Load().ToArray());

    public void Record(PendingPunchDeliveryResult result)
    {
        if (result.Pending.ConfirmShortShift && result.PunchResult is { ResultType: "SUCCESS", EventType: "CLOCK_OUT" } confirmed)
        {
            WithLock(() =>
            {
                var entries = Load();
                var removed = entries.RemoveAll(entry => entry.DedupeKey == result.Pending.DedupeKey
                    && entry.AttendanceEventId == confirmed.AttendanceEventId && entry.OccurredAt == result.Pending.OccurredAt);
                if (removed > 0) Persist(entries);
                return true;
            });
            return;
        }
        var code = result.Outcome == PendingPunchDeliveryOutcome.DeterministicallyRejected
            && result.ApiException?.IsDeterministicRejection == true ? result.ApiException.Code
            : string.Equals(result.PunchResult?.ResultType, "WARNING", StringComparison.OrdinalIgnoreCase)
                ? "SERVER_REVIEW_REQUIRED" : null;
        if (code is null) return;
        var shortShift = result.PunchResult?.IsShortShiftTap == true;
        WithLock(() =>
        {
            var entries = Load();
            var existing = entries.FirstOrDefault(item => string.Equals(item.DedupeKey, result.Pending.DedupeKey, StringComparison.Ordinal));
            if (existing is not null)
            {
                if (existing.OccurredAt != result.Pending.OccurredAt || existing.Code != code)
                    throw new InvalidDataException("同じキーの確認結果が一致しません。原本を保持してください。");
                if (existing.WasShortShiftTap != shortShift && (shortShift || result.PunchResult?.ReasonCode == "SHORT_SHIFT_REVIEW_ONLY"))
                {
                    entries[entries.IndexOf(existing)] = existing with { WasShortShiftTap = shortShift };
                    Persist(entries);
                }
                return true;
            }
            var now = _now();
            entries.Add(new PunchReviewReceipt(result.Pending.DedupeKey, result.Pending.OccurredAt, now,
                code, result.PunchResult?.AttendanceEventId) { WasShortShiftTap = shortShift });
            // Age is based on receipt time, not an old offline punch's occurredAt.
            entries = entries.Where(item => item.ReceivedAt >= now.AddDays(-30)).TakeLast(200).ToList();
            Persist(entries);
            return true;
        });
    }

    private List<PunchReviewReceipt> Load()
    {
        List<PunchReviewReceipt> entries;
        try { entries = JsonSerializer.Deserialize<List<PunchReviewReceipt>>(File.ReadAllText(_path), Options)
                ?? throw new InvalidDataException("確認結果の台帳が不正です。元ファイルを保持してください。"); }
        catch (FileNotFoundException) when (!_hasSeenFile) { return []; }
        catch (JsonException error) { throw new InvalidDataException("確認結果を読み取れません。元ファイルを保持してください。", error); }
        var keys = new HashSet<string>(StringComparer.Ordinal);
        foreach (var item in entries)
        {
            if (item is null || string.IsNullOrWhiteSpace(item.DedupeKey) || item.OccurredAt == default
                || item.ReceivedAt == default || !keys.Add(item.DedupeKey)
                || item.Code is not ("CARD_NOT_REGISTERED" or "TOO_MANY_PUNCHES" or "SHORT_INTERVAL_PUNCH" or "SERVER_REVIEW_REQUIRED"))
                throw new InvalidDataException("確認結果の内容を確認できません。元ファイルを保持してください。");
        }
        _hasSeenFile = true;
        return entries;
    }

    private T WithLock<T>(Func<T> action)
    {
        lock (_sync)
        {
            Directory.CreateDirectory(Path.GetDirectoryName(_path)!);
            var started = Environment.TickCount64;
            FileStream handle;
            while (true)
            {
                try { handle = new FileStream(_path + ".write.lock", FileMode.OpenOrCreate, FileAccess.ReadWrite, FileShare.None); break; }
                catch (IOException e) when (OfflinePunchQueue.IsSharingViolation(e) && Environment.TickCount64 - started < 2000) { Thread.Sleep(20); }
            }
            using (handle) return action();
        }
    }

    private void Persist(List<PunchReviewReceipt> entries)
    {
        var temporary = _path + $".{Guid.NewGuid():N}.tmp";
        try
        {
            using (var file = new FileStream(temporary, FileMode.CreateNew, FileAccess.Write, FileShare.None, 4096, FileOptions.WriteThrough))
            {
                JsonSerializer.Serialize(file, entries, Options);
                file.Flush(true);
            }
            File.Move(temporary, _path, overwrite: true);
            _hasSeenFile = true;
        }
        finally
        {
            try { File.Delete(temporary); } catch (IOException) { } catch (UnauthorizedAccessException) { }
        }
    }
}
