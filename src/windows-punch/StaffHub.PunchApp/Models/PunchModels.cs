namespace StaffHub.PunchApp.Models;

public sealed record AppSettings(
    string ApiBaseUrl,
    string DeviceCode,
    string DeviceSecret,
    string DeviceName,
    bool AutoStartEnabled,
    string ReaderMode,
    string? PreferredReaderName,
    int PollIntervalMilliseconds);

public sealed record PunchRequest(
    string DeviceCode,
    string DeviceSecret,
    string CardUid,
    DateTimeOffset OccurredAt,
    string DedupeKey,
    string? AppVersion);

public sealed record EmployeeBrief(
    long Id,
    string EmployeeCode,
    string Name);

public sealed record PunchResult(
    long AttendanceEventId,
    EmployeeBrief? Employee,
    string? EventType,
    string ResultType,
    string ResultMessage,
    DateTimeOffset OccurredAt,
    bool OfflineAccepted);

public sealed record PendingPunch(
    string CardUid,
    DateTimeOffset OccurredAt,
    string DedupeKey);
