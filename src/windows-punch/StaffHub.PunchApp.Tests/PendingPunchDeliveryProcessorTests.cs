using System.Net;
using StaffHub.PunchApp.Models;
using StaffHub.PunchApp.Services;
using StaffHub.PunchApp.ViewModels;
using Xunit;

namespace StaffHub.PunchApp.Tests;

public sealed class PendingPunchDeliveryProcessorTests : IDisposable
{
    private readonly string _directory = Path.Combine(Path.GetTempPath(), $"staffhub-punch-delivery-tests-{Guid.NewGuid():N}");

    [Fact]
    public async Task RetryKeepsQueuedPunchWhenServerReturnsTemporaryFailure()
    {
        var queue = new OfflinePunchQueue(Path.Combine(_directory, "pending-punches.json"));
        var pending = CreatePending("temporary-failure");
        queue.Enqueue(pending);
        var processor = new PendingPunchDeliveryProcessor(
            queue,
            new StubPunchSender((_, _) => Task.FromException<PunchResult>(
                new AttendanceApiException(HttpStatusCode.ServiceUnavailable, "SERVICE_UNAVAILABLE", "一時的に利用できません。"))));

        var summary = await processor.RetryAsync(20);

        Assert.Equal(0, summary.DeliveredCount);
        Assert.Equal(0, summary.RejectedCount);
        Assert.Equal(1, summary.RetainedCount);
        Assert.Equal(pending, Assert.Single(queue.Items));
        Assert.Equal(PendingPunchDeliveryOutcome.Deferred, summary.LastResult?.Outcome);
    }

    [Fact]
    public async Task RetryKeepsQueuedPunchWhenDeviceAuthenticationFails()
    {
        var queue = new OfflinePunchQueue(Path.Combine(_directory, "pending-punches.json"));
        var pending = CreatePending("forbidden");
        queue.Enqueue(pending);
        var processor = new PendingPunchDeliveryProcessor(
            queue,
            new StubPunchSender((_, _) => Task.FromException<PunchResult>(
                new AttendanceApiException(HttpStatusCode.Forbidden, "FORBIDDEN", "端末認証に失敗しました。"))));

        var summary = await processor.RetryAsync(20);

        Assert.Equal(1, summary.RetainedCount);
        Assert.Equal(pending, Assert.Single(queue.Items));
        Assert.Equal(PendingPunchDeliveryOutcome.Deferred, summary.LastResult?.Outcome);
    }

    [Fact]
    public async Task RetryRemovesKnownPersistedRejectionAndContinuesWithNextPunch()
    {
        var queue = new OfflinePunchQueue(Path.Combine(_directory, "pending-punches.json"));
        var rejected = CreatePending("rejected");
        var delivered = CreatePending("delivered");
        queue.Enqueue(rejected);
        queue.Enqueue(delivered);
        var attempts = 0;
        var processor = new PendingPunchDeliveryProcessor(
            queue,
            new StubPunchSender((pending, _) =>
            {
                attempts++;
                return pending.DedupeKey == rejected.DedupeKey
                    ? Task.FromException<PunchResult>(new AttendanceApiException(
                        HttpStatusCode.BadRequest,
                        "CARD_NOT_REGISTERED",
                        "このカードは登録されていません。"))
                    : Task.FromResult(CreateSuccess(pending));
            }));

        var summary = await processor.RetryAsync(20);

        Assert.Equal(2, attempts);
        Assert.Equal(1, summary.RejectedCount);
        Assert.Equal(1, summary.DeliveredCount);
        Assert.Equal(0, summary.RemainingCount);
        Assert.Empty(queue.Items);
    }

    [Fact]
    public async Task NewScanDrainsEarlierSavedPunchesInArrivalOrderEvenWhenClockMovesBack()
    {
        var path = Path.Combine(_directory, "pending-punches.json");
        var queue = new OfflinePunchQueue(path);
        var earlier = CreatePending("earlier") with { OccurredAt = CreatePending("time").OccurredAt.AddHours(9) };
        var latest = CreatePending("latest");
        queue.Enqueue(earlier);
        queue.Enqueue(latest);
        var attempts = new List<PendingPunch>();
        var processor = new PendingPunchDeliveryProcessor(queue, new StubPunchSender((pending, _) =>
        {
            attempts.Add(pending);
            return Task.FromResult(CreateSuccess(pending));
        }));

        // Even a caller with the same key cannot change the persisted UID/time used for delivery.
        var result = await processor.DeliverAsync(latest with { CardUid = "CHANGED", OccurredAt = latest.OccurredAt.AddDays(1) });

        Assert.Equal(new[] { earlier, latest }, attempts);
        Assert.Equal(latest, result.Pending);
        Assert.Equal(PendingPunchDeliveryOutcome.Delivered, result.Outcome);
        Assert.Empty(new OfflinePunchQueue(path).Items);
    }

