using System.Text.Json;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.Logging;
using Npgsql;
using StaffHub.Api.Models;

namespace StaffHub.Api.Services;

public sealed partial class PostgresCoreStore
{
    private const string TimeZoneName = "Asia/Tokyo";
    private readonly string? _connectionString;
    private readonly ILogger<PostgresCoreStore> _logger;

    public PostgresCoreStore(IConfiguration configuration, ILogger<PostgresCoreStore> logger)
    {
        _logger = logger;
        _connectionString = configuration.GetConnectionString("StaffHubPostgres");
        IsEnabled = configuration.GetValue<bool>("Persistence:UsePostgreSqlCore") && !string.IsNullOrWhiteSpace(_connectionString);
    }

    public bool IsEnabled { get; }

    public string PersistenceMode => IsEnabled ? "postgresql-core" : "json-file";

    public async Task InitializeAsync(InMemoryAppStore seedStore, CancellationToken cancellationToken = default)
    {
        if (!IsEnabled)
        {
            return;
        }

        await using var connection = CreateConnection();
        await connection.OpenAsync(cancellationToken);

        if (!await TableExistsAsync(connection, "employees", cancellationToken))
        {
            var schemaPath = FindSchemaPath();
            var sql = await File.ReadAllTextAsync(schemaPath, cancellationToken);
            await using var command = new NpgsqlCommand(sql, connection);
            await command.ExecuteNonQueryAsync(cancellationToken);
            _logger.LogInformation("PostgreSQL schema initialized from {SchemaPath}", schemaPath);
        }

        await SyncEmployeesAsync(connection, seedStore.GetBootstrapEmployees(), cancellationToken);
        await SyncDevicesAsync(connection, seedStore.GetBootstrapDevices(), cancellationToken);
    }

    public async Task<PunchResult> PunchAsync(PunchRequest request, CancellationToken cancellationToken = default)
    {
        await using var connection = CreateConnection();
        await connection.OpenAsync(cancellationToken);
        await using var transaction = await connection.BeginTransactionAsync(cancellationToken);

        var device = await FindActiveDeviceAsync(connection, transaction, request.DeviceCode, cancellationToken);
        if (device is null)
        {
            throw new InvalidOperationException("DEVICE_DISABLED");
        }

        var normalizedCardUid = request.CardUid.Trim().ToUpperInvariant();
        var owner = await FindActiveCardOwnerAsync(connection, transaction, normalizedCardUid, cancellationToken);
        if (owner is null)
        {
            var rejectedId = await InsertAttendanceEventAsync(connection, transaction, null, device.Id, normalizedCardUid, request.OccurredAt, null, "REJECTED", "CARD_NOT_REGISTERED", request.DedupeKey, cancellationToken);
            await InsertAuditAsync(connection, transaction, "DEVICE", device.Id, "ATTENDANCE_REJECTED", "CARD", normalizedCardUid, JsonSerializer.Serialize(new
            {
                request.DeviceCode,
                request.CardUid,
                reason = "CARD_NOT_REGISTERED",
                attendanceEventId = rejectedId
            }), cancellationToken);

            await transaction.CommitAsync(cancellationToken);
            throw new InvalidOperationException("CARD_NOT_REGISTERED");
        }

        var window = await GetAttendanceWindowAsync(connection, transaction, owner.EmployeeId, request.OccurredAt, cancellationToken);
        var eventType = window.LastAcceptedEventType is null || window.LastAcceptedEventType == "CLOCK_OUT" ? "CLOCK_IN" : "CLOCK_OUT";

        var resultType = "SUCCESS";
        var resultMessage = eventType == "CLOCK_IN" ? "出勤を記録しました。" : "退勤を記録しました。";
        if (window.LastAcceptedOccurredAt.HasValue && (request.OccurredAt - window.LastAcceptedOccurredAt.Value).TotalMinutes <= 2)
        {
            resultType = "WARNING";
            resultMessage = "短時間の連続打刻です。内容を確認してください。";
        }

        var eventId = await InsertAttendanceEventAsync(connection, transaction, owner.EmployeeId, device.Id, normalizedCardUid, request.OccurredAt, eventType, "ACCEPTED", null, request.DedupeKey, cancellationToken);
        await RebuildAttendanceDailyAsync(connection, transaction, owner, request.OccurredAt, cancellationToken);

        await InsertAuditAsync(connection, transaction, "DEVICE", device.Id, "ATTENDANCE_ACCEPTED", "ATTENDANCE_EVENT", eventId.ToString(), JsonSerializer.Serialize(new
        {
            owner.EmployeeId,
            owner.EmployeeCode,
            owner.EmployeeName,
            eventType,
            request.OccurredAt
        }), cancellationToken);

        await transaction.CommitAsync(cancellationToken);

        return new PunchResult(
            eventId,
            new EmployeeBrief(owner.EmployeeId, owner.EmployeeCode, owner.EmployeeName),
            eventType,
            resultType,
            resultMessage,
            request.OccurredAt,
            false);
    }

