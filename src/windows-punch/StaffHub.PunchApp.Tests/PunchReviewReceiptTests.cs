using System.Net;
using System.Text.Json;
using StaffHub.PunchApp.Models;
using StaffHub.PunchApp.Services;
using Xunit;

namespace StaffHub.PunchApp.Tests;

public sealed class PunchReviewReceiptTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), "staffhub-receipt-tests-" + Guid.NewGuid().ToString("N"));
    private string QueuePath => Path.Combine(_root, "pending.json");
    private string ReceiptPath => Path.Combine(_root, "receipts.json");
    private static readonly DateTimeOffset Now = DateTimeOffset.Parse("2026-10-28T15:00:00+09:00");
    private static PendingPunch Pending(string key) => new("PRIVATE-CARD-DO-NOT-PERSIST", Now.AddHours(-8), key);
    private static PunchResult Success(PendingPunch p) => new(123, new EmployeeBrief(1, "S1", "PRIVATE-NAME-DO-NOT-PERSIST"), "CLOCK_IN", "SUCCESS", "server text", p.OccurredAt, false);
    private PunchReviewReceiptStore Store() => new(ReceiptPath, () => Now);
    private sealed class Sender(Func<PendingPunch, Task<PunchResult>> send) : IAttendancePunchSender
    { public Task<PunchResult> SendPunchAsync(PendingPunch p, CancellationToken token = default) => send(p); }

    [Theory]
    [InlineData("CARD_NOT_REGISTERED")]
    [InlineData("TOO_MANY_PUNCHES")]
    [InlineData("SHORT_INTERVAL_PUNCH")]
    public async Task AutomaticRejectionIsDurableBeforeRemovalAndCanBeReadAfterRestart(string code)
    {
        var queue = new OfflinePunchQueue(QueuePath);var p = Pending("synthetic-key");queue.Enqueue(p);
        var store = Store();
        var processor = new PendingPunchDeliveryProcessor(queue, new Sender(_ => Task.FromException<PunchResult>(
            new AttendanceApiException(HttpStatusCode.BadRequest, code, "PRIVATE-SERVER-TEXT"))), store);

        var summary = await processor.RetryAsync(20);

        Assert.Equal(1, summary.RejectedCount);Assert.Empty(queue.Items);
        var receipt = Assert.Single(Store().ReadAll());
        Assert.Equal(p.OccurredAt, receipt.OccurredAt);Assert.Equal(p.DedupeKey, receipt.DedupeKey);Assert.Equal(code, receipt.Code);
        Assert.Contains("受付拒否", receipt.StatusText);
        var text = File.ReadAllText(ReceiptPath);
        Assert.DoesNotContain(p.CardUid, text);Assert.DoesNotContain("PRIVATE-", text);
        using var json = JsonDocument.Parse(text);
        Assert.Equal(new[] { "attendanceEventId", "code", "dedupeKey", "occurredAt", "receivedAt" },
            json.RootElement[0].EnumerateObject().Select(x => x.Name).Order().ToArray());
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public async Task AllSuccessOrMixedRetryMaintainsCountsAndOnlyPersistsConfirmationResults(bool mixed)
    {
        var queue = new OfflinePunchQueue(QueuePath);
        foreach (var key in new[] { "success", "rejected", "warning" }) queue.Enqueue(Pending(key));
        var processor = new PendingPunchDeliveryProcessor(queue, new Sender(p =>
        {
            if (mixed && p.DedupeKey == "rejected") return Task.FromException<PunchResult>(new AttendanceApiException(HttpStatusCode.BadRequest, "SHORT_INTERVAL_PUNCH", "reason"));
            return Task.FromResult(mixed && p.DedupeKey == "warning" ? Success(p) with { ResultType = "WARNING", EventType = null } : Success(p));
        }), Store());
        var summary = await processor.RetryAsync(20);
        Assert.Equal(mixed ? 2 : 3, summary.DeliveredCount);Assert.Equal(mixed ? 1 : 0, summary.RejectedCount);
        Assert.Equal(0, summary.RemainingCount);Assert.Equal(mixed ? 2 : 0, Store().ReadAll().Count);
        Assert.Empty(new OfflinePunchQueue(QueuePath).Items);
        if (mixed)
        {
            var receipt = Store().ReadAll().Single(r => r.Code == "SERVER_REVIEW_REQUIRED");
            Assert.Equal(123, receipt.AttendanceEventId);
            Assert.False(receipt.WasShortShiftTap);
            Assert.Equal("原本保存済・要確認", receipt.StatusText);
            Assert.DoesNotContain("wasShortShiftTap", File.ReadAllText(ReceiptPath));
        }
    }

    [Theory]
    [InlineData("{")]
    [InlineData("null")]
    [InlineData("[{}]")]
    public async Task CorruptReceiptLedgerLeavesBothPendingOriginalsAndLedgerUntouched(string corrupt)
    {
        var queue = new OfflinePunchQueue(QueuePath);queue.Enqueue(Pending("first"));queue.Enqueue(Pending("second"));
        File.WriteAllText(ReceiptPath, corrupt);var original = File.ReadAllBytes(QueuePath);var attempts = 0;
        var processor = new PendingPunchDeliveryProcessor(queue, new Sender(_ => { attempts++;return Task.FromException<PunchResult>(
            new AttendanceApiException(HttpStatusCode.BadRequest, "CARD_NOT_REGISTERED", "reason")); }), Store());
        var summary = await processor.RetryAsync(20);
        Assert.Equal(PendingPunchDeliveryOutcome.ReceiptSaveFailed, summary.LastResult!.Outcome);
        Assert.Equal(1, attempts);Assert.Equal(2, summary.RemainingCount);
        Assert.Equal(original, File.ReadAllBytes(QueuePath));Assert.Equal(corrupt, File.ReadAllText(ReceiptPath));
    }

    [Fact]
    public async Task LedgerSaveFailurePreservesQueueAndRecoveryAddsOnlyOneReceipt()
    {
        var queue = new OfflinePunchQueue(QueuePath);var p=Pending("original");queue.Enqueue(p);
        File.WriteAllText(ReceiptPath, "[]");var original=File.ReadAllBytes(QueuePath);
        var processor=new PendingPunchDeliveryProcessor(queue,new Sender(_=>Task.FromException<PunchResult>(
            new AttendanceApiException(HttpStatusCode.BadRequest,"TOO_MANY_PUNCHES","reason"))),Store());
        using(var file=new FileStream(ReceiptPath,FileMode.Open,FileAccess.Read,FileShare.Read))
        {
            var result=await processor.DeliverAsync(p);
            Assert.Equal(PendingPunchDeliveryOutcome.ReceiptSaveFailed,result.Outcome);
            Assert.Equal(original,File.ReadAllBytes(QueuePath));Assert.Equal("[]",File.ReadAllText(ReceiptPath));
        }
        Assert.Equal(PendingPunchDeliveryOutcome.DeterministicallyRejected,(await processor.DeliverAsync(p)).Outcome);
        Assert.Single(Store().ReadAll());Assert.Empty(queue.Items);
    }

    [Fact]
    public async Task ReceiptSurvivesQueueRemovalFailureAndIdempotentRetryDoesNotDuplicateIt()
    {
        var queue=new OfflinePunchQueue(QueuePath);var p=Pending("remove-failure");queue.Enqueue(p);
        var processor=new PendingPunchDeliveryProcessor(queue,new Sender(_=>Task.FromException<PunchResult>(
            new AttendanceApiException(HttpStatusCode.BadRequest,"CARD_NOT_REGISTERED","reason"))),Store());
        using(var file=new FileStream(QueuePath,FileMode.Open,FileAccess.Read,FileShare.Read))
        {
            Assert.Equal(PendingPunchDeliveryOutcome.QueueRemovalFailed,(await processor.DeliverAsync(p)).Outcome);
            Assert.Single(Store().ReadAll());Assert.Equal(p,Assert.Single(queue.Items));
        }
        await processor.DeliverAsync(p);Assert.Single(Store().ReadAll());Assert.Empty(queue.Items);
    }

    [Fact]
    public void RetentionUsesReceiptDateAndKeepsAtMostTwoHundredMinimalRecords()
    {
        var store=Store();
        for(var i=0;i<201;i++)store.Record(new(Pending("retention-"+i) with { OccurredAt=Now.AddYears(-1) },
            PendingPunchDeliveryOutcome.DeterministicallyRejected,ApiException:new AttendanceApiException(HttpStatusCode.BadRequest,"CARD_NOT_REGISTERED","reason")));
        Assert.Equal(200,store.ReadAll().Count);Assert.DoesNotContain(store.ReadAll(),r=>r.DedupeKey=="retention-0");
        var later=new PunchReviewReceiptStore(ReceiptPath,()=>Now.AddDays(31));
        later.Record(new(Pending("new"),PendingPunchDeliveryOutcome.DeterministicallyRejected,
            ApiException:new AttendanceApiException(HttpStatusCode.BadRequest,"CARD_NOT_REGISTERED","reason")));
        Assert.Equal("new",Assert.Single(later.ReadAll()).DedupeKey);
    }

    [Fact]
    public async Task NewScanBehindAnUnwritableRejectionLedgerStaysUnsentWithAnExplicitSaveFailure()
    {
        var queue=new OfflinePunchQueue(QueuePath);var first=Pending("first");var next=Pending("next");
        queue.Enqueue(first);queue.Enqueue(next);File.WriteAllText(ReceiptPath,"{broken");var attempts=0;
        var processor=new PendingPunchDeliveryProcessor(queue,new Sender(_=>{attempts++;return Task.FromException<PunchResult>(
            new AttendanceApiException(HttpStatusCode.BadRequest,"CARD_NOT_REGISTERED","reason"));}),Store());
        var result=await processor.DeliverAsync(next);
        Assert.Equal(PendingPunchDeliveryOutcome.ReceiptSaveFailed,result.Outcome);Assert.True(result.IsRetained);
        Assert.Equal(1,attempts);Assert.Equal(new[]{first,next},new OfflinePunchQueue(QueuePath).Items);
    }

    [Fact]
    public void UnexpectedLedgerDeletionDoesNotSilentlyRecreateAnEmptyHistory()
    {
        var store=Store();
        var receipt=new PendingPunchDeliveryResult(Pending("kept"),PendingPunchDeliveryOutcome.DeterministicallyRejected,
            ApiException:new AttendanceApiException(HttpStatusCode.BadRequest,"CARD_NOT_REGISTERED","reason"));
        store.Record(receipt);File.Delete(ReceiptPath);
        Assert.ThrowsAny<IOException>(()=>store.Record(receipt with { Pending=Pending("new") }));
        Assert.False(File.Exists(ReceiptPath));
    }

    public void Dispose(){if(Directory.Exists(_root))Directory.Delete(_root,true);}
}