    [Theory]
    [InlineData(401, "UNAUTHORIZED")]
    [InlineData(403, "FORBIDDEN")]
    [InlineData(500, "SERVER_ERROR")]
    [InlineData(503, "SERVICE_UNAVAILABLE")]
    [InlineData(409, "TIME_ORDER_CONFLICT")]
    [InlineData(422, "MONTH_CLOSED")]
    public async Task FailedEarlierPunchRetainsNewScanWithoutSendingItAndSurvivesRestart(int status, string code)
    {
        var path = Path.Combine(_directory, "pending-punches.json");
        var queue = new OfflinePunchQueue(path);
        var earlier = CreatePending("earlier");
        var latest = CreatePending("latest");
        queue.Enqueue(earlier);
        queue.Enqueue(latest);
        var originalBytes = File.ReadAllBytes(path);
        var attempts = new List<PendingPunch>();
        var processor = new PendingPunchDeliveryProcessor(queue, new StubPunchSender((pending, _) =>
        {
            attempts.Add(pending);
            return Task.FromException<PunchResult>(new AttendanceApiException((HttpStatusCode)status, code, "合成エラー"));
        }));

        var result = await processor.DeliverAsync(latest);

        Assert.Equal(earlier, Assert.Single(attempts));
        Assert.Equal(latest, result.Pending);
        Assert.Equal(PendingPunchDeliveryOutcome.WaitingForEarlierPunch, result.Outcome);
        Assert.True(result.IsRetained);
        Assert.Null(result.PunchResult);
        Assert.Equal(originalBytes, File.ReadAllBytes(path));
        Assert.Equal(new[] { earlier, latest }, queue.Items);

        var restored = new OfflinePunchQueue(path);
        attempts.Clear();
        var recovery = new PendingPunchDeliveryProcessor(restored, new StubPunchSender((pending, _) =>
        {
            attempts.Add(pending);
            return Task.FromResult(CreateSuccess(pending));
        }));
        var summary = await recovery.RetryAsync(20);

        Assert.Equal(new[] { earlier, latest }, attempts);
        Assert.Equal(2, summary.DeliveredCount);
        Assert.Empty(new OfflinePunchQueue(path).Items);
    }

    [Fact]
    public async Task EarlierTimeoutDoesNotSendLaterScan()
    {
        var queue = new OfflinePunchQueue(Path.Combine(_directory, "pending-punches.json"));
        var earlier = CreatePending("earlier");
        var latest = CreatePending("latest");
        queue.Enqueue(earlier);
        queue.Enqueue(latest);
        var attempts = new List<PendingPunch>();
        var processor = new PendingPunchDeliveryProcessor(queue, new StubPunchSender((pending, _) =>
        {
            attempts.Add(pending);
            return Task.FromException<PunchResult>(new TaskCanceledException("合成HttpClientタイムアウト"));
        }));

        var result = await processor.DeliverAsync(latest);

        Assert.Equal(PendingPunchDeliveryOutcome.WaitingForEarlierPunch, result.Outcome);
        Assert.Equal(earlier, Assert.Single(attempts));
        Assert.Equal(new[] { earlier, latest }, queue.Items);
    }

    [Fact]
    public async Task EarlierRemovalFailureRetainsBothPunchesAndStopsBeforeNewScan()
    {
        var path = Path.Combine(_directory, "pending-punches.json");
        var queue = new OfflinePunchQueue(path);
        var earlier = CreatePending("earlier");
        var latest = CreatePending("latest");
        queue.Enqueue(earlier);
        queue.Enqueue(latest);
        var originalBytes = File.ReadAllBytes(path);
        var attempts = new List<PendingPunch>();
        var processor = new PendingPunchDeliveryProcessor(queue, new StubPunchSender((pending, _) =>
        {
            attempts.Add(pending);
            return Task.FromResult(CreateSuccess(pending));
        }));

        using (var fileLock = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.Read))
        {
            var result = await processor.DeliverAsync(latest);
            Assert.Equal(PendingPunchDeliveryOutcome.WaitingForEarlierPunch, result.Outcome);
            Assert.True(result.IsRetained);
            Assert.Null(result.PunchResult);
        }