    public async Task<PagedResult<CardAssignmentModel>> GetCardsAsync(string? cardUid, string? employeeCode, bool? isActive, int page, int perPage, CancellationToken cancellationToken = default)
    {
        var conditions = new List<string>();
        var parameters = new List<NpgsqlParameter>();
        var parameterIndex = 0;

        if (!string.IsNullOrWhiteSpace(cardUid))
        {
            parameterIndex++;
            conditions.Add($"upper(c.card_uid) like @p{parameterIndex}");
            parameters.Add(new NpgsqlParameter($"p{parameterIndex}", $"%{cardUid.Trim().ToUpperInvariant()}%"));
        }

        if (!string.IsNullOrWhiteSpace(employeeCode))
        {
            parameterIndex++;
            conditions.Add($"upper(e.employee_code) like @p{parameterIndex}");
            parameters.Add(new NpgsqlParameter($"p{parameterIndex}", $"%{employeeCode.Trim().ToUpperInvariant()}%"));
        }

        if (isActive.HasValue)
        {
            parameterIndex++;
            conditions.Add($"c.is_active = @p{parameterIndex}");
            parameters.Add(new NpgsqlParameter($"p{parameterIndex}", isActive.Value));
        }

        var whereClause = conditions.Count == 0 ? string.Empty : $"where {string.Join(" and ", conditions)}";
        var offset = Math.Max(0, (Math.Max(page, 1) - 1) * Math.Max(perPage, 1));

        await using var connection = CreateConnection();
        await connection.OpenAsync(cancellationToken);

        var total = await ExecuteCountAsync(connection, """
            select count(*)
            from employee_cards c
            inner join employees e on e.id = c.employee_id
            """, whereClause, parameters, cancellationToken);

        var sql = $"""
            select
                c.id,
                c.employee_id,
                e.employee_code,
                e.name,
                c.card_uid,
                c.is_active,
                c.assigned_at,
                c.revoked_at
            from employee_cards c
            inner join employees e on e.id = c.employee_id
            {whereClause}
            order by c.assigned_at desc
            limit @limit offset @offset
            """;

        await using var command = new NpgsqlCommand(sql, connection);
        foreach (var parameter in parameters)
        {
            command.Parameters.Add(parameter);
        }

        command.Parameters.AddWithValue("limit", Math.Max(perPage, 1));
        command.Parameters.AddWithValue("offset", offset);

        var items = new List<CardAssignmentModel>();
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        while (await reader.ReadAsync(cancellationToken))
        {
            items.Add(new CardAssignmentModel(
                reader.GetInt64(0),
                reader.GetInt64(1),
                reader.GetString(2),
                reader.GetString(3),
                reader.GetString(4),
                reader.GetBoolean(5),
                reader.GetFieldValue<DateTimeOffset>(6),
                reader.IsDBNull(7) ? null : reader.GetFieldValue<DateTimeOffset>(7)));
        }

        return new PagedResult<CardAssignmentModel>(items, Math.Max(page, 1), Math.Max(perPage, 1), checked((int)total));
    }

