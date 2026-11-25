using StaffHub.PunchApp.Models;

namespace StaffHub.PunchApp.Services;

public enum PendingPunchDeliveryOutcome
{
    Delivered,
    DeterministicallyRejected,
    Deferred,
    QueueRemovalFailed,
    AlreadyRemoved,
    WaitingForEarlierPunch,
    ReceiptSaveFailed,
}

public sealed record PendingPunchDeliveryResult(
    PendingPunch Pending,
    PendingPunchDeliveryOutcome Outcome,
    PunchResult? PunchResult = null,
    AttendanceApiException? ApiException = null,
    Exception? Exception = null,
    IReadOnlyList<PunchResult>? ReviewWarnings = null)
{
    public bool IsRetained => Outcome is PendingPunchDeliveryOutcome.Deferred
        or PendingPunchDeliveryOutcome.QueueRemovalFailed or PendingPunchDeliveryOutcome.WaitingForEarlierPunch
        or PendingPunchDeliveryOutcome.ReceiptSaveFailed;
}

public sealed record PendingPunchRetrySummary(
    int DeliveredCount,
    int RejectedCount,
    int RetainedCount,
    int RemainingCount,
    PendingPunchDeliveryResult? LastResult,
    IReadOnlyList<PunchResult>? ReviewWarnings = null)
{
    public bool HasProgress => DeliveredCount > 0 || RejectedCount > 0;
}

/// <summary>
/// Serializes all deliveries from the persisted queue. A punch remains in the queue until the
/// API accepts it or confirms one of the known, persisted rejection outcomes.
/// </summary>
public sealed class PendingPunchDeliveryProcessor
{
    private readonly OfflinePunchQueue _queue;
    private readonly IAttendancePunchSender _sender;
    private readonly IPunchReviewReceiptStore? _receipts;
    private readonly SemaphoreSlim _deliveryGate = new(1, 1);
    private readonly Dictionary<string, PendingPunchDeliveryResult> _recentResults = new(StringComparer.Ordinal);
    private readonly Queue<string> _recentResultKeys = new();

    public PendingPunchDeliveryProcessor(OfflinePunchQueue queue, IAttendancePunchSender sender,
        IPunchReviewReceiptStore? receipts = null)
    {
        _queue = queue;
        _sender = sender;
        _receipts = receipts;
    }

    public async Task<PendingPunchDeliveryResult> DeliverAsync(
        PendingPunch pending,
        CancellationToken cancellationToken = default)
    {
        await _deliveryGate.WaitAsync(cancellationToken);
        try
        {
            // New scans and retries must use the same persisted arrival order. Sorting by
            // OccurredAt would reorder accepted scans when the terminal clock moves back.
            var queued = _queue.Items;
            var reviewWarnings = new List<PunchResult>();
            if (!queued.Any(item => string.Equals(item.DedupeKey, pending.DedupeKey, StringComparison.Ordinal)))
            {
                return CompletedOrRemoved(pending);
            }

            foreach (var earlier in queued)
            {
                cancellationToken.ThrowIfCancellationRequested();
                var result = await DeliverCoreAsync(earlier, cancellationToken);
                CollectReviewWarning(result, reviewWarnings);
                if (string.Equals(earlier.DedupeKey, pending.DedupeKey, StringComparison.Ordinal))
                {
                    return result with { ReviewWarnings = reviewWarnings.ToArray() };
                }

                if (result.IsRetained)
                {
                    // The requested scan is saved but has not been sent. Do not report the
                    // preceding punch's success/rejection as this employee's result.
                    return new PendingPunchDeliveryResult(pending,
                        result.Outcome == PendingPunchDeliveryOutcome.ReceiptSaveFailed
                            ? PendingPunchDeliveryOutcome.ReceiptSaveFailed
                            : PendingPunchDeliveryOutcome.WaitingForEarlierPunch,
                        ApiException: result.ApiException, Exception: result.Exception,
                        ReviewWarnings: reviewWarnings.ToArray());
                }
            }

            return CompletedOrRemoved(pending);
        }
        finally
        {
            _deliveryGate.Release();
        }
    }

