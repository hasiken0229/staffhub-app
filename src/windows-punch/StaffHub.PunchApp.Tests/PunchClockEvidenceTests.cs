using System.IO;
using System.Net;
using System.Net.Http.Json;
using StaffHub.PunchApp.Models;
using StaffHub.PunchApp.Services;
using Xunit;

namespace StaffHub.PunchApp.Tests;

public sealed class PunchClockEvidenceTests
{
    [Fact]
    public async Task ClockJumpDoesNotChangeCaptureReferenceOnRecoveryOrRestart()
    {
        var initial = DateTimeOffset.Parse("2026-11-25T08:30:00+09:00");
        var clock = new TestClock(initial);
        var transport = new LocalHandler(initial);
        var settings = new AppSettings("http://localhost/", "SYNTHETIC", "test", "時計検証", false, false, "MOCK", null, 300);
        var client = new AttendanceApiClient(settings, new HttpClient(transport), clock);
        Assert.Null(client.CreatePendingPunch("TEST-CARD").ClockReferenceAt);

        await client.SendHeartbeatAsync(0, "合成リーダー");
        clock.Advance(TimeSpan.FromMinutes(5));
        clock.WallTime = clock.WallTime.AddDays(1);
        var pending = client.CreatePendingPunch("TEST-CARD");
        Assert.Equal(initial.AddMinutes(5), pending.ClockReferenceAt);
        Assert.Equal(initial.AddDays(1).AddMinutes(5), pending.OccurredAt);

        var directory = Directory.CreateTempSubdirectory("staffhub-clock-test-");
        var path = Path.Combine(directory.FullName, "queue.json");
        try
        {
            new OfflinePunchQueue(path).Enqueue(pending);
            var restored = Assert.Single(new OfflinePunchQueue(path).Items);
            clock.WallTime = initial.AddDays(2);
            var restarted = new AttendanceApiClient(settings, new HttpClient(transport), clock);
            await restarted.SendPunchAsync(restored);

            var request = Assert.IsType<PunchRequest>(transport.LastRequest);
            Assert.Equal(pending.DedupeKey, request.DedupeKey);
            Assert.Equal(pending.OccurredAt, request.OccurredAt);
            Assert.Equal(initial.AddMinutes(5), request.ClockReferenceAt);
            Assert.Equal(clock.WallTime, request.ClientSentAt);
            Assert.False(request.CaptureClockUnknown);
            Assert.Null(request.CaptureAgeSeconds);

            File.WriteAllText(path, """[{"cardUid":"OLD","occurredAt":"2026-11-20T09:00:00+09:00","dedupeKey":"legacy"}]""");
            Assert.Null(Assert.Single(new OfflinePunchQueue(path).Items).ClockReferenceAt);
        }
        finally
        {
            File.Delete(path);
            File.Delete(path + ".write.lock");
            directory.Delete();
        }
    }

    [Fact]
    public async Task ColdOfflineCaptureRetainsItsAgeUntilDeliveryAndReportsUnknownAfterRestart()
    {
        var initial = DateTimeOffset.Parse("2026-11-25T08:30:00+09:00");
        var clock = new TestClock(initial);
        var transport = new LocalHandler(initial);
        var settings = new AppSettings("http://localhost/", "SYNTHETIC", "test", "時計検証", false, false, "MOCK", null, 300);
        var client = new AttendanceApiClient(settings, new HttpClient(transport), clock);
        var pending = client.CreatePendingPunch("TEST-CARD");
        Assert.True(pending.AwaitingClockReference);
        transport.Offline = true;
        await Assert.ThrowsAsync<HttpRequestException>(() => client.SendPunchAsync(pending));
        clock.Advance(TimeSpan.FromDays(1));
        clock.WallTime = clock.WallTime.AddHours(-3);

        transport.Offline = false;
        await client.SendPunchAsync(pending);
        Assert.Equal(86400L, transport.LastRequest!.CaptureAgeSeconds);
        Assert.False(transport.LastRequest.CaptureClockUnknown);
        var restarted = new AttendanceApiClient(settings, new HttpClient(transport), clock);
        await restarted.SendPunchAsync(pending);
        Assert.True(transport.LastRequest!.CaptureClockUnknown);
        Assert.Null(transport.LastRequest.CaptureAgeSeconds);

        var oldQueueItem = new PendingPunch("OLD", initial, "old-key");
        await restarted.SendPunchAsync(oldQueueItem);
        Assert.False(transport.LastRequest!.CaptureClockUnknown);
        Assert.Null(transport.LastRequest.CaptureAgeSeconds);
    }

    private sealed class TestClock(DateTimeOffset start) : TimeProvider
    {
        private long _timestamp;
        public DateTimeOffset WallTime { get; set; } = start;
        public override long TimestampFrequency => TimeSpan.TicksPerSecond;
        public override long GetTimestamp() => _timestamp;
        public override DateTimeOffset GetUtcNow() => WallTime.ToUniversalTime();

        public void Advance(TimeSpan elapsed)
        {
            _timestamp += elapsed.Ticks;
            WallTime += elapsed;
        }
    }

    private sealed class LocalHandler(DateTimeOffset serverTime) : HttpMessageHandler
    {
        public PunchRequest? LastRequest { get; private set; }
        public bool Offline { get; set; }

        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            if (Offline) throw new HttpRequestException("検証用の通信断");
            if (request.RequestUri!.AbsolutePath.EndsWith("/heartbeat", StringComparison.Ordinal))
                return new HttpResponseMessage(HttpStatusCode.OK)
                {
                    Content = JsonContent.Create(new { data = new HeartbeatResult(true, serverTime, true, 0) }),
                };

            LastRequest = await request.Content!.ReadFromJsonAsync<PunchRequest>(cancellationToken: cancellationToken);
            return new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = JsonContent.Create(new { data = new PunchResult(1, null, null, "WARNING", "時計の確認", LastRequest!.OccurredAt, false) }),
            };
        }
    }
}