    public async Task<CardAssignmentModel> AssignCardAsync(AssignCardRequest request, CancellationToken cancellationToken = default)
    {
        var normalizedCardUid = request.CardUid.Trim().ToUpperInvariant();
        if (string.IsNullOrWhiteSpace(normalizedCardUid))
        {
            throw new ArgumentException("VALIDATION_CARD_UID");
        }

        await using var connection = CreateConnection();
        await connection.OpenAsync(cancellationToken);
        await using var transaction = await connection.BeginTransactionAsync(cancellationToken);

        var employee = await FindEmployeeAsync(connection, transaction, request.EmployeeId, cancellationToken);
        if (employee is null)
        {
            throw new InvalidOperationException("EMPLOYEE_NOT_FOUND");
        }

        await using (var existingCommand = new NpgsqlCommand("""
            select
                c.id,
                c.employee_id,
                e.employee_code,
                e.name,
                c.card_uid,
                c.is_active,
                c.assigned_at,
                c.revoked_at
            from employee_cards c
            inner join employees e on e.id = c.employee_id
            where c.employee_id = @employeeId
              and upper(c.card_uid) = @cardUid
              and c.is_active = true
            limit 1
            """, connection, transaction))
        {
            existingCommand.Parameters.AddWithValue("employeeId", request.EmployeeId);
            existingCommand.Parameters.AddWithValue("cardUid", normalizedCardUid);

            await using var reader = await existingCommand.ExecuteReaderAsync(cancellationToken);
            if (await reader.ReadAsync(cancellationToken))
            {
                var existing = new CardAssignmentModel(
                    reader.GetInt64(0),
                    reader.GetInt64(1),
                    reader.GetString(2),
                    reader.GetString(3),
                    reader.GetString(4),
                    reader.GetBoolean(5),
                    reader.GetFieldValue<DateTimeOffset>(6),
                    reader.IsDBNull(7) ? null : reader.GetFieldValue<DateTimeOffset>(7));

                await transaction.CommitAsync(cancellationToken);
                return existing;
            }
        }

        await using (var revokeCommand = new NpgsqlCommand("""
            update employee_cards
            set is_active = false,
                revoked_at = now(),
                updated_at = now()
            where is_active = true
              and (employee_id = @employeeId or upper(card_uid) = @cardUid)
            """, connection, transaction))
        {
            revokeCommand.Parameters.AddWithValue("employeeId", request.EmployeeId);
            revokeCommand.Parameters.AddWithValue("cardUid", normalizedCardUid);
            await revokeCommand.ExecuteNonQueryAsync(cancellationToken);
        }

        CardAssignmentModel result;
        await using (var insertCommand = new NpgsqlCommand("""
            insert into employee_cards (employee_id, card_uid, is_active, assigned_at, created_at, updated_at)
            values (@employeeId, @cardUid, true, now(), now(), now())
            returning id, assigned_at
            """, connection, transaction))
        {
            insertCommand.Parameters.AddWithValue("employeeId", request.EmployeeId);
            insertCommand.Parameters.AddWithValue("cardUid", normalizedCardUid);

            await using var reader = await insertCommand.ExecuteReaderAsync(cancellationToken);
            await reader.ReadAsync(cancellationToken);
            result = new CardAssignmentModel(reader.GetInt64(0), employee.Id, employee.EmployeeCode, employee.Name, normalizedCardUid, true, reader.GetFieldValue<DateTimeOffset>(1), null);
        }

        await InsertAuditAsync(connection, transaction, "ADMIN", 100, "CARD_ASSIGNED", "CARD", result.Id.ToString(), JsonSerializer.Serialize(new
        {
            request.EmployeeId,
            result.CardUid
        }), cancellationToken);

        await transaction.CommitAsync(cancellationToken);
        return result;
    }

    public async Task<bool> RevokeCardAsync(long cardId, CancellationToken cancellationToken = default)
    {
        await using var connection = CreateConnection();
        await connection.OpenAsync(cancellationToken);
        await using var transaction = await connection.BeginTransactionAsync(cancellationToken);

        string? cardUid;
        await using (var findCommand = new NpgsqlCommand("select card_uid from employee_cards where id = @id", connection, transaction))
        {
            findCommand.Parameters.AddWithValue("id", cardId);
            cardUid = await findCommand.ExecuteScalarAsync(cancellationToken) as string;
        }

        if (cardUid is null)
        {
            return false;
        }

        await using (var updateCommand = new NpgsqlCommand("""
            update employee_cards
            set is_active = false,
                revoked_at = now(),
                updated_at = now()
            where id = @id
            """, connection, transaction))
        {
            updateCommand.Parameters.AddWithValue("id", cardId);
            await updateCommand.ExecuteNonQueryAsync(cancellationToken);
        }

        await InsertAuditAsync(connection, transaction, "ADMIN", 100, "CARD_REVOKED", "CARD", cardId.ToString(), JsonSerializer.Serialize(new
        {
            cardId,
            cardUid
        }), cancellationToken);

        await transaction.CommitAsync(cancellationToken);
        return true;
    }

