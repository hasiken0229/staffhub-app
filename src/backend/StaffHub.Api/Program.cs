using StaffHub.Api.Models;
using StaffHub.Api.Services;

var builder = WebApplication.CreateBuilder(args);

builder.Services.AddSingleton<InMemoryAppStore>();
builder.Services.AddSingleton<PostgresCoreStore>();
builder.Services.AddCors(options =>
{
    options.AddDefaultPolicy(policy =>
        policy.AllowAnyOrigin().AllowAnyHeader().AllowAnyMethod());
});

var app = builder.Build();
app.UseCors();

var postgresCore = app.Services.GetRequiredService<PostgresCoreStore>();
var seedStore = app.Services.GetRequiredService<InMemoryAppStore>();
await postgresCore.InitializeAsync(seedStore);

app.MapGet("/", () => ApiOk(new
{
    name = "勤怠管理 API",
    version = "0.1.0",
    message = "職員打刻・休暇申請・給与明細配信アプリのバックエンド雛形です。"
}));

app.MapGet("/health", (InMemoryAppStore store, PostgresCoreStore postgres) => ApiOk(new
{
    status = "ok",
    serverTime = DateTimeOffset.Now,
    persistence = new
    {
        mode = postgres.PersistenceMode,
        snapshotPath = store.SnapshotPath
    }
}));

var api = app.MapGroup("/api");

api.MapPost("/auth/login", (InMemoryAppStore store, LoginRequest request) =>
{
    var result = store.Login(request.LoginId, request.Password);
    return result is null
        ? ApiError(StatusCodes.Status401Unauthorized, "UNAUTHORIZED", "ログインIDまたはパスワードが正しくありません。")
        : ApiOk(result);
});

api.MapPost("/auth/refresh", (InMemoryAppStore store, RefreshTokenRequest request) => ApiOk(store.RefreshToken(request.RefreshToken)));
api.MapPost("/auth/logout", () => ApiOk(new { success = true }));

api.MapPost("/attendance/punch", async (InMemoryAppStore store, PostgresCoreStore postgres, PunchRequest request) =>
{
    try
    {
        var result = postgres.IsEnabled
            ? await postgres.PunchAsync(request)
            : store.Punch(request);
        return ApiOk(result);
    }
    catch (Exception ex)
    {
        return FromDomainException(ex);
    }
});

api.MapPost("/attendance/devices/heartbeat", (InMemoryAppStore store, HeartbeatRequest request) =>
{
    try
    {
        return ApiOk(store.Heartbeat(request));
    }
    catch (Exception ex)
    {
        return FromDomainException(ex);
    }
});

api.MapGet("/mobile/home", (InMemoryAppStore store) => ApiOk(store.GetHomeSummary(store.DefaultEmployeeId)));
api.MapGet("/leave/balance", (InMemoryAppStore store) => ApiOk(store.GetLeaveBalance(store.DefaultEmployeeId)));

api.MapGet("/leave/requests", (InMemoryAppStore store, string? status, DateOnly? from, DateOnly? to, int page = 1, int perPage = 20) =>
{
    var result = store.GetLeaveRequests(store.DefaultEmployeeId, status, from, to, page, perPage);
    return ApiOk(result.Items, new { result.Page, result.PerPage, result.Total });
});

api.MapPost("/leave/requests", (InMemoryAppStore store, CreateLeaveRequest request) =>
{
    try
    {
        return ApiOk(store.CreateLeaveRequest(store.DefaultEmployeeId, request));
    }
    catch (Exception ex)
    {
        return FromDomainException(ex);
    }
});

api.MapGet("/leave/requests/{id:long}", (InMemoryAppStore store, long id) =>
{
    var result = store.GetLeaveRequest(store.DefaultEmployeeId, id);
    return result is null
        ? ApiError(StatusCodes.Status404NotFound, "NOT_FOUND", "申請が見つかりません。")
        : ApiOk(result);
});

api.MapGet("/payroll/statements", (InMemoryAppStore store, string? yearMonth, int page = 1, int perPage = 20) =>
{
    var result = store.GetPayrollStatements(store.DefaultEmployeeId, yearMonth, page, perPage);
    return ApiOk(result.Items, new { result.Page, result.PerPage, result.Total });
});

api.MapGet("/payroll/statements/{id:long}", (InMemoryAppStore store, long id) =>
{
    var result = store.GetPayrollStatement(store.DefaultEmployeeId, id);
    if (result is null)
    {
        return ApiError(StatusCodes.Status404NotFound, "NOT_FOUND", "給与明細が見つかりません。");
    }

    return ApiOk(new
    {
        result.Id,
        result.TargetYearMonth,
        downloadUrl = result.DownloadUrl,
        expiresAt = DateTimeOffset.Now.AddMinutes(10)
    });
});

api.MapPost("/payroll/statements/{id:long}/viewed", (InMemoryAppStore store, long id, ViewedRequest request) =>
{
    store.MarkPayrollViewed(store.DefaultEmployeeId, id, request.ViewedAt);
    return ApiOk(new { success = true });
});

