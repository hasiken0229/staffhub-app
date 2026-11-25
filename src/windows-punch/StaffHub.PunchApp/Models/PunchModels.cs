namespace StaffHub.PunchApp.Models;

public sealed record AppSettings(
    string ApiBaseUrl,
    string DeviceCode,
    string DeviceSecret,
    string DeviceName,
    bool AutoStartEnabled,
    bool StartMinimized,
    string ReaderMode,
    string? PreferredReaderName,
    int PollIntervalMilliseconds);

public sealed record PunchRequest(
    string DeviceCode,
    string DeviceSecret,
    string CardUid,
    DateTimeOffset OccurredAt,
    string DedupeKey,
    string? AppVersion,
    DateTimeOffset? ClockReferenceAt = null,
    DateTimeOffset? ClientSentAt = null)
{
    public long? CaptureAgeSeconds { get; init; }
    public bool CaptureClockUnknown { get; init; }
    public bool ConfirmShortShift { get; init; }
}

public sealed record EmployeeBrief(
    long Id,
    string EmployeeCode,
    string Name);

public sealed record RegistrationEmployee(
    long Id,
    string EmployeeCode,
    string Name,
    string? DepartmentName,
    string Status)
{
    public string DisplayName => $"{EmployeeCode} / {Name}";
}

public sealed record CardAssignmentResult(
    long Id,
    long EmployeeId,
    string EmployeeCode,
    string EmployeeName,
    string CardUid,
    bool IsActive,
    DateTimeOffset AssignedAt,
    DateTimeOffset? RevokedAt);

public sealed record PunchResult(
    long AttendanceEventId,
    EmployeeBrief? Employee,
    string? EventType,
    string ResultType,
    string ResultMessage,
    DateTimeOffset OccurredAt,
    bool OfflineAccepted)
{
    public string? ReasonCode { get; init; }
    internal bool IsShortShiftTap => ResultType == "WARNING" && ReasonCode == "SHORT_SHIFT_CONFIRMATION_REQUIRED";
}

public sealed record DevicePunchReceipt(string EmployeeName, string EventType, DateTimeOffset OccurredAt);
public sealed record TodayDeviceReceipts(DateTimeOffset CheckedAt, IReadOnlyList<DevicePunchReceipt> Receipts);

public sealed record HeartbeatResult(
    bool Success,
    DateTimeOffset ServerTime,
    bool DeviceActive,
    int ClockDriftSeconds);

public sealed record PendingPunch(
    string CardUid,
    DateTimeOffset OccurredAt,
    string DedupeKey,
    DateTimeOffset? ClockReferenceAt = null)
{
    public bool AwaitingClockReference { get; init; }
    public bool ConfirmShortShift { get; init; }
}