    public async Task<PagedResult<AttendanceEventModel>> GetAttendanceEventsAsync(DateOnly? from, DateOnly? to, string? employeeCode, string? receiveStatus, string? deviceCode, int page, int perPage, CancellationToken cancellationToken = default)
    {
        var conditions = new List<string>();
        var parameters = new List<NpgsqlParameter>();
        var parameterIndex = 0;

        if (from.HasValue)
        {
            parameterIndex++;
            conditions.Add($"date(ae.occurred_at at time zone '{TimeZoneName}') >= @p{parameterIndex}");
            parameters.Add(new NpgsqlParameter($"p{parameterIndex}", from.Value));
        }

        if (to.HasValue)
        {
            parameterIndex++;
            conditions.Add($"date(ae.occurred_at at time zone '{TimeZoneName}') <= @p{parameterIndex}");
            parameters.Add(new NpgsqlParameter($"p{parameterIndex}", to.Value));
        }

        if (!string.IsNullOrWhiteSpace(employeeCode))
        {
            parameterIndex++;
            conditions.Add($"upper(coalesce(e.employee_code, '')) like @p{parameterIndex}");
            parameters.Add(new NpgsqlParameter($"p{parameterIndex}", $"%{employeeCode.Trim().ToUpperInvariant()}%"));
        }

        if (!string.IsNullOrWhiteSpace(receiveStatus))
        {
            parameterIndex++;
            conditions.Add($"upper(ae.receive_status) = @p{parameterIndex}");
            parameters.Add(new NpgsqlParameter($"p{parameterIndex}", receiveStatus.Trim().ToUpperInvariant()));
        }

        if (!string.IsNullOrWhiteSpace(deviceCode))
        {
            parameterIndex++;
            conditions.Add($"upper(ad.device_code) = @p{parameterIndex}");
            parameters.Add(new NpgsqlParameter($"p{parameterIndex}", deviceCode.Trim().ToUpperInvariant()));
        }

        var whereClause = conditions.Count == 0 ? string.Empty : $"where {string.Join(" and ", conditions)}";
        var offset = Math.Max(0, (Math.Max(page, 1) - 1) * Math.Max(perPage, 1));

        await using var connection = CreateConnection();
        await connection.OpenAsync(cancellationToken);

        var total = await ExecuteCountAsync(connection, """
            select count(*)
            from attendance_events ae
            inner join attendance_devices ad on ad.id = ae.device_id
            left join employees e on e.id = ae.employee_id
            """, whereClause, parameters, cancellationToken);

        var sql = $"""
            select
                ae.id,
                ae.employee_id,
                e.employee_code,
                e.name,
                ae.device_id,
                ad.device_code,
                ad.name,
                ae.card_uid,
                ae.occurred_at,
                ae.event_type,
                ae.receive_status,
                ae.rejection_reason,
                ae.offline_saved
            from attendance_events ae
            inner join attendance_devices ad on ad.id = ae.device_id
            left join employees e on e.id = ae.employee_id
            {whereClause}
            order by ae.occurred_at desc
            limit @limit offset @offset
            """;

        await using var command = new NpgsqlCommand(sql, connection);
        foreach (var parameter in parameters)
        {
            command.Parameters.Add(parameter);
        }

        command.Parameters.AddWithValue("limit", Math.Max(perPage, 1));
        command.Parameters.AddWithValue("offset", offset);

        var items = new List<AttendanceEventModel>();
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        while (await reader.ReadAsync(cancellationToken))
        {
            items.Add(new AttendanceEventModel(
                reader.GetInt64(0),
                reader.IsDBNull(1) ? null : reader.GetInt64(1),
                reader.IsDBNull(2) ? null : reader.GetString(2),
                reader.IsDBNull(3) ? null : reader.GetString(3),
                reader.GetInt64(4),
                reader.GetString(5),
                reader.GetString(6),
                reader.GetString(7),
                reader.GetFieldValue<DateTimeOffset>(8),
                reader.IsDBNull(9) ? null : reader.GetString(9),
                reader.GetString(10),
                reader.IsDBNull(11) ? null : reader.GetString(11),
                reader.GetBoolean(12)));
        }

        return new PagedResult<AttendanceEventModel>(items, Math.Max(page, 1), Math.Max(perPage, 1), checked((int)total));
    }