        Assert.Equal(earlier, Assert.Single(attempts));
        Assert.Equal(new[] { earlier, latest }, queue.Items);
        Assert.Equal(originalBytes, File.ReadAllBytes(path));
    }

    [Fact]
    public async Task PersistedRejectionBeforeNewScanIsRemovedBeforeSendingTheNewScan()
    {
        var queue = new OfflinePunchQueue(Path.Combine(_directory, "pending-punches.json"));
        var earlier = CreatePending("earlier");
        var latest = CreatePending("latest");
        queue.Enqueue(earlier);
        queue.Enqueue(latest);
        var attempts = new List<PendingPunch>();
        var processor = new PendingPunchDeliveryProcessor(queue, new StubPunchSender((pending, _) =>
        {
            attempts.Add(pending);
            if (pending == earlier)
                return Task.FromException<PunchResult>(new AttendanceApiException(HttpStatusCode.BadRequest,
                    "CARD_NOT_REGISTERED", "合成登録なし"));
            Assert.Equal(latest, Assert.Single(queue.Items));
            return Task.FromResult(CreateSuccess(pending));
        }));

        var result = await processor.DeliverAsync(latest);

        Assert.Equal(new[] { earlier, latest }, attempts);
        Assert.Equal(PendingPunchDeliveryOutcome.Delivered, result.Outcome);
        Assert.Equal(latest, result.Pending);
        Assert.Empty(queue.Items);
    }

    [Fact]
    public async Task AutomaticRetryAndNewScansShareOneGateWithoutDuplicateOrOutOfOrderSends()
    {
        var queue = new OfflinePunchQueue(Path.Combine(_directory, "pending-punches.json"));
        var earlier = CreatePending("earlier");
        var latest = CreatePending("latest");
        queue.Enqueue(earlier);
        var firstStarted = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var releaseFirst = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var attempts = new List<PendingPunch>();
        var processor = new PendingPunchDeliveryProcessor(queue, new StubPunchSender(async (pending, _) =>
        {
            attempts.Add(pending);
            if (pending == earlier)
            {
                firstStarted.SetResult();
                await releaseFirst.Task;
            }
            return CreateSuccess(pending);
        }));

        var retry = processor.RetryAsync(20);
        await firstStarted.Task.WaitAsync(TimeSpan.FromSeconds(5));
        queue.Enqueue(latest);
        var newDelivery = processor.DeliverAsync(latest);
        Assert.False(newDelivery.IsCompleted);
        releaseFirst.SetResult();
        await retry.WaitAsync(TimeSpan.FromSeconds(5));
        var result = await newDelivery.WaitAsync(TimeSpan.FromSeconds(5));
        var retryAgain = await processor.RetryAsync(20);

        Assert.Equal(new[] { earlier, latest }, attempts);
        Assert.Equal(PendingPunchDeliveryOutcome.Delivered, result.Outcome);
        Assert.Equal(0, retryAgain.DeliveredCount);
        Assert.Empty(queue.Items);
    }

    [Fact]
    public async Task CancellationWhileWaitingForOlderPunchLeavesNewScanPersisted()
    {
        var path = Path.Combine(_directory, "pending-punches.json");
        var queue = new OfflinePunchQueue(path);
        var earlier = CreatePending("earlier");
        var latest = CreatePending("latest");
        queue.Enqueue(earlier);
        queue.Enqueue(latest);
        using var cancellation = new CancellationTokenSource();
        var attempts = new List<PendingPunch>();
        var processor = new PendingPunchDeliveryProcessor(queue, new StubPunchSender((pending, _) =>
        {
            attempts.Add(pending);
            cancellation.Cancel();
            return Task.FromCanceled<PunchResult>(cancellation.Token);
        }));

        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => processor.DeliverAsync(latest, cancellation.Token));

        Assert.Equal(earlier, Assert.Single(attempts));
        Assert.Equal(new[] { earlier, latest }, new OfflinePunchQueue(path).Items);
    }

    [Theory]
    [InlineData("時刻順が前後しているため原本を保存しました。管理者に確認してください。")]
    [InlineData("確定済み日のため原本を保存しました。管理者に確認してください。")]
    public async Task ServerRecordedWarningKeepsItsIdentityAndRequestsReviewInsteadOfNormalSuccess(string message)
    {
        var queue = new OfflinePunchQueue(Path.Combine(_directory, "pending-punches.json"));
        var pending = CreatePending("review-warning");
        queue.Enqueue(pending);
        var warning = CreateSuccess(pending) with { EventType = null, ResultType = "WARNING", ResultMessage = message };
        var processor = new PendingPunchDeliveryProcessor(queue,
            new StubPunchSender((_, _) => Task.FromResult(warning)));

        var result = await processor.DeliverAsync(pending);
        var feedback = PunchResultFeedback.From(result.PunchResult!);

        Assert.Equal(PendingPunchDeliveryOutcome.Delivered, result.Outcome);
        Assert.Equal(warning, result.PunchResult);
        Assert.Null(result.PunchResult!.EventType);
        Assert.Empty(queue.Items);
        Assert.True(feedback.RequiresReview);
        Assert.Contains("管理者に確認", feedback.Detail);
        Assert.Equal("原本保存済・要確認", feedback.HistoryStatus);
        Assert.False(PunchResultFeedback.From(CreateSuccess(pending)).RequiresReview);
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public async Task EarlierReviewWarningsRemainAvailableForDisplayAfterANewerSuccessfulPunch(bool retry)
    {
        var queue = new OfflinePunchQueue(Path.Combine(_directory, "pending-punches.json"));
        var earlier = CreatePending("earlier-warning");
        var latest = CreatePending("latest-success");
        queue.Enqueue(earlier);
        queue.Enqueue(latest);
        var warning = CreateSuccess(earlier) with
        {
            AttendanceEventId = 101, EventType = null, ResultType = "WARNING", ResultMessage = "原本保存済み。管理者確認が必要です。",
        };
        var processor = new PendingPunchDeliveryProcessor(queue, new StubPunchSender((pending, _) =>
            Task.FromResult(pending == earlier ? warning : CreateSuccess(pending))));

        IReadOnlyList<PunchResult>? warnings;
        if (retry)
        {
            var result = await processor.RetryAsync(20);
            Assert.Equal(2, result.DeliveredCount);
            warnings = result.ReviewWarnings;
        }
        else
        {
            var result = await processor.DeliverAsync(latest);
            Assert.Equal(latest, result.Pending);
            Assert.Equal("SUCCESS", result.PunchResult!.ResultType);
            warnings = result.ReviewWarnings;
        }

        Assert.Equal(warning, Assert.Single(warnings!));
        Assert.Empty(queue.Items);
    }

    [Fact]
    public async Task WaitingNewScanReceivesItsWarningWhenAutomaticRetryAlreadyCompletedIt()
    {
        var queue = new OfflinePunchQueue(Path.Combine(_directory, "pending-punches.json"));
        var pending = CreatePending("warning-race");
        queue.Enqueue(pending);
        var warning = CreateSuccess(pending) with { EventType = null, ResultType = "WARNING", ResultMessage = "原本保存済み・管理者確認" };
        var started = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var release = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var attempts = 0;
        var processor = new PendingPunchDeliveryProcessor(queue, new StubPunchSender(async (_, _) =>
        {
            attempts++;
            started.SetResult();
            await release.Task;
            return warning;
        }));

        var retry = processor.RetryAsync(20);
        await started.Task.WaitAsync(TimeSpan.FromSeconds(5));
        var newScan = processor.DeliverAsync(pending);
        release.SetResult();
        var retried = await retry.WaitAsync(TimeSpan.FromSeconds(5));
        var result = await newScan.WaitAsync(TimeSpan.FromSeconds(5));

        Assert.Equal(warning, Assert.Single(retried.ReviewWarnings!));
        Assert.Equal(PendingPunchDeliveryOutcome.Delivered, result.Outcome);
        Assert.Equal(warning, result.PunchResult);
        Assert.True(PunchResultFeedback.From(result.PunchResult!).RequiresReview);
        Assert.Equal(1, attempts);
        Assert.Empty(queue.Items);
    }

    [Fact]
    public async Task UnknownResultAfterRecentResultEvictionNeverResendsOrInventsSuccess()
    {
        var queue = new OfflinePunchQueue(Path.Combine(_directory, "pending-punches.json"));
        var attempts = 0;
        var processor = new PendingPunchDeliveryProcessor(queue, new StubPunchSender((pending, _) =>
        {
            attempts++;
            return Task.FromResult(CreateSuccess(pending));
        }));
        var oldest = CreatePending("evicted-0");
        for (var i = 0; i < 101; i++)
        {
            var pending = i == 0 ? oldest : CreatePending($"evicted-{i}");
            queue.Enqueue(pending);
            await processor.DeliverAsync(pending);
        }

        var unknown = await processor.DeliverAsync(oldest);

        Assert.Equal(PendingPunchDeliveryOutcome.AlreadyRemoved, unknown.Outcome);
        Assert.Null(unknown.PunchResult);
        Assert.Equal(101, attempts);
        Assert.Empty(queue.Items);
    }

    public void Dispose()
    {
        if (Directory.Exists(_directory))
        {
            Directory.Delete(_directory, recursive: true);
        }
    }

    private static PendingPunch CreatePending(string suffix)
        => new(
            "0123456789ABCDEF",
            new DateTimeOffset(2026, 8, 30, 8, 15, 30, TimeSpan.FromHours(9)),
            $"DEVICE-01-{suffix}");

    private static PunchResult CreateSuccess(PendingPunch pending)
        => new(
            123,
            new EmployeeBrief(1, "E001", "テスト職員"),
            "CLOCK_IN",
            "SUCCESS",
            "出勤を記録しました。",
            pending.OccurredAt,
            false);

    private sealed class StubPunchSender(
        Func<PendingPunch, CancellationToken, Task<PunchResult>> send) : IAttendancePunchSender
    {
        public Task<PunchResult> SendPunchAsync(PendingPunch pending, CancellationToken cancellationToken = default)
            => send(pending, cancellationToken);
    }
}
