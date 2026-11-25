using System.Net;
using System.Net.Http.Json;
using System.Text.Json;
using StaffHub.PunchApp.Models;
using StaffHub.PunchApp.Services;
using Xunit;

namespace StaffHub.PunchApp.Tests;

public sealed class AttendanceApiClientTests
{
    [Fact]
    public async Task RetrySendsOriginalOccurredAtAndDedupeKey()
    {
        string? requestJson = null;
        var handler = new RecordingHandler(async request =>
        {
            requestJson = await request.Content!.ReadAsStringAsync();
            return new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = JsonContent.Create(new
                {
                    data = new
                    {
                        attendanceEventId = 123,
                        employee = new { id = 1, employeeCode = "E001", name = "テスト職員" },
                        eventType = "CLOCK_IN",
                        resultType = "SUCCESS",
                        resultMessage = "出勤を記録しました。",
                        occurredAt = "2026-08-27T08:15:30+09:00",
                        offlineAccepted = true,
                    },
                }),
            };
        });
        var settings = new AppSettings(
            "https://example.test/dakoku/",
            "DEVICE-01",
            "device-secret",
            "テスト端末",
            false,
            false,
            "MOCK",
            null,
            300);
        var client = new AttendanceApiClient(settings, new HttpClient(handler));
        var pending = new PendingPunch(
            "0123456789ABCDEF",
            new DateTimeOffset(2026, 8, 27, 8, 15, 30, TimeSpan.FromHours(9)),
            "DEVICE-01-fixed-dedupe-key");

        await client.SendPunchAsync(pending);

        using var json = JsonDocument.Parse(Assert.IsType<string>(requestJson));
        var root = json.RootElement;
        Assert.Equal("0123456789ABCDEF", root.GetProperty("cardUid").GetString());
        Assert.Equal("2026-08-27T08:15:30+09:00", root.GetProperty("occurredAt").GetString());
        Assert.Equal("DEVICE-01-fixed-dedupe-key", root.GetProperty("dedupeKey").GetString());
    }

    [Fact]
    public async Task HeartbeatSendsReaderAndQueueStatusAndReadsServerClockDrift()
    {
        string? requestJson = null;
        var handler = new RecordingHandler(async request =>
        {
            requestJson = await request.Content!.ReadAsStringAsync();
            return new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = JsonContent.Create(new
                {
                    data = new
                    {
                        success = true,
                        serverTime = "2026-08-30T09:00:00+09:00",
                        deviceActive = true,
                        clockDriftSeconds = 42,
                    },
                }),
            };
        });
        var client = new AttendanceApiClient(CreateSettings(), new HttpClient(handler));

        var result = await client.SendHeartbeatAsync(3, "RC-S380 接続済");

        using var json = JsonDocument.Parse(Assert.IsType<string>(requestJson));
        var root = json.RootElement;
        Assert.Equal(3, root.GetProperty("pendingOfflineCount").GetInt32());
        Assert.Equal("RC-S380 接続済", root.GetProperty("readerStatus").GetString());
        Assert.True(root.TryGetProperty("lastSeenAt", out _));
        Assert.True(result.Success);
        Assert.Equal(42, result.ClockDriftSeconds);
        Assert.Equal(new DateTimeOffset(2026, 8, 30, 9, 0, 0, TimeSpan.FromHours(9)), result.ServerTime);
    }

    [Theory]
    [InlineData(HttpStatusCode.ServiceUnavailable, "SERVICE_UNAVAILABLE", false)]
    [InlineData(HttpStatusCode.TooManyRequests, "RATE_LIMITED", false)]
    [InlineData(HttpStatusCode.Forbidden, "FORBIDDEN", false)]
    [InlineData(HttpStatusCode.BadRequest, "MONTH_CLOSED", false)]
    [InlineData(HttpStatusCode.BadRequest, "CARD_NOT_REGISTERED", true)]
    [InlineData(HttpStatusCode.BadRequest, "TOO_MANY_PUNCHES", true)]
    public void OnlyKnownPersistedRejectionsCanBeRemovedFromQueue(
        HttpStatusCode statusCode,
        string code,
        bool expected)
    {
        var exception = new AttendanceApiException(statusCode, code, "test");

        Assert.Equal(expected, exception.IsDeterministicRejection);
    }

    private static AppSettings CreateSettings()
        => new(
            "https://example.test/dakoku/",
            "DEVICE-01",
            "device-secret",
            "テスト端末",
            false,
            false,
            "MOCK",
            null,
            300);

    private sealed class RecordingHandler(Func<HttpRequestMessage, Task<HttpResponseMessage>> responder) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
            => responder(request);
    }
}