    public async Task<PagedResult<AttendanceDailyModel>> GetAttendanceDailyAsync(string? targetMonth, string? employeeCode, string? departmentName, int page, int perPage, CancellationToken cancellationToken = default)
    {
        var conditions = new List<string>();
        var parameters = new List<NpgsqlParameter>();
        var parameterIndex = 0;

        if (!string.IsNullOrWhiteSpace(targetMonth))
        {
            parameterIndex++;
            conditions.Add($"to_char(ad.target_date, 'YYYY-MM') = @p{parameterIndex}");
            parameters.Add(new NpgsqlParameter($"p{parameterIndex}", targetMonth.Trim()));
        }

        if (!string.IsNullOrWhiteSpace(employeeCode))
        {
            parameterIndex++;
            conditions.Add($"upper(e.employee_code) like @p{parameterIndex}");
            parameters.Add(new NpgsqlParameter($"p{parameterIndex}", $"%{employeeCode.Trim().ToUpperInvariant()}%"));
        }

        if (!string.IsNullOrWhiteSpace(departmentName))
        {
            parameterIndex++;
            conditions.Add($"upper(coalesce(e.department_name, '')) like @p{parameterIndex}");
            parameters.Add(new NpgsqlParameter($"p{parameterIndex}", $"%{departmentName.Trim().ToUpperInvariant()}%"));
        }

        var whereClause = conditions.Count == 0 ? string.Empty : $"where {string.Join(" and ", conditions)}";
        var offset = Math.Max(0, (Math.Max(page, 1) - 1) * Math.Max(perPage, 1));

        await using var connection = CreateConnection();
        await connection.OpenAsync(cancellationToken);

        var total = await ExecuteCountAsync(connection, """
            select count(*)
            from attendance_daily ad
            inner join employees e on e.id = ad.employee_id
            """, whereClause, parameters, cancellationToken);

        var sql = $"""
            select
                ad.employee_id,
                e.employee_code,
                e.name,
                ad.target_date,
                ad.clock_in_at,
                ad.clock_out_at,
                ad.work_minutes,
                ad.absence_flag,
                ad.special_leave_flag,
                ad.paid_leave_unit
            from attendance_daily ad
            inner join employees e on e.id = ad.employee_id
            {whereClause}
            order by ad.target_date desc, e.employee_code
            limit @limit offset @offset
            """;

        await using var command = new NpgsqlCommand(sql, connection);
        foreach (var parameter in parameters)
        {
            command.Parameters.Add(parameter);
        }

        command.Parameters.AddWithValue("limit", Math.Max(perPage, 1));
        command.Parameters.AddWithValue("offset", offset);

        var items = new List<AttendanceDailyModel>();
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        while (await reader.ReadAsync(cancellationToken))
        {
            items.Add(new AttendanceDailyModel(
                reader.GetInt64(0),
                reader.GetString(1),
                reader.GetString(2),
                reader.GetFieldValue<DateOnly>(3),
                reader.IsDBNull(4) ? null : reader.GetFieldValue<DateTimeOffset>(4),
                reader.IsDBNull(5) ? null : reader.GetFieldValue<DateTimeOffset>(5),
                reader.IsDBNull(6) ? null : reader.GetInt32(6),
                reader.GetBoolean(7),
                reader.GetBoolean(8),
                reader.IsDBNull(9) ? null : reader.GetDecimal(9)));
        }

        return new PagedResult<AttendanceDailyModel>(items, Math.Max(page, 1), Math.Max(perPage, 1), checked((int)total));
    }

    private NpgsqlConnection CreateConnection()
        => new(_connectionString ?? throw new InvalidOperationException("StaffHubPostgres connection string is not configured."));

    private static async Task<bool> TableExistsAsync(NpgsqlConnection connection, string tableName, CancellationToken cancellationToken)
    {
        await using var command = new NpgsqlCommand("select to_regclass(@name)::text", connection);
        command.Parameters.AddWithValue("name", $"public.{tableName}");
        var result = await command.ExecuteScalarAsync(cancellationToken) as string;
        return !string.IsNullOrWhiteSpace(result);
    }

