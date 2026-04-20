using System.Text.Json;
using Microsoft.Extensions.Logging;
using StaffHub.Api.Models;

namespace StaffHub.Api.Services;

public sealed class InMemoryAppStore
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web)
    {
        WriteIndented = true
    };

    private readonly object _sync = new();
    private readonly ILogger<InMemoryAppStore> _logger;
    private readonly string _snapshotPath;
    private readonly List<EmployeeModel> _employees = [];
    private readonly List<AccountCredential> _accounts = [];
    private readonly List<AttendanceDeviceModel> _devices = [];
    private readonly List<CardAssignmentModel> _cards = [];
    private readonly List<AttendanceEventModel> _attendanceEvents = [];
    private readonly List<AttendanceDailyModel> _attendanceDaily = [];
    private readonly List<PaidLeaveGrantModel> _paidLeaveGrants = [];
    private readonly List<LeaveRequestModel> _leaveRequests = [];
    private readonly List<PayrollStatementModel> _payrollStatements = [];
    private readonly List<PayrollViewModel> _payrollViews = [];
    private readonly List<NotificationModel> _notifications = [];
    private readonly List<AuditLogModel> _auditLogs = [];

    private long _employeeSequence = 100;
    private long _cardSequence = 10;
    private long _attendanceSequence = 10000;
    private long _leaveSequence = 50;
    private long _leaveActionSequence = 200;
    private long _payrollSequence = 20;
    private long _payrollViewSequence = 500;
    private long _notificationSequence = 3000;
    private long _auditSequence = 90000;

    public InMemoryAppStore(ILogger<InMemoryAppStore> logger)
    {
        _logger = logger;
        _snapshotPath = Path.Combine(GetProjectRootPath(), "App_Data", "staffhub-state.json");
        LoadOrSeed();
    }

    public long DefaultEmployeeId => 1;
    public long DefaultAdminId => 100;

    public LoginResponse? Login(string loginId, string password)
    {
        var account = _accounts.FirstOrDefault(x =>
            x.LoginId.Equals(loginId, StringComparison.OrdinalIgnoreCase) &&
            x.Password == password);

        if (account is null)
        {
            return null;
        }

        var employee = _employees.First(x => x.Id == account.EmployeeId);
        return new LoginResponse(
            $"demo-access-{employee.Id}",
            $"demo-refresh-{employee.Id}",
            new LoggedInUser(employee.Id, account.Role, employee.EmployeeCode, employee.Name));
    }

    public object RefreshToken(string refreshToken) => new
    {
        accessToken = $"{refreshToken}-access",
        refreshToken = $"{refreshToken}-refresh"
    };

    public string SnapshotPath => _snapshotPath;

    public IReadOnlyList<EmployeeModel> GetBootstrapEmployees()
    {
        lock (_sync)
        {
            return _employees.ToList();
        }
    }

    public IReadOnlyList<AttendanceDeviceModel> GetBootstrapDevices()
    {
        lock (_sync)
        {
            return _devices.ToList();
        }
    }

    public PunchResult Punch(PunchRequest request)
    {
        lock (_sync)
        {
            var device = _devices.FirstOrDefault(x => x.DeviceCode == request.DeviceCode && x.IsActive);
            if (device is null)
            {
                throw new InvalidOperationException("DEVICE_DISABLED");
            }

            var card = _cards.FirstOrDefault(x => x.CardUid == request.CardUid && x.IsActive);
            if (card is null)
            {
                var rejectedId = ++_attendanceSequence;
                _attendanceEvents.Add(new AttendanceEventModel(
                    rejectedId, null, null, null, device.Id, device.DeviceCode, device.Name, request.CardUid,
                    request.OccurredAt, null, "REJECTED", "CARD_NOT_REGISTERED", false));
                AddAudit("DEVICE", device.Id, "ATTENDANCE_REJECTED", "CARD", request.CardUid, "未登録カード");
                SaveSnapshotUnsafe();
                throw new InvalidOperationException("CARD_NOT_REGISTERED");
            }

            var employee = _employees.First(x => x.Id == card.EmployeeId);
            var targetDate = DateOnly.FromDateTime(request.OccurredAt.LocalDateTime);
            var sameDayAccepted = _attendanceEvents
                .Where(x => x.EmployeeId == employee.Id && x.ReceiveStatus == "ACCEPTED" && DateOnly.FromDateTime(x.OccurredAt.LocalDateTime) == targetDate)
                .OrderBy(x => x.OccurredAt)
                .ToList();

            var eventType = sameDayAccepted.Count == 0 || sameDayAccepted.Last().EventType == "CLOCK_OUT"
                ? "CLOCK_IN"
                : "CLOCK_OUT";

            var resultType = "SUCCESS";
            var resultMessage = eventType == "CLOCK_IN" ? "出勤を記録しました。" : "退勤を記録しました。";
            if (sameDayAccepted.LastOrDefault() is { } lastEvent &&
                (request.OccurredAt - lastEvent.OccurredAt).TotalMinutes <= 2)
            {
                resultType = "WARNING";
                resultMessage = "短時間の連続打刻です。内容を確認してください。";
            }

            var id = ++_attendanceSequence;
            _attendanceEvents.Add(new AttendanceEventModel(
                id, employee.Id, employee.EmployeeCode, employee.Name, device.Id, device.DeviceCode, device.Name, request.CardUid,
                request.OccurredAt, eventType, "ACCEPTED", null, false));

            RebuildAttendanceDaily(employee.Id, targetDate);
            AddAudit("DEVICE", device.Id, "ATTENDANCE_ACCEPTED", "ATTENDANCE_EVENT", id.ToString(), $"{employee.Name}:{eventType}");
            SaveSnapshotUnsafe();

            return new PunchResult(id, ToBrief(employee.Id), eventType, resultType, resultMessage, request.OccurredAt, false);
        }
    }

    public HeartbeatResult Heartbeat(HeartbeatRequest request)
    {
        lock (_sync)
        {
            var index = _devices.FindIndex(x => x.DeviceCode == request.DeviceCode);
            if (index < 0)
            {
                throw new InvalidOperationException("DEVICE_DISABLED");
            }

            var current = _devices[index];
            _devices[index] = current with { LastSeenAt = request.LastSeenAt, AppVersion = request.AppVersion };
            SaveSnapshotUnsafe();
            return new HeartbeatResult(true, DateTimeOffset.Now, current.IsActive);
        }
    }

    public HomeSummary GetHomeSummary(long employeeId)
    {
        var viewedIds = _payrollViews.Where(x => x.EmployeeId == employeeId).Select(x => x.PayrollStatementId).ToHashSet();
        var latest = _payrollStatements
            .Where(x => x.EmployeeId == employeeId && x.PublishedAt is not null)
            .OrderByDescending(x => x.TargetYearMonth)
            .Select(x => x with { Viewed = viewedIds.Contains(x.Id) })
            .FirstOrDefault();

        return new HomeSummary(
            ToBrief(employeeId),
            _leaveRequests.Count(x => x.EmployeeId == employeeId && x.Status == "PENDING"),
            GetPaidLeaveBalance(employeeId),
            _notifications.Count(x => x.EmployeeId == employeeId && !x.IsRead),
            latest);
    }

    public object GetLeaveBalance(long employeeId) => new
    {
        employeeId,
        currentBalance = GetPaidLeaveBalance(employeeId),
        grants = _paidLeaveGrants.Where(x => x.EmployeeId == employeeId).OrderBy(x => x.GrantedOn).ToList()
    };

    public PagedResult<LeaveRequestModel> GetLeaveRequests(long employeeId, string? status, DateOnly? from, DateOnly? to, int page, int perPage)
    {
        var query = _leaveRequests.Where(x => x.EmployeeId == employeeId);
        if (!string.IsNullOrWhiteSpace(status))
        {
            query = query.Where(x => x.Status.Equals(status, StringComparison.OrdinalIgnoreCase));
        }
        if (from.HasValue)
        {
            query = query.Where(x => x.StartDate >= from.Value);
        }
        if (to.HasValue)
        {
            query = query.Where(x => x.EndDate <= to.Value);
        }

        return Page(query.OrderByDescending(x => x.CreatedAt).ToList(), page, perPage);
    }

    public LeaveRequestModel CreateLeaveRequest(long employeeId, CreateLeaveRequest request)
    {
        lock (_sync)
        {
            if (request.StartDate > request.EndDate)
            {
                throw new ArgumentException("VALIDATION_DATE_RANGE");
            }
            if (request.DayUnit == "HALF" && request.StartDate != request.EndDate)
            {
                throw new ArgumentException("VALIDATION_HALF_DAY_RANGE");
            }
            if (request.LeaveTypeCode == "ABSENCE" && request.DayUnit == "HALF")
            {
                throw new ArgumentException("VALIDATION_ABSENCE_HALF");
            }

            var quantityDays = request.DayUnit == "HALF" ? 0.5m : request.EndDate.DayNumber - request.StartDate.DayNumber + 1;
            if (request.LeaveTypeCode == "PAID" && GetPaidLeaveBalance(employeeId) < quantityDays)
            {
                throw new InvalidOperationException("INSUFFICIENT_PAID_LEAVE");
            }

            var employee = _employees.First(x => x.Id == employeeId);
            var leaveId = ++_leaveSequence;
            var action = new LeaveActionModel(++_leaveActionSequence, leaveId, "APPLIED", employeeId, employee.Name, null, DateTimeOffset.Now);
            var leave = new LeaveRequestModel(
                leaveId, employeeId, employee.Name, request.LeaveTypeCode, ToLeaveTypeName(request.LeaveTypeCode),
                request.StartDate, request.EndDate, request.DayUnit, request.HalfDayType, quantityDays, request.Reason,
                "PENDING", null, null, null, DateTimeOffset.Now, [action]);

            _leaveRequests.Add(leave);
            AddNotification(DefaultAdminId, "LEAVE_REQUESTED", "休暇申請が登録されました", $"{employee.Name} さんの申請を確認してください。");
            AddAudit("EMPLOYEE", employeeId, "LEAVE_REQUEST_CREATED", "LEAVE_REQUEST", leave.Id.ToString(), leave.LeaveTypeCode);
            SaveSnapshotUnsafe();
            return leave;
        }
    }

    public LeaveRequestModel? GetLeaveRequest(long employeeId, long id) => _leaveRequests.FirstOrDefault(x => x.EmployeeId == employeeId && x.Id == id);

    public PagedResult<PayrollStatementModel> GetPayrollStatements(long employeeId, string? yearMonth, int page, int perPage)
    {
        var viewedIds = _payrollViews.Where(x => x.EmployeeId == employeeId).Select(x => x.PayrollStatementId).ToHashSet();
        var query = _payrollStatements.Where(x => x.EmployeeId == employeeId && x.PublishedAt is not null);
        if (!string.IsNullOrWhiteSpace(yearMonth))
        {
            query = query.Where(x => x.TargetYearMonth == yearMonth);
        }

        var items = query.OrderByDescending(x => x.TargetYearMonth)
            .Select(x => x with { Viewed = viewedIds.Contains(x.Id) })
            .ToList();

        return Page(items, page, perPage);
    }

    public PayrollStatementModel? GetPayrollStatement(long employeeId, long id)
        => _payrollStatements.FirstOrDefault(x => x.EmployeeId == employeeId && x.Id == id && x.PublishedAt is not null);

    public void MarkPayrollViewed(long employeeId, long id, DateTimeOffset viewedAt)
    {
        lock (_sync)
        {
            if (_payrollViews.Any(x => x.EmployeeId == employeeId && x.PayrollStatementId == id))
            {
                return;
            }

            _payrollViews.Add(new PayrollViewModel(++_payrollViewSequence, id, employeeId, viewedAt));
            AddAudit("EMPLOYEE", employeeId, "PAYROLL_VIEWED", "PAYROLL_STATEMENT", id.ToString(), viewedAt.ToString("O"));
            SaveSnapshotUnsafe();
        }
    }

    public PagedResult<NotificationModel> GetNotifications(long employeeId, bool? isRead, int page, int perPage)
    {
        var query = _notifications.Where(x => x.EmployeeId == employeeId);
        if (isRead.HasValue)
        {
            query = query.Where(x => x.IsRead == isRead.Value);
        }

        return Page(query.OrderByDescending(x => x.SentAt).ToList(), page, perPage);
    }

    public NotificationModel? MarkNotificationRead(long employeeId, long id)
    {
        lock (_sync)
        {
            var index = _notifications.FindIndex(x => x.EmployeeId == employeeId && x.Id == id);
            if (index < 0)
            {
                return null;
            }

            var updated = _notifications[index] with { IsRead = true, ReadAt = DateTimeOffset.Now };
            _notifications[index] = updated;
            SaveSnapshotUnsafe();
            return updated;
        }
    }

    public PagedResult<EmployeeModel> GetEmployees(string? employeeCode, string? name, string? departmentName, string? status, int page, int perPage)
    {
        var query = _employees.AsEnumerable();
        if (!string.IsNullOrWhiteSpace(employeeCode))
        {
            query = query.Where(x => x.EmployeeCode.Contains(employeeCode, StringComparison.OrdinalIgnoreCase));
        }
        if (!string.IsNullOrWhiteSpace(name))
        {
            query = query.Where(x => x.Name.Contains(name, StringComparison.OrdinalIgnoreCase));
        }
        if (!string.IsNullOrWhiteSpace(departmentName))
        {
            query = query.Where(x => (x.DepartmentName ?? string.Empty).Contains(departmentName, StringComparison.OrdinalIgnoreCase));
        }
        if (!string.IsNullOrWhiteSpace(status))
        {
            query = query.Where(x => x.Status.Equals(status, StringComparison.OrdinalIgnoreCase));
        }

        return Page(query.OrderBy(x => x.EmployeeCode).ToList(), page, perPage);
    }

    public EmployeeModel CreateEmployee(CreateEmployeeRequest request)
    {
        lock (_sync)
        {
            if (_employees.Any(x => x.EmployeeCode == request.EmployeeCode))
            {
                throw new InvalidOperationException("DUPLICATE_EMPLOYEE_CODE");
            }

            var employee = new EmployeeModel(++_employeeSequence, request.EmployeeCode, request.Name, request.Kana, request.DepartmentName, request.EmploymentType, request.Status, request.JoinedOn, null);
            _employees.Add(employee);
            _accounts.Add(new AccountCredential(employee.Id, request.LoginId, request.InitialPassword, "EMPLOYEE"));
            AddAudit("ADMIN", DefaultAdminId, "EMPLOYEE_CREATED", "EMPLOYEE", employee.Id.ToString(), employee.Name);
            SaveSnapshotUnsafe();
            return employee;
        }
    }

    public EmployeeModel? UpdateEmployee(long id, UpdateEmployeeRequest request)
    {
        lock (_sync)
        {
            var index = _employees.FindIndex(x => x.Id == id);
            if (index < 0)
            {
                return null;
            }

            var updated = _employees[index] with
            {
                Name = request.Name,
                DepartmentName = request.DepartmentName,
                EmploymentType = request.EmploymentType,
                Status = request.Status,
                RetiredOn = request.RetiredOn
            };

            _employees[index] = updated;
            AddAudit("ADMIN", DefaultAdminId, "EMPLOYEE_UPDATED", "EMPLOYEE", updated.Id.ToString(), updated.Name);
            SaveSnapshotUnsafe();
            return updated;
        }
    }

    public PagedResult<CardAssignmentModel> GetCards(string? cardUid, string? employeeCode, bool? isActive, int page, int perPage)
    {
        var query = _cards.AsEnumerable();
        if (!string.IsNullOrWhiteSpace(cardUid))
        {
            query = query.Where(x => x.CardUid.Contains(cardUid, StringComparison.OrdinalIgnoreCase));
        }
        if (!string.IsNullOrWhiteSpace(employeeCode))
        {
            query = query.Where(x => x.EmployeeCode.Contains(employeeCode, StringComparison.OrdinalIgnoreCase));
        }
        if (isActive.HasValue)
        {
            query = query.Where(x => x.IsActive == isActive.Value);
        }

        return Page(query.OrderByDescending(x => x.AssignedAt).ToList(), page, perPage);
    }

    public CardAssignmentModel AssignCard(AssignCardRequest request)
    {
        lock (_sync)
        {
            var normalizedCardUid = request.CardUid.Trim().ToUpperInvariant();
            if (string.IsNullOrWhiteSpace(normalizedCardUid))
            {
                throw new ArgumentException("VALIDATION_CARD_UID");
            }

            var employee = _employees.FirstOrDefault(x => x.Id == request.EmployeeId);
            if (employee is null)
            {
                throw new InvalidOperationException("EMPLOYEE_NOT_FOUND");
            }

            var existing = _cards.FirstOrDefault(x =>
                x.EmployeeId == employee.Id &&
                x.CardUid.Equals(normalizedCardUid, StringComparison.OrdinalIgnoreCase) &&
                x.IsActive);
            if (existing is not null)
            {
                return existing;
            }

            var now = DateTimeOffset.Now;
            for (var index = 0; index < _cards.Count; index++)
            {
                var current = _cards[index];
                if (!current.IsActive)
                {
                    continue;
                }

                var sameEmployee = current.EmployeeId == employee.Id;
                var sameCardUid = current.CardUid.Equals(normalizedCardUid, StringComparison.OrdinalIgnoreCase);
                if (!sameEmployee && !sameCardUid)
                {
                    continue;
                }

                _cards[index] = current with { IsActive = false, RevokedAt = now };
            }

            var card = new CardAssignmentModel(++_cardSequence, employee.Id, employee.EmployeeCode, employee.Name, normalizedCardUid, true, now, null);
            _cards.Add(card);
            AddAudit("ADMIN", DefaultAdminId, "CARD_ASSIGNED", "CARD", card.Id.ToString(), card.CardUid);
            SaveSnapshotUnsafe();
            return card;
        }
    }

    public bool RevokeCard(long cardId)
    {
        lock (_sync)
        {
            var index = _cards.FindIndex(x => x.Id == cardId);
            if (index < 0)
            {
                return false;
            }

            _cards[index] = _cards[index] with { IsActive = false, RevokedAt = DateTimeOffset.Now };
            AddAudit("ADMIN", DefaultAdminId, "CARD_REVOKED", "CARD", cardId.ToString(), _cards[index].CardUid);
            SaveSnapshotUnsafe();
            return true;
        }
    }

    public PagedResult<AttendanceEventModel> GetAttendanceEvents(DateOnly? from, DateOnly? to, string? employeeCode, string? receiveStatus, string? deviceCode, int page, int perPage)
    {
        var query = _attendanceEvents.AsEnumerable();
        if (from.HasValue)
        {
            query = query.Where(x => DateOnly.FromDateTime(x.OccurredAt.LocalDateTime) >= from.Value);
        }
        if (to.HasValue)
        {
            query = query.Where(x => DateOnly.FromDateTime(x.OccurredAt.LocalDateTime) <= to.Value);
        }
        if (!string.IsNullOrWhiteSpace(employeeCode))
        {
            query = query.Where(x => (x.EmployeeCode ?? string.Empty).Contains(employeeCode, StringComparison.OrdinalIgnoreCase));
        }
        if (!string.IsNullOrWhiteSpace(receiveStatus))
        {
            query = query.Where(x => x.ReceiveStatus.Equals(receiveStatus, StringComparison.OrdinalIgnoreCase));
        }
        if (!string.IsNullOrWhiteSpace(deviceCode))
        {
            query = query.Where(x => x.DeviceCode.Equals(deviceCode, StringComparison.OrdinalIgnoreCase));
        }

        return Page(query.OrderByDescending(x => x.OccurredAt).ToList(), page, perPage);
    }

    public PagedResult<AttendanceDailyModel> GetAttendanceDaily(string? targetMonth, string? employeeCode, string? departmentName, int page, int perPage)
    {
        var query = _attendanceDaily.AsEnumerable();
        if (!string.IsNullOrWhiteSpace(targetMonth))
        {
            query = query.Where(x => x.TargetDate.ToString("yyyy-MM") == targetMonth);
        }
        if (!string.IsNullOrWhiteSpace(employeeCode))
        {
            query = query.Where(x => x.EmployeeCode.Contains(employeeCode, StringComparison.OrdinalIgnoreCase));
        }
        if (!string.IsNullOrWhiteSpace(departmentName))
        {
            var employeeIds = _employees.Where(x => (x.DepartmentName ?? string.Empty).Contains(departmentName, StringComparison.OrdinalIgnoreCase)).Select(x => x.Id).ToHashSet();
            query = query.Where(x => employeeIds.Contains(x.EmployeeId));
        }

        return Page(query.OrderByDescending(x => x.TargetDate).ThenBy(x => x.EmployeeCode).ToList(), page, perPage);
    }

    public PagedResult<LeaveRequestModel> GetAdminLeaveRequests(string? status, string? leaveTypeCode, DateOnly? from, DateOnly? to, string? employeeName, int page, int perPage)
    {
        var query = _leaveRequests.AsEnumerable();
        if (!string.IsNullOrWhiteSpace(status))
        {
            query = query.Where(x => x.Status.Equals(status, StringComparison.OrdinalIgnoreCase));
        }
        if (!string.IsNullOrWhiteSpace(leaveTypeCode))
        {
            query = query.Where(x => x.LeaveTypeCode.Equals(leaveTypeCode, StringComparison.OrdinalIgnoreCase));
        }
        if (from.HasValue)
        {
            query = query.Where(x => x.StartDate >= from.Value);
        }
        if (to.HasValue)
        {
            query = query.Where(x => x.EndDate <= to.Value);
        }
        if (!string.IsNullOrWhiteSpace(employeeName))
        {
            query = query.Where(x => x.EmployeeName.Contains(employeeName, StringComparison.OrdinalIgnoreCase));
        }

        return Page(query.OrderByDescending(x => x.CreatedAt).ToList(), page, perPage);
    }

    public LeaveRequestModel? DecideLeave(long id, string actionType, string? comment)
    {
        lock (_sync)
        {
            var index = _leaveRequests.FindIndex(x => x.Id == id);
            if (index < 0)
            {
                return null;
            }

            var current = _leaveRequests[index];
            var status = actionType switch
            {
                "APPROVED" => "APPROVED",
                "REJECTED" => "REJECTED",
                "RETURNED" => "RETURNED",
                _ => throw new ArgumentOutOfRangeException(nameof(actionType))
            };

            var action = new LeaveActionModel(++_leaveActionSequence, id, actionType, DefaultAdminId, _employees.First(x => x.Id == DefaultAdminId).Name, comment, DateTimeOffset.Now);
            var updated = current with
            {
                Status = status,
                ApprovedBy = DefaultAdminId,
                ApprovedAt = DateTimeOffset.Now,
                DecisionComment = comment,
                Actions = current.Actions.Concat([action]).ToList()
            };

            _leaveRequests[index] = updated;
            if (status == "APPROVED")
            {
                ApplyLeaveApproval(updated);
            }

            AddNotification(updated.EmployeeId, "LEAVE_RESULT", "休暇申請の結果が更新されました", $"{updated.StartDate:yyyy-MM-dd} の申請が {status} になりました。");
            AddAudit("ADMIN", DefaultAdminId, $"LEAVE_{status}", "LEAVE_REQUEST", id.ToString(), comment ?? string.Empty);
            SaveSnapshotUnsafe();
            return updated;
        }
    }

    public PagedResult<PayrollStatementModel> GetAdminPayrollStatements(string? targetYearMonth, string? employeeCode, string? publishedStatus, int page, int perPage)
    {
        var query = _payrollStatements.AsEnumerable();
        if (!string.IsNullOrWhiteSpace(targetYearMonth))
        {
            query = query.Where(x => x.TargetYearMonth == targetYearMonth);
        }
        if (!string.IsNullOrWhiteSpace(employeeCode))
        {
            var ids = _employees.Where(x => x.EmployeeCode.Contains(employeeCode, StringComparison.OrdinalIgnoreCase)).Select(x => x.Id).ToHashSet();
            query = query.Where(x => ids.Contains(x.EmployeeId));
        }
        if (publishedStatus == "PUBLISHED")
        {
            query = query.Where(x => x.PublishedAt is not null);
        }
        if (publishedStatus == "UNPUBLISHED")
        {
            query = query.Where(x => x.PublishedAt is null);
        }

        return Page(query.OrderByDescending(x => x.TargetYearMonth).ToList(), page, perPage);
    }

    public PayrollStatementModel UploadPayrollStatement(long employeeId, string targetYearMonth, string fileName, DateTimeOffset? publishedAt)
    {
        lock (_sync)
        {
            _payrollStatements.RemoveAll(x => x.EmployeeId == employeeId && x.TargetYearMonth == targetYearMonth);
            var statement = new PayrollStatementModel(++_payrollSequence, employeeId, targetYearMonth, fileName, publishedAt, false, $"https://example.local/payroll/{targetYearMonth}/{fileName}");
            _payrollStatements.Add(statement);
            AddNotification(employeeId, "PAYROLL_PUBLISHED", "給与明細が公開されました", $"{targetYearMonth} の給与明細を確認できます。");
            AddAudit("ADMIN", DefaultAdminId, "PAYROLL_UPLOADED", "PAYROLL_STATEMENT", statement.Id.ToString(), fileName);
            SaveSnapshotUnsafe();
            return statement;
        }
    }

    private void LoadOrSeed()
    {
        try
        {
            if (File.Exists(_snapshotPath))
            {
                var json = File.ReadAllText(_snapshotPath);
                var snapshot = JsonSerializer.Deserialize<AppStateSnapshot>(json, JsonOptions);
                if (snapshot is not null)
                {
                    ApplySnapshot(snapshot);
                    _logger.LogInformation("App state loaded from {SnapshotPath}", _snapshotPath);
                    return;
                }
            }
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Failed to load app state from {SnapshotPath}. Seeding default data.", _snapshotPath);
        }

        Seed();
        SaveSnapshot();
    }

    private void SaveSnapshot()
    {
        lock (_sync)
        {
            SaveSnapshotUnsafe();
        }
    }

    private void SaveSnapshotUnsafe()
    {
        var snapshotDirectory = Path.GetDirectoryName(_snapshotPath);
        if (!string.IsNullOrWhiteSpace(snapshotDirectory))
        {
            Directory.CreateDirectory(snapshotDirectory);
        }

        var snapshot = new AppStateSnapshot(
            _employees.ToList(),
            _accounts.ToList(),
            _devices.ToList(),
            _cards.ToList(),
            _attendanceEvents.ToList(),
            _attendanceDaily.ToList(),
            _paidLeaveGrants.ToList(),
            _leaveRequests.ToList(),
            _payrollStatements.ToList(),
            _payrollViews.ToList(),
            _notifications.ToList(),
            _auditLogs.ToList(),
            _employeeSequence,
            _cardSequence,
            _attendanceSequence,
            _leaveSequence,
            _leaveActionSequence,
            _payrollSequence,
            _payrollViewSequence,
            _notificationSequence,
            _auditSequence);

        File.WriteAllText(_snapshotPath, JsonSerializer.Serialize(snapshot, JsonOptions));
    }

    private void ApplySnapshot(AppStateSnapshot snapshot)
    {
        _employees.Clear();
        _employees.AddRange(snapshot.Employees);
        _accounts.Clear();
        _accounts.AddRange(snapshot.Accounts);
        _devices.Clear();
        _devices.AddRange(snapshot.Devices);
        _cards.Clear();
        _cards.AddRange(snapshot.Cards);
        _attendanceEvents.Clear();
        _attendanceEvents.AddRange(snapshot.AttendanceEvents);
        _attendanceDaily.Clear();
        _attendanceDaily.AddRange(snapshot.AttendanceDaily);
        _paidLeaveGrants.Clear();
        _paidLeaveGrants.AddRange(snapshot.PaidLeaveGrants);
        _leaveRequests.Clear();
        _leaveRequests.AddRange(snapshot.LeaveRequests);
        _payrollStatements.Clear();
        _payrollStatements.AddRange(snapshot.PayrollStatements);
        _payrollViews.Clear();
        _payrollViews.AddRange(snapshot.PayrollViews);
        _notifications.Clear();
        _notifications.AddRange(snapshot.Notifications);
        _auditLogs.Clear();
        _auditLogs.AddRange(snapshot.AuditLogs);

        _employeeSequence = snapshot.EmployeeSequence;
        _cardSequence = snapshot.CardSequence;
        _attendanceSequence = snapshot.AttendanceSequence;
        _leaveSequence = snapshot.LeaveSequence;
        _leaveActionSequence = snapshot.LeaveActionSequence;
        _payrollSequence = snapshot.PayrollSequence;
        _payrollViewSequence = snapshot.PayrollViewSequence;
        _notificationSequence = snapshot.NotificationSequence;
        _auditSequence = snapshot.AuditSequence;
    }

    private static string GetProjectRootPath()
    {
        var directory = new DirectoryInfo(AppContext.BaseDirectory);
        while (directory is not null)
        {
            if (File.Exists(Path.Combine(directory.FullName, "StaffHub.Api.csproj")))
            {
                return directory.FullName;
            }

            directory = directory.Parent;
        }

        return AppContext.BaseDirectory;
    }

    public PagedResult<AuditLogModel> GetAuditLogs(DateOnly? from, DateOnly? to, string? action, string? actorKeyword, string? targetType, int page, int perPage)
    {
        var query = _auditLogs.AsEnumerable();
        if (from.HasValue)
        {
            query = query.Where(x => DateOnly.FromDateTime(x.OccurredAt.LocalDateTime) >= from.Value);
        }
        if (to.HasValue)
        {
            query = query.Where(x => DateOnly.FromDateTime(x.OccurredAt.LocalDateTime) <= to.Value);
        }
        if (!string.IsNullOrWhiteSpace(action))
        {
            query = query.Where(x => x.Action.Contains(action, StringComparison.OrdinalIgnoreCase));
        }
        if (!string.IsNullOrWhiteSpace(actorKeyword))
        {
            query = query.Where(x => (x.ActorId?.ToString() ?? string.Empty).Contains(actorKeyword, StringComparison.OrdinalIgnoreCase));
        }
        if (!string.IsNullOrWhiteSpace(targetType))
        {
            query = query.Where(x => x.TargetType.Equals(targetType, StringComparison.OrdinalIgnoreCase));
        }

        return Page(query.OrderByDescending(x => x.OccurredAt).ToList(), page, perPage);
    }

    private void RebuildAttendanceDaily(long employeeId, DateOnly targetDate)
    {
        var employee = _employees.First(x => x.Id == employeeId);
        var events = _attendanceEvents
            .Where(x => x.EmployeeId == employeeId && x.ReceiveStatus == "ACCEPTED" && DateOnly.FromDateTime(x.OccurredAt.LocalDateTime) == targetDate)
            .OrderBy(x => x.OccurredAt)
            .ToList();

        var clockInAt = events.FirstOrDefault(x => x.EventType == "CLOCK_IN")?.OccurredAt;
        var clockOutAt = events.LastOrDefault(x => x.EventType == "CLOCK_OUT" && (!clockInAt.HasValue || x.OccurredAt >= clockInAt.Value))?.OccurredAt;
        var workMinutes = clockInAt.HasValue && clockOutAt.HasValue ? (int?)Math.Max(0, (clockOutAt.Value - clockInAt.Value).TotalMinutes) : null;
        var model = new AttendanceDailyModel(employee.Id, employee.EmployeeCode, employee.Name, targetDate, clockInAt, clockOutAt, workMinutes, false, false, null);

        var index = _attendanceDaily.FindIndex(x => x.EmployeeId == employeeId && x.TargetDate == targetDate);
        if (index >= 0)
        {
            var existing = _attendanceDaily[index];
            _attendanceDaily[index] = model with { AbsenceFlag = existing.AbsenceFlag, SpecialLeaveFlag = existing.SpecialLeaveFlag, PaidLeaveUnit = existing.PaidLeaveUnit };
        }
        else
        {
            _attendanceDaily.Add(model);
        }
    }

    private void ApplyLeaveApproval(LeaveRequestModel leave)
    {
        if (leave.LeaveTypeCode == "PAID")
        {
            var remaining = leave.QuantityDays;
            foreach (var grant in _paidLeaveGrants.Where(x => x.EmployeeId == leave.EmployeeId).OrderBy(x => x.ExpiresOn ?? DateOnly.MaxValue).ToList())
            {
                if (remaining <= 0)
                {
                    break;
                }

                var available = grant.GrantedDays - grant.UsedDays;
                if (available <= 0)
                {
                    continue;
                }

                var consume = Math.Min(available, remaining);
                var grantIndex = _paidLeaveGrants.FindIndex(x => x.Id == grant.Id);
                _paidLeaveGrants[grantIndex] = grant with { UsedDays = grant.UsedDays + consume };
                remaining -= consume;
            }
        }

        for (var date = leave.StartDate; date <= leave.EndDate; date = date.AddDays(1))
        {
            var employee = _employees.First(x => x.Id == leave.EmployeeId);
            var index = _attendanceDaily.FindIndex(x => x.EmployeeId == leave.EmployeeId && x.TargetDate == date);
            var daily = index >= 0 ? _attendanceDaily[index] : new AttendanceDailyModel(employee.Id, employee.EmployeeCode, employee.Name, date, null, null, null, false, false, null);

            daily = leave.LeaveTypeCode switch
            {
                "PAID" => daily with { PaidLeaveUnit = leave.DayUnit == "HALF" ? 0.5m : 1.0m, AbsenceFlag = false, SpecialLeaveFlag = false },
                "ABSENCE" => daily with { AbsenceFlag = true, SpecialLeaveFlag = false, PaidLeaveUnit = null },
                "SPECIAL" => daily with { SpecialLeaveFlag = true, AbsenceFlag = false, PaidLeaveUnit = null },
                _ => daily
            };

            if (index >= 0)
            {
                _attendanceDaily[index] = daily;
            }
            else
            {
                _attendanceDaily.Add(daily);
            }
        }
    }

    private void AddNotification(long employeeId, string notificationType, string title, string body)
        => _notifications.Add(new NotificationModel(++_notificationSequence, employeeId, notificationType, title, body, false, DateTimeOffset.Now, null));

    private void AddAudit(string actorType, long? actorId, string action, string targetType, string? targetId, string detail)
        => _auditLogs.Add(new AuditLogModel(++_auditSequence, actorType, actorId, action, targetType, targetId, detail, DateTimeOffset.Now));

    private EmployeeBrief ToBrief(long employeeId)
    {
        var employee = _employees.First(x => x.Id == employeeId);
        return new EmployeeBrief(employee.Id, employee.EmployeeCode, employee.Name);
    }

    private decimal GetPaidLeaveBalance(long employeeId)
        => _paidLeaveGrants.Where(x => x.EmployeeId == employeeId).Sum(x => x.GrantedDays - x.UsedDays);

    private static string ToLeaveTypeName(string code) => code switch
    {
        "PAID" => "有給休暇",
        "ABSENCE" => "欠勤",
        "SPECIAL" => "特別休暇",
        _ => code
    };

    private static PagedResult<T> Page<T>(IReadOnlyList<T> items, int page, int perPage)
    {
        page = page <= 0 ? 1 : page;
        perPage = perPage <= 0 ? 20 : perPage;
        var total = items.Count;
        var paged = items.Skip((page - 1) * perPage).Take(perPage).ToList();
        return new PagedResult<T>(paged, page, perPage, total);
    }

    private void Seed()
    {
        _employees.AddRange(
        [
            new EmployeeModel(1, "E0001", "山田 太郎", "ヤマダ タロウ", "総務部", "FULL_TIME", "ACTIVE", new DateOnly(2024, 4, 1), null),
            new EmployeeModel(2, "E0002", "佐藤 花子", "サトウ ハナコ", "介護部", "PART_TIME", "ACTIVE", new DateOnly(2024, 4, 1), null),
            new EmployeeModel(100, "A0001", "管理者", "カンリシャ", "管理部", "ADMIN", "ACTIVE", new DateOnly(2024, 4, 1), null)
        ]);

        _accounts.AddRange(
        [
            new AccountCredential(1, "staff001", "password", "EMPLOYEE"),
            new AccountCredential(100, "admin001", "password", "ADMIN")
        ]);

        _devices.Add(new AttendanceDeviceModel(1, "PC-ENTRANCE-01", "玄関端末", "玄関", "0.1.0", true, DateTimeOffset.Now.AddMinutes(-2)));
        _cards.Add(new CardAssignmentModel(1, 1, "E0001", "山田 太郎", "0123456789ABCDEF", true, DateTimeOffset.Now.AddDays(-20), null));
        _cards.Add(new CardAssignmentModel(2, 2, "E0002", "佐藤 花子", "1111222233334444", true, DateTimeOffset.Now.AddDays(-19), null));

        _paidLeaveGrants.Add(new PaidLeaveGrantModel(1, 1, new DateOnly(2025, 4, 1), 10m, 1.5m, new DateOnly(2027, 3, 31), "初期付与"));
        _paidLeaveGrants.Add(new PaidLeaveGrantModel(2, 2, new DateOnly(2025, 4, 1), 10m, 0m, new DateOnly(2027, 3, 31), "初期付与"));

        var leaveAction = new LeaveActionModel(1, 1, "APPLIED", 1, "山田 太郎", null, DateTimeOffset.Now.AddDays(-1));
        _leaveRequests.Add(new LeaveRequestModel(1, 1, "山田 太郎", "PAID", "有給休暇", new DateOnly(2026, 4, 3), new DateOnly(2026, 4, 3), "HALF", "AM", 0.5m, "私用のため", "PENDING", null, null, null, DateTimeOffset.Now.AddDays(-1), [leaveAction]));

        _payrollStatements.Add(new PayrollStatementModel(1, 1, "2026-02", "salary_2026_02.pdf", DateTimeOffset.Now.AddDays(-1), false, "https://example.local/payroll/2026-02/salary_2026_02.pdf"));
        _payrollStatements.Add(new PayrollStatementModel(2, 1, "2026-01", "salary_2026_01.pdf", DateTimeOffset.Now.AddDays(-30), true, "https://example.local/payroll/2026-01/salary_2026_01.pdf"));
        _payrollViews.Add(new PayrollViewModel(1, 2, 1, DateTimeOffset.Now.AddDays(-29)));

        _notifications.Add(new NotificationModel(1, 1, "LEAVE_RESULT", "休暇申請を確認中です", "承認者が内容を確認しています。", false, DateTimeOffset.Now.AddHours(-8), null));
        _notifications.Add(new NotificationModel(2, 1, "PAYROLL_PUBLISHED", "給与明細が公開されました", "2026-02 の給与明細を確認できます。", false, DateTimeOffset.Now.AddDays(-1), null));

        var previousDay = DateTime.Today.AddDays(-1);
        var previousDayStart = new DateTimeOffset(previousDay, TimeSpan.FromHours(9));
        _attendanceEvents.Add(new AttendanceEventModel(1, 1, "E0001", "山田 太郎", 1, "PC-ENTRANCE-01", "玄関端末", "0123456789ABCDEF", previousDayStart.AddHours(8).AddMinutes(31), "CLOCK_IN", "ACCEPTED", null, false));
        _attendanceEvents.Add(new AttendanceEventModel(2, 1, "E0001", "山田 太郎", 1, "PC-ENTRANCE-01", "玄関端末", "0123456789ABCDEF", previousDayStart.AddHours(17).AddMinutes(30), "CLOCK_OUT", "ACCEPTED", null, false));
        _attendanceDaily.Add(new AttendanceDailyModel(1, "E0001", "山田 太郎", DateOnly.FromDateTime(previousDay), previousDayStart.AddHours(8).AddMinutes(31), previousDayStart.AddHours(17).AddMinutes(30), 539, false, false, null));
        _auditLogs.Add(new AuditLogModel(1, "SYSTEM", null, "SEED_DATA_CREATED", "SYSTEM", "BOOTSTRAP", "初期データを生成しました。", DateTimeOffset.Now.AddMinutes(-5)));
    }

    private sealed record AppStateSnapshot(
        IReadOnlyList<EmployeeModel> Employees,
        IReadOnlyList<AccountCredential> Accounts,
        IReadOnlyList<AttendanceDeviceModel> Devices,
        IReadOnlyList<CardAssignmentModel> Cards,
        IReadOnlyList<AttendanceEventModel> AttendanceEvents,
        IReadOnlyList<AttendanceDailyModel> AttendanceDaily,
        IReadOnlyList<PaidLeaveGrantModel> PaidLeaveGrants,
        IReadOnlyList<LeaveRequestModel> LeaveRequests,
        IReadOnlyList<PayrollStatementModel> PayrollStatements,
        IReadOnlyList<PayrollViewModel> PayrollViews,
        IReadOnlyList<NotificationModel> Notifications,
        IReadOnlyList<AuditLogModel> AuditLogs,
        long EmployeeSequence,
        long CardSequence,
        long AttendanceSequence,
        long LeaveSequence,
        long LeaveActionSequence,
        long PayrollSequence,
        long PayrollViewSequence,
        long NotificationSequence,
        long AuditSequence);
}
