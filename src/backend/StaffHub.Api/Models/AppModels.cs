namespace StaffHub.Api.Models;

public sealed record EmployeeModel(
    long Id,
    string EmployeeCode,
    string Name,
    string? Kana,
    string? DepartmentName,
    string EmploymentType,
    string Status,
    DateOnly JoinedOn,
    DateOnly? RetiredOn);

public sealed record EmployeeBrief(
    long Id,
    string EmployeeCode,
    string Name);

public sealed record AccountCredential(
    long EmployeeId,
    string LoginId,
    string Password,
    string Role);

public sealed record LoggedInUser(
    long Id,
    string Role,
    string EmployeeCode,
    string Name);

public sealed record LoginResponse(
    string AccessToken,
    string RefreshToken,
    LoggedInUser User);

public sealed record AttendanceDeviceModel(
    long Id,
    string DeviceCode,
    string Name,
    string? LocationName,
    string? AppVersion,
    bool IsActive,
    DateTimeOffset? LastSeenAt);

public sealed record AttendanceEventModel(
    long Id,
    long? EmployeeId,
    string? EmployeeCode,
    string? EmployeeName,
    long DeviceId,
    string DeviceCode,
    string DeviceName,
    string CardUid,
    DateTimeOffset OccurredAt,
    string? EventType,
    string ReceiveStatus,
    string? RejectionReason,
    bool OfflineSaved);

public sealed record AttendanceDailyModel(
    long EmployeeId,
    string EmployeeCode,
    string EmployeeName,
    DateOnly TargetDate,
    DateTimeOffset? ClockInAt,
    DateTimeOffset? ClockOutAt,
    int? WorkMinutes,
    bool AbsenceFlag,
    bool SpecialLeaveFlag,
    decimal? PaidLeaveUnit);

public sealed record PunchResult(
    long AttendanceEventId,
    EmployeeBrief? Employee,
    string? EventType,
    string ResultType,
    string ResultMessage,
    DateTimeOffset OccurredAt,
    bool OfflineAccepted);

public sealed record HeartbeatResult(
    bool Success,
    DateTimeOffset ServerTime,
    bool DeviceActive);

public sealed record PayrollStatementModel(
    long Id,
    long EmployeeId,
    string TargetYearMonth,
    string OriginalFileName,
    DateTimeOffset? PublishedAt,
    bool Viewed,
    string DownloadUrl);

public sealed record PayrollViewModel(
    long Id,
    long PayrollStatementId,
    long EmployeeId,
    DateTimeOffset ViewedAt);

public sealed record HomeSummary(
    EmployeeBrief Employee,
    int PendingLeaveCount,
    decimal PaidLeaveBalance,
    int UnreadNotificationCount,
    PayrollStatementModel? LatestPayroll);

public sealed record PaidLeaveGrantModel(
    long Id,
    long EmployeeId,
    DateOnly GrantedOn,
    decimal GrantedDays,
    decimal UsedDays,
    DateOnly? ExpiresOn,
    string? Note);

public sealed record LeaveActionModel(
    long Id,
    long LeaveRequestId,
    string ActionType,
    long ActionBy,
    string ActionByName,
    string? Comment,
    DateTimeOffset ActedAt);

public sealed record LeaveRequestModel(
    long Id,
    long EmployeeId,
    string EmployeeName,
    string LeaveTypeCode,
    string LeaveTypeName,
    DateOnly StartDate,
    DateOnly EndDate,
    string DayUnit,
    string? HalfDayType,
    decimal QuantityDays,
    string? Reason,
    string Status,
    long? ApprovedBy,
    DateTimeOffset? ApprovedAt,
    string? DecisionComment,
    DateTimeOffset CreatedAt,
    IReadOnlyList<LeaveActionModel> Actions);

public sealed record NotificationModel(
    long Id,
    long EmployeeId,
    string NotificationType,
    string Title,
    string Body,
    bool IsRead,
    DateTimeOffset SentAt,
    DateTimeOffset? ReadAt);

public sealed record CardAssignmentModel(
    long Id,
    long EmployeeId,
    string EmployeeCode,
    string EmployeeName,
    string CardUid,
    bool IsActive,
    DateTimeOffset AssignedAt,
    DateTimeOffset? RevokedAt);

public sealed record AuditLogModel(
    long Id,
    string ActorType,
    long? ActorId,
    string Action,
    string TargetType,
    string? TargetId,
    string Detail,
    DateTimeOffset OccurredAt);

public sealed record PagedResult<T>(
    IReadOnlyList<T> Items,
    int Page,
    int PerPage,
    int Total);

public sealed record LoginRequest(string LoginId, string Password);
public sealed record RefreshTokenRequest(string RefreshToken);
public sealed record LogoutRequest(string RefreshToken);
public sealed record PunchRequest(string DeviceCode, string DeviceSecret, string CardUid, DateTimeOffset OccurredAt, string DedupeKey, string? AppVersion);
public sealed record HeartbeatRequest(string DeviceCode, string DeviceSecret, string? AppVersion, DateTimeOffset LastSeenAt, int PendingOfflineCount);
public sealed record CreateLeaveRequest(string LeaveTypeCode, DateOnly StartDate, DateOnly EndDate, string DayUnit, string? HalfDayType, string? Reason);
public sealed record ViewedRequest(DateTimeOffset ViewedAt);
public sealed record CreateEmployeeRequest(string EmployeeCode, string Name, string? Kana, string? DepartmentName, string EmploymentType, string Status, DateOnly JoinedOn, string LoginId, string InitialPassword);
public sealed record UpdateEmployeeRequest(string Name, string? DepartmentName, string EmploymentType, string Status, DateOnly? RetiredOn);
public sealed record AssignCardRequest(long EmployeeId, string CardUid);
public sealed record RevokeCardRequest(long CardId);
public sealed record DecisionRequest(string? Comment);