    private async Task SyncEmployeesAsync(NpgsqlConnection connection, IReadOnlyList<EmployeeModel> employees, CancellationToken cancellationToken)
    {
        foreach (var employee in employees)
        {
            await using var command = new NpgsqlCommand("""
                insert into employees (id, employee_code, name, kana, department_name, employment_type, status, joined_on, retired_on, created_at, updated_at)
                values (@id, @employeeCode, @name, @kana, @departmentName, @employmentType, @status, @joinedOn, @retiredOn, now(), now())
                on conflict (id) do update
                set employee_code = excluded.employee_code,
                    name = excluded.name,
                    kana = excluded.kana,
                    department_name = excluded.department_name,
                    employment_type = excluded.employment_type,
                    status = excluded.status,
                    joined_on = excluded.joined_on,
                    retired_on = excluded.retired_on,
                    updated_at = now()
                """, connection);

            command.Parameters.AddWithValue("id", employee.Id);
            command.Parameters.AddWithValue("employeeCode", employee.EmployeeCode);
            command.Parameters.AddWithValue("name", employee.Name);
            command.Parameters.AddWithValue("kana", (object?)employee.Kana ?? DBNull.Value);
            command.Parameters.AddWithValue("departmentName", (object?)employee.DepartmentName ?? DBNull.Value);
            command.Parameters.AddWithValue("employmentType", employee.EmploymentType);
            command.Parameters.AddWithValue("status", employee.Status);
            command.Parameters.AddWithValue("joinedOn", employee.JoinedOn);
            command.Parameters.AddWithValue("retiredOn", (object?)employee.RetiredOn ?? DBNull.Value);
            await command.ExecuteNonQueryAsync(cancellationToken);
        }
    }

    private async Task SyncDevicesAsync(NpgsqlConnection connection, IReadOnlyList<AttendanceDeviceModel> devices, CancellationToken cancellationToken)
    {
        foreach (var device in devices)
        {
            await using var command = new NpgsqlCommand("""
                insert into attendance_devices (id, device_code, name, location_name, app_version, last_seen_at, is_active, created_at, updated_at)
                values (@id, @deviceCode, @name, @locationName, @appVersion, @lastSeenAt, @isActive, now(), now())
                on conflict (id) do update
                set device_code = excluded.device_code,
                    name = excluded.name,
                    location_name = excluded.location_name,
                    app_version = excluded.app_version,
                    last_seen_at = excluded.last_seen_at,
                    is_active = excluded.is_active,
                    updated_at = now()
                """, connection);

            command.Parameters.AddWithValue("id", device.Id);
            command.Parameters.AddWithValue("deviceCode", device.DeviceCode);
            command.Parameters.AddWithValue("name", device.Name);
            command.Parameters.AddWithValue("locationName", (object?)device.LocationName ?? DBNull.Value);
            command.Parameters.AddWithValue("appVersion", (object?)device.AppVersion ?? DBNull.Value);
            command.Parameters.AddWithValue("lastSeenAt", (object?)device.LastSeenAt ?? DBNull.Value);
            command.Parameters.AddWithValue("isActive", device.IsActive);
            await command.ExecuteNonQueryAsync(cancellationToken);
        }
    }

    private static string FindSchemaPath()
    {
        var directory = new DirectoryInfo(AppContext.BaseDirectory);
        while (directory is not null)
        {
            var candidate = Path.Combine(directory.FullName, "sql", "schema.sql");
            if (File.Exists(candidate))
            {
                return candidate;
            }

            directory = directory.Parent;
        }

        throw new FileNotFoundException("PostgreSQL schema file was not found.");
    }

    private static async Task<long> ExecuteCountAsync(NpgsqlConnection connection, string fromClause, string whereClause, IReadOnlyList<NpgsqlParameter> parameters, CancellationToken cancellationToken)
    {
        await using var command = new NpgsqlCommand($"{fromClause} {whereClause}", connection);
        foreach (var parameter in parameters)
        {
            command.Parameters.Add(new NpgsqlParameter(parameter.ParameterName, parameter.Value));
        }

        var result = await command.ExecuteScalarAsync(cancellationToken);
        return Convert.ToInt64(result);
    }

    private static async Task<long> InsertAttendanceEventAsync(NpgsqlConnection connection, NpgsqlTransaction transaction, long? employeeId, long deviceId, string cardUid, DateTimeOffset occurredAt, string? eventType, string receiveStatus, string? rejectionReason, string dedupeKey, CancellationToken cancellationToken)
    {
        await using var command = new NpgsqlCommand("""
            insert into attendance_events (
                employee_id, device_id, card_uid, occurred_at, event_type, source_type,
                receive_status, rejection_reason, offline_saved, dedupe_key, created_at
            )
            values (
                @employeeId, @deviceId, @cardUid, @occurredAt, @eventType, 'CARD_READER',
                @receiveStatus, @rejectionReason, false, @dedupeKey, now()
            )
            returning id
            """, connection, transaction);

        command.Parameters.AddWithValue("employeeId", (object?)employeeId ?? DBNull.Value);
        command.Parameters.AddWithValue("deviceId", deviceId);
        command.Parameters.AddWithValue("cardUid", cardUid);
        command.Parameters.AddWithValue("occurredAt", occurredAt);
        command.Parameters.AddWithValue("eventType", (object?)eventType ?? DBNull.Value);
        command.Parameters.AddWithValue("receiveStatus", receiveStatus);
        command.Parameters.AddWithValue("rejectionReason", (object?)rejectionReason ?? DBNull.Value);
        command.Parameters.AddWithValue("dedupeKey", dedupeKey);

        var result = await command.ExecuteScalarAsync(cancellationToken);
        return Convert.ToInt64(result);
    }