    public async Task<PendingPunchRetrySummary> RetryAsync(
        int maximumItems,
        CancellationToken cancellationToken = default)
    {
        ArgumentOutOfRangeException.ThrowIfNegativeOrZero(maximumItems);

        await _deliveryGate.WaitAsync(cancellationToken);
        try
        {
            var deliveredCount = 0;
            var rejectedCount = 0;
            var retainedCount = 0;
            PendingPunchDeliveryResult? lastResult = null;
            var reviewWarnings = new List<PunchResult>();

            foreach (var pending in _queue.Items.Take(maximumItems))
            {
                cancellationToken.ThrowIfCancellationRequested();
                lastResult = await DeliverCoreAsync(pending, cancellationToken);
                CollectReviewWarning(lastResult, reviewWarnings);

                switch (lastResult.Outcome)
                {
                    case PendingPunchDeliveryOutcome.Delivered:
                        deliveredCount++;
                        break;
                    case PendingPunchDeliveryOutcome.DeterministicallyRejected:
                        rejectedCount++;
                        break;
                    case PendingPunchDeliveryOutcome.AlreadyRemoved:
                        break;
                    default:
                        retainedCount++;
                        // Preserve persisted arrival order. Do not send later queued punches while
                        // this one still needs a retry or could not be durably removed.
                        return new PendingPunchRetrySummary(
                            deliveredCount,
                            rejectedCount,
                            retainedCount,
                            _queue.Items.Count,
                            lastResult,
                            reviewWarnings.ToArray());
                }
            }

            return new PendingPunchRetrySummary(
                deliveredCount,
                rejectedCount,
                retainedCount,
                _queue.Items.Count,
                lastResult,
                reviewWarnings.ToArray());
        }
        finally
        {
            _deliveryGate.Release();
        }
    }

    private static void CollectReviewWarning(PendingPunchDeliveryResult result, List<PunchResult> warnings)
    {
        if (result.PunchResult is { } punch
            && string.Equals(punch.ResultType, "WARNING", StringComparison.OrdinalIgnoreCase))
        {
            warnings.Add(punch);
        }
    }

    private PendingPunchDeliveryResult CompletedOrRemoved(PendingPunch pending)
        => _recentResults.TryGetValue(pending.DedupeKey, out var completed)
            ? completed
            : new PendingPunchDeliveryResult(pending, PendingPunchDeliveryOutcome.AlreadyRemoved);

    private void RememberCompleted(PendingPunchDeliveryResult result)
    {
        // A retry may complete a newly queued scan before that scan acquires the gate.
        // Preserve its actual warning/rejection for the waiting UI, without resending it.
        if (!_recentResults.ContainsKey(result.Pending.DedupeKey))
            _recentResultKeys.Enqueue(result.Pending.DedupeKey);
        _recentResults[result.Pending.DedupeKey] = result;
        while (_recentResultKeys.Count > 100)
            _recentResults.Remove(_recentResultKeys.Dequeue());
    }

    private async Task<PendingPunchDeliveryResult> DeliverCoreAsync(
        PendingPunch pending,
        CancellationToken cancellationToken)
    {
        if (!_queue.Items.Any(item => string.Equals(item.DedupeKey, pending.DedupeKey, StringComparison.Ordinal)))
        {
            return new PendingPunchDeliveryResult(pending, PendingPunchDeliveryOutcome.AlreadyRemoved);
        }

        try
        {
            var result = await _sender.SendPunchAsync(pending, cancellationToken);
            return TryRemove(pending, PendingPunchDeliveryOutcome.Delivered, result, null, null);
        }
        catch (AttendanceApiException exception) when (exception.IsDeterministicRejection)
        {
            return TryRemove(pending, PendingPunchDeliveryOutcome.DeterministicallyRejected, null, exception, null);
        }
        catch (AttendanceApiException exception)
        {
            return new PendingPunchDeliveryResult(pending, PendingPunchDeliveryOutcome.Deferred, null, exception, null);
        }
        catch (OperationCanceledException exception) when (!cancellationToken.IsCancellationRequested)
        {
            // HttpClient reports its own timeout as cancellation. Retain the queue and
            // stop this batch; only the application's cancellation should escape.
            return new PendingPunchDeliveryResult(pending, PendingPunchDeliveryOutcome.Deferred, null, null, exception);
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            return new PendingPunchDeliveryResult(pending, PendingPunchDeliveryOutcome.Deferred, null, null, exception);
        }
    }

    private PendingPunchDeliveryResult TryRemove(
        PendingPunch pending,
        PendingPunchDeliveryOutcome outcome,
        PunchResult? punchResult,
        AttendanceApiException? apiException,
        Exception? exception)
    {
        var completed = new PendingPunchDeliveryResult(pending, outcome, punchResult, apiException, exception);
        try
        {
            // A confirmation must be durable before its pending original can be removed.
            // Failure here leaves the original untouched for an idempotent server retry.
            _receipts?.Record(completed);
        }
        catch (Exception receiptException)
        {
            return completed with { Outcome = PendingPunchDeliveryOutcome.ReceiptSaveFailed, Exception = receiptException };
        }
        try
        {
            _queue.Remove(pending);
            RememberCompleted(completed);
            return completed;
        }
        catch (Exception removalException)
        {
            return new PendingPunchDeliveryResult(
                pending,
                PendingPunchDeliveryOutcome.QueueRemovalFailed,
                punchResult,
                apiException,
                removalException);
        }
    }
}