api.MapGet("/notifications", (InMemoryAppStore store, bool? isRead, int page = 1, int perPage = 20) =>
{
    var result = store.GetNotifications(store.DefaultEmployeeId, isRead, page, perPage);
    return ApiOk(result.Items, new { result.Page, result.PerPage, result.Total });
});

api.MapPost("/notifications/{id:long}/read", (InMemoryAppStore store, long id) =>
{
    var notification = store.MarkNotificationRead(store.DefaultEmployeeId, id);
    return notification is null
        ? ApiError(StatusCodes.Status404NotFound, "NOT_FOUND", "通知が見つかりません。")
        : ApiOk(new { success = true, notification.ReadAt });
});

var admin = api.MapGroup("/admin");

admin.MapGet("/employees", (InMemoryAppStore store, string? employeeCode, string? name, string? departmentName, string? status, int page = 1, int perPage = 20) =>
{
    var result = store.GetEmployees(employeeCode, name, departmentName, status, page, perPage);
    return ApiOk(result.Items, new { result.Page, result.PerPage, result.Total });
});

admin.MapPost("/employees", (InMemoryAppStore store, CreateEmployeeRequest request) =>
{
    try
    {
        return ApiOk(store.CreateEmployee(request));
    }
    catch (Exception ex)
    {
        return FromDomainException(ex);
    }
});

admin.MapPut("/employees/{id:long}", (InMemoryAppStore store, long id, UpdateEmployeeRequest request) =>
{
    var result = store.UpdateEmployee(id, request);
    return result is null
        ? ApiError(StatusCodes.Status404NotFound, "NOT_FOUND", "職員が見つかりません。")
        : ApiOk(result);
});

admin.MapGet("/cards", async (InMemoryAppStore store, PostgresCoreStore postgres, string? cardUid, string? employeeCode, bool? isActive, int page = 1, int perPage = 20) =>
{
    var result = postgres.IsEnabled
        ? await postgres.GetCardsAsync(cardUid, employeeCode, isActive, page, perPage)
        : store.GetCards(cardUid, employeeCode, isActive, page, perPage);
    return ApiOk(result.Items, new { result.Page, result.PerPage, result.Total });
});

admin.MapPost("/cards/assign", async (InMemoryAppStore store, PostgresCoreStore postgres, AssignCardRequest request) =>
{
    try
    {
        var result = postgres.IsEnabled
            ? await postgres.AssignCardAsync(request)
            : store.AssignCard(request);
        return ApiOk(result);
    }
    catch (Exception ex)
    {
        return FromDomainException(ex);
    }
});

admin.MapPost("/cards/revoke", async (InMemoryAppStore store, PostgresCoreStore postgres, RevokeCardRequest request) =>
{
    var success = postgres.IsEnabled
        ? await postgres.RevokeCardAsync(request.CardId)
        : store.RevokeCard(request.CardId);
    return success
        ? ApiOk(new { success = true })
        : ApiError(StatusCodes.Status404NotFound, "NOT_FOUND", "カードが見つかりません。");
});

admin.MapGet("/attendance/events", async (InMemoryAppStore store, PostgresCoreStore postgres, DateOnly? from, DateOnly? to, string? employeeCode, string? receiveStatus, string? deviceCode, int page = 1, int perPage = 20) =>
{
    var result = postgres.IsEnabled
        ? await postgres.GetAttendanceEventsAsync(from, to, employeeCode, receiveStatus, deviceCode, page, perPage)
        : store.GetAttendanceEvents(from, to, employeeCode, receiveStatus, deviceCode, page, perPage);
    return ApiOk(result.Items, new { result.Page, result.PerPage, result.Total });
});

admin.MapGet("/attendance/daily", async (InMemoryAppStore store, PostgresCoreStore postgres, string? targetMonth, string? employeeCode, string? departmentName, int page = 1, int perPage = 20) =>
{
    var result = postgres.IsEnabled
        ? await postgres.GetAttendanceDailyAsync(targetMonth, employeeCode, departmentName, page, perPage)
        : store.GetAttendanceDaily(targetMonth, employeeCode, departmentName, page, perPage);
    return ApiOk(result.Items, new { result.Page, result.PerPage, result.Total });
});

admin.MapGet("/leave/requests", (InMemoryAppStore store, string? status, string? leaveTypeCode, DateOnly? from, DateOnly? to, string? employeeName, int page = 1, int perPage = 20) =>
{
    var result = store.GetAdminLeaveRequests(status, leaveTypeCode, from, to, employeeName, page, perPage);
    return ApiOk(result.Items, new { result.Page, result.PerPage, result.Total });
});

admin.MapPost("/leave/requests/{id:long}/approve", (InMemoryAppStore store, long id, DecisionRequest request) =>
{
    var result = store.DecideLeave(id, "APPROVED", request.Comment);
    return result is null ? ApiError(StatusCodes.Status404NotFound, "NOT_FOUND", "申請が見つかりません。") : ApiOk(result);
});

admin.MapPost("/leave/requests/{id:long}/reject", (InMemoryAppStore store, long id, DecisionRequest request) =>
{
    var result = store.DecideLeave(id, "REJECTED", request.Comment);
    return result is null ? ApiError(StatusCodes.Status404NotFound, "NOT_FOUND", "申請が見つかりません。") : ApiOk(result);
});