    private static async Task InsertAuditAsync(NpgsqlConnection connection, NpgsqlTransaction transaction, string actorType, long? actorId, string action, string targetType, string? targetId, string? detailJson, CancellationToken cancellationToken)
    {
        await using var command = new NpgsqlCommand("""
            insert into audit_logs (actor_type, actor_id, action, target_type, target_id, detail_json, occurred_at)
            values (@actorType, @actorId, @action, @targetType, @targetId, cast(@detailJson as jsonb), now())
            """, connection, transaction);

        command.Parameters.AddWithValue("actorType", actorType);
        command.Parameters.AddWithValue("actorId", (object?)actorId ?? DBNull.Value);
        command.Parameters.AddWithValue("action", action);
        command.Parameters.AddWithValue("targetType", targetType);
        command.Parameters.AddWithValue("targetId", (object?)targetId ?? DBNull.Value);
        command.Parameters.AddWithValue("detailJson", detailJson ?? "{}");
        await command.ExecuteNonQueryAsync(cancellationToken);
    }

    private static async Task<DeviceRow?> FindActiveDeviceAsync(NpgsqlConnection connection, NpgsqlTransaction transaction, string deviceCode, CancellationToken cancellationToken)
    {
        await using var command = new NpgsqlCommand("""
            select id, device_code, name
            from attendance_devices
            where device_code = @deviceCode
              and is_active = true
            limit 1
            """, connection, transaction);

        command.Parameters.AddWithValue("deviceCode", deviceCode);
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        if (!await reader.ReadAsync(cancellationToken))
        {
            return null;
        }

        return new DeviceRow(reader.GetInt64(0), reader.GetString(1), reader.GetString(2));
    }

    private static async Task<EmployeeRow?> FindEmployeeAsync(NpgsqlConnection connection, NpgsqlTransaction transaction, long employeeId, CancellationToken cancellationToken)
    {
        await using var command = new NpgsqlCommand("""
            select id, employee_code, name
            from employees
            where id = @id
            limit 1
            """, connection, transaction);

        command.Parameters.AddWithValue("id", employeeId);
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        if (!await reader.ReadAsync(cancellationToken))
        {
            return null;
        }

        return new EmployeeRow(reader.GetInt64(0), reader.GetString(1), reader.GetString(2));
    }

    private static async Task<CardOwnerRow?> FindActiveCardOwnerAsync(NpgsqlConnection connection, NpgsqlTransaction transaction, string cardUid, CancellationToken cancellationToken)
    {
        await using var command = new NpgsqlCommand("""
            select e.id, e.employee_code, e.name
            from employee_cards c
            inner join employees e on e.id = c.employee_id
            where upper(c.card_uid) = @cardUid
              and c.is_active = true
            limit 1
            """, connection, transaction);

        command.Parameters.AddWithValue("cardUid", cardUid);
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        if (!await reader.ReadAsync(cancellationToken))
        {
            return null;
        }

        return new CardOwnerRow(reader.GetInt64(0), reader.GetString(1), reader.GetString(2));
    }