admin.MapPost("/leave/requests/{id:long}/return", (InMemoryAppStore store, long id, DecisionRequest request) =>
{
    var result = store.DecideLeave(id, "RETURNED", request.Comment);
    return result is null ? ApiError(StatusCodes.Status404NotFound, "NOT_FOUND", "申請が見つかりません。") : ApiOk(result);
});

admin.MapGet("/payroll/statements", (InMemoryAppStore store, string? targetYearMonth, string? employeeCode, string? publishedStatus, int page = 1, int perPage = 20) =>
{
    var result = store.GetAdminPayrollStatements(targetYearMonth, employeeCode, publishedStatus, page, perPage);
    return ApiOk(result.Items, new { result.Page, result.PerPage, result.Total });
});

admin.MapPost("/payroll/statements", async (HttpRequest request, InMemoryAppStore store) =>
{
    if (!request.HasFormContentType)
    {
        return ApiError(StatusCodes.Status400BadRequest, "VALIDATION_ERROR", "multipart/form-data で送信してください。");
    }

    var form = await request.ReadFormAsync();
    if (!long.TryParse(form["employeeId"], out var employeeId))
    {
        return ApiError(StatusCodes.Status400BadRequest, "VALIDATION_ERROR", "employeeId を指定してください。");
    }

    var targetYearMonth = form["targetYearMonth"].ToString();
    if (string.IsNullOrWhiteSpace(targetYearMonth))
    {
        return ApiError(StatusCodes.Status400BadRequest, "VALIDATION_ERROR", "targetYearMonth を指定してください。");
    }

    var file = form.Files["file"];
    if (file is null)
    {
        return ApiError(StatusCodes.Status400BadRequest, "VALIDATION_ERROR", "PDFファイルを指定してください。");
    }

    DateTimeOffset? publishedAt = null;
    if (DateTimeOffset.TryParse(form["publishedAt"], out var parsedPublishedAt))
    {
        publishedAt = parsedPublishedAt;
    }

    var result = store.UploadPayrollStatement(employeeId, targetYearMonth, file.FileName, publishedAt);
    return ApiOk(result);
});

admin.MapGet("/audit-logs", (InMemoryAppStore store, DateOnly? from, DateOnly? to, string? action, string? actorKeyword, string? targetType, int page = 1, int perPage = 20) =>
{
    var result = store.GetAuditLogs(from, to, action, actorKeyword, targetType, page, perPage);
    return ApiOk(result.Items, new { result.Page, result.PerPage, result.Total });
});

app.Run();

static IResult ApiOk(object data, object? meta = null)
    => Results.Json(new { data, meta = meta ?? new { } });

static IResult ApiError(int statusCode, string code, string message, object? details = null)
    => Results.Json(new { error = new { code, message, details = details ?? Array.Empty<object>() } }, statusCode: statusCode);

static IResult FromDomainException(Exception ex) => ex switch
{
    InvalidOperationException { Message: "CARD_NOT_REGISTERED" } => ApiError(StatusCodes.Status400BadRequest, "CARD_NOT_REGISTERED", "このカードは登録されていません。"),
    InvalidOperationException { Message: "DEVICE_DISABLED" } => ApiError(StatusCodes.Status403Forbidden, "DEVICE_DISABLED", "端末が無効化されています。"),
    InvalidOperationException { Message: "EMPLOYEE_NOT_FOUND" } => ApiError(StatusCodes.Status404NotFound, "EMPLOYEE_NOT_FOUND", "職員が見つかりません。"),
    InvalidOperationException { Message: "INSUFFICIENT_PAID_LEAVE" } => ApiError(StatusCodes.Status400BadRequest, "INSUFFICIENT_PAID_LEAVE", "有給残数が不足しています。"),
    InvalidOperationException { Message: "DUPLICATE_EMPLOYEE_CODE" } => ApiError(StatusCodes.Status409Conflict, "DUPLICATE_REQUEST", "同じ職員番号が既に登録されています。"),
    ArgumentException { Message: "VALIDATION_CARD_UID" } => ApiError(StatusCodes.Status400BadRequest, "VALIDATION_ERROR", "カードUIDを入力してください。"),
    ArgumentException { Message: "VALIDATION_DATE_RANGE" } => ApiError(StatusCodes.Status400BadRequest, "VALIDATION_ERROR", "開始日は終了日以前である必要があります。"),
    ArgumentException { Message: "VALIDATION_HALF_DAY_RANGE" } => ApiError(StatusCodes.Status400BadRequest, "VALIDATION_ERROR", "半日申請は同日指定のみ可能です。"),
    ArgumentException { Message: "VALIDATION_ABSENCE_HALF" } => ApiError(StatusCodes.Status400BadRequest, "VALIDATION_ERROR", "欠勤はMVPでは半日申請に対応していません。"),
    _ => ApiError(StatusCodes.Status500InternalServerError, "INTERNAL_ERROR", "予期しないエラーが発生しました。")
};