    private static async Task<AttendanceWindow> GetAttendanceWindowAsync(NpgsqlConnection connection, NpgsqlTransaction transaction, long employeeId, DateTimeOffset occurredAt, CancellationToken cancellationToken)
    {
        var dayStart = new DateTimeOffset(occurredAt.Year, occurredAt.Month, occurredAt.Day, 0, 0, 0, occurredAt.Offset);
        var dayEnd = dayStart.AddDays(1);

        await using var command = new NpgsqlCommand("""
            select event_type, occurred_at
            from attendance_events
            where employee_id = @employeeId
              and receive_status = 'ACCEPTED'
              and occurred_at >= @dayStart
              and occurred_at < @dayEnd
            order by occurred_at
            """, connection, transaction);

        command.Parameters.AddWithValue("employeeId", employeeId);
        command.Parameters.AddWithValue("dayStart", dayStart);
        command.Parameters.AddWithValue("dayEnd", dayEnd);

        string? lastType = null;
        DateTimeOffset? lastOccurredAt = null;
        await using var reader = await command.ExecuteReaderAsync(cancellationToken);
        while (await reader.ReadAsync(cancellationToken))
        {
            lastType = reader.IsDBNull(0) ? null : reader.GetString(0);
            lastOccurredAt = reader.GetFieldValue<DateTimeOffset>(1);
        }

        return new AttendanceWindow(lastType, lastOccurredAt);
    }

    private static async Task RebuildAttendanceDailyAsync(NpgsqlConnection connection, NpgsqlTransaction transaction, CardOwnerRow owner, DateTimeOffset occurredAt, CancellationToken cancellationToken)
    {
        var dayStart = new DateTimeOffset(occurredAt.Year, occurredAt.Month, occurredAt.Day, 0, 0, 0, occurredAt.Offset);
        var dayEnd = dayStart.AddDays(1);
        var targetDate = DateOnly.FromDateTime(occurredAt.LocalDateTime);

        DateTimeOffset? clockInAt = null;
        DateTimeOffset? clockOutAt = null;

        await using (var command = new NpgsqlCommand("""
            with accepted as (
                select occurred_at, event_type
                from attendance_events
                where employee_id = @employeeId
                  and receive_status = 'ACCEPTED'
                  and occurred_at >= @dayStart
                  and occurred_at < @dayEnd
            ),
            first_in as (
                select min(occurred_at) as clock_in_at
                from accepted
                where event_type = 'CLOCK_IN'
            )
            select
                fi.clock_in_at,
                (
                    select max(a.occurred_at)
                    from accepted a
                    where a.event_type = 'CLOCK_OUT'
                      and (fi.clock_in_at is null or a.occurred_at >= fi.clock_in_at)
                ) as clock_out_at
            from first_in fi
            """, connection, transaction))
        {
            command.Parameters.AddWithValue("employeeId", owner.EmployeeId);
            command.Parameters.AddWithValue("dayStart", dayStart);
            command.Parameters.AddWithValue("dayEnd", dayEnd);

            await using var reader = await command.ExecuteReaderAsync(cancellationToken);
            if (await reader.ReadAsync(cancellationToken))
            {
                clockInAt = reader.IsDBNull(0) ? null : reader.GetFieldValue<DateTimeOffset>(0);
                clockOutAt = reader.IsDBNull(1) ? null : reader.GetFieldValue<DateTimeOffset>(1);
            }
        }

        var workMinutes = clockInAt.HasValue && clockOutAt.HasValue ? (int?)Math.Max(0, (clockOutAt.Value - clockInAt.Value).TotalMinutes) : null;

        await using var upsertCommand = new NpgsqlCommand("""
            insert into attendance_daily (employee_id, target_date, clock_in_at, clock_out_at, work_minutes, updated_at)
            values (@employeeId, @targetDate, @clockInAt, @clockOutAt, @workMinutes, now())
            on conflict (employee_id, target_date) do update
            set clock_in_at = excluded.clock_in_at,
                clock_out_at = excluded.clock_out_at,
                work_minutes = excluded.work_minutes,
                updated_at = now()
            """, connection, transaction);

        upsertCommand.Parameters.AddWithValue("employeeId", owner.EmployeeId);
        upsertCommand.Parameters.AddWithValue("targetDate", targetDate);
        upsertCommand.Parameters.AddWithValue("clockInAt", (object?)clockInAt ?? DBNull.Value);
        upsertCommand.Parameters.AddWithValue("clockOutAt", (object?)clockOutAt ?? DBNull.Value);
        upsertCommand.Parameters.AddWithValue("workMinutes", (object?)workMinutes ?? DBNull.Value);
        await upsertCommand.ExecuteNonQueryAsync(cancellationToken);
    }

    private sealed record DeviceRow(long Id, string DeviceCode, string Name);
    private sealed record EmployeeRow(long Id, string EmployeeCode, string Name);
    private sealed record CardOwnerRow(long EmployeeId, string EmployeeCode, string EmployeeName);
    private sealed record AttendanceWindow(string? LastAcceptedEventType, DateTimeOffset? LastAcceptedOccurredAt);
}
