using StaffHub.PunchApp.Models;
using StaffHub.PunchApp.Services;
using System.Text.Json;
using Xunit;

namespace StaffHub.PunchApp.Tests;

public sealed class OfflinePunchQueueTests : IDisposable
{
    private readonly string _directory = Path.Combine(Path.GetTempPath(), $"staffhub-punch-tests-{Guid.NewGuid():N}");

    [Fact]
    public void EnqueueAndRemoveArePersistedAcrossQueueInstances()
    {
        var path = Path.Combine(_directory, "pending-punches.json");
        var pending = new PendingPunch(
            "0123456789ABCDEF",
            new DateTimeOffset(2026, 8, 27, 8, 15, 30, TimeSpan.FromHours(9)),
            "DEVICE-01-fixed-dedupe-key");

        var first = new OfflinePunchQueue(path);
        first.Enqueue(pending);

        var restored = new OfflinePunchQueue(path);
        Assert.Equal(pending, Assert.Single(restored.Items));

        restored.Remove(pending);
        Assert.Empty(new OfflinePunchQueue(path).Items);
    }

    [Fact]
    public void DuplicateDedupeKeyIsStoredOnlyOnce()
    {
        var path = Path.Combine(_directory, "pending-punches.json");
        var queue = new OfflinePunchQueue(path);
        var first = new PendingPunch("CARD-1", DateTimeOffset.Now, "same-key");
        var second = new PendingPunch("CARD-2", DateTimeOffset.Now.AddMinutes(1), "same-key");

        queue.Enqueue(first);
        queue.Enqueue(second);

        Assert.Equal(first, Assert.Single(new OfflinePunchQueue(path).Items));
    }

    [Fact]
    public void UnreadableExistingQueueFailsWithoutReplacingOrMovingItsContents()
    {
        var path = Path.Combine(_directory, "pending-punches.json");
        var original = CreatePending("original");
        WriteQueue(path, original);
        var originalBytes = File.ReadAllBytes(path);

        using (var lockedFile = new FileStream(path, FileMode.Open, FileAccess.ReadWrite, FileShare.None))
        {
            Assert.ThrowsAny<IOException>(() => new OfflinePunchQueue(path));
        }

        Assert.Equal(originalBytes, File.ReadAllBytes(path));
        Assert.Empty(Directory.GetFiles(_directory, "pending-punches.json.corrupt-*"));

        var restored = new OfflinePunchQueue(path);
        Assert.Equal(original, Assert.Single(restored.Items));
        var next = CreatePending("next");
        restored.Enqueue(next);
        Assert.Equal(new[] { original, next }, new OfflinePunchQueue(path).Items);
    }

    [Fact]
    public void ExistingQueueFormatKeepsOriginalUidTimestampOffsetAndDedupeKey()
    {
        Directory.CreateDirectory(_directory);
        var path = Path.Combine(_directory, "pending-punches.json");
        const string json = "[{\"cardUid\":\"0000123456789ABC\",\"occurredAt\":\"2026-10-10T23:59:59.1234567+09:00\",\"dedupeKey\":\"LEGACY-unchanged-key\"}]";
        File.WriteAllText(path, json);

        var queue = new OfflinePunchQueue(path);
        var original = Assert.Single(queue.Items);
        queue.Enqueue(CreatePending("next"));

        var restored = new OfflinePunchQueue(path).Items;
        Assert.Equal(2, restored.Count);
        Assert.Equal("0000123456789ABC", restored[0].CardUid);
        Assert.Equal("2026-10-10T23:59:59.1234567+09:00", restored[0].OccurredAt.ToString("O"));
        Assert.Equal("LEGACY-unchanged-key", restored[0].DedupeKey);
        Assert.Equal(original, restored[0]);
    }

    [Fact]
    public void ExistingDuplicateKeysFailWithoutChangingTheOriginalFile()
    {
        var path = Path.Combine(_directory, "pending-punches.json");
        var original = CreatePending("duplicate");
        WriteQueue(path, original, original with { CardUid = "ANOTHER-SYNTHETIC-CARD" });
        var originalBytes = File.ReadAllBytes(path);

        Assert.Throws<InvalidDataException>(() => new OfflinePunchQueue(path));

        Assert.Equal(originalBytes, File.ReadAllBytes(path));
    }

    [Theory]
    [InlineData("{")]
    [InlineData("null")]
    [InlineData("[null]")]
    [InlineData("[{}]")]
    [InlineData("[{\"cardUid\":\"\",\"occurredAt\":\"2026-10-12T08:15:30+09:00\",\"dedupeKey\":\"key\"}]")]
    [InlineData("[{\"cardUid\":\"CARD\",\"occurredAt\":\"2026-10-12T08:15:30+09:00\",\"dedupeKey\":\" \"}]")]
    [InlineData("[{\"cardUid\":\"CARD\",\"occurredAt\":\"0001-01-01T00:00:00+00:00\",\"dedupeKey\":\"key\"}]")]
    public void InvalidQueueDataFailsWithoutDiscardingTheOriginalFile(string json)
    {
        Directory.CreateDirectory(_directory);
        var path = Path.Combine(_directory, "pending-punches.json");
        File.WriteAllText(path, json);
        var originalBytes = File.ReadAllBytes(path);

        Assert.Throws<InvalidDataException>(() => new OfflinePunchQueue(path));

        Assert.Equal(originalBytes, File.ReadAllBytes(path));
        Assert.Empty(Directory.GetFiles(_directory, "pending-punches.json.corrupt-*"));
    }

    [Fact]
    public void EnqueueRejectsFileCorruptionAfterStartupAndPreservesTheLastValidSnapshot()
    {
        var path = Path.Combine(_directory, "pending-punches.json");
        var original = CreatePending("original");
        var queue = new OfflinePunchQueue(path);
        queue.Enqueue(original);
        File.WriteAllText(path, "{broken-after-startup");
        var corruptBytes = File.ReadAllBytes(path);

        Assert.Throws<InvalidDataException>(() => queue.Enqueue(CreatePending("next")));

        Assert.Equal(original, Assert.Single(queue.Items));
        Assert.Equal(corruptBytes, File.ReadAllBytes(path));
        Assert.Empty(Directory.GetFiles(_directory, "pending-punches.json.corrupt-*"));
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public void MissingFileDoesNotTurnAnExistingNonemptyQueueIntoAnEmptyQueue(bool remove)
    {
        var path = Path.Combine(_directory, "pending-punches.json");
        var original = CreatePending("original");
        var queue = new OfflinePunchQueue(path);
        queue.Enqueue(original);
        File.Delete(path);

        if (remove)
        {
            Assert.ThrowsAny<IOException>(() => queue.Remove(original));
        }
        else
        {
            Assert.ThrowsAny<IOException>(() => queue.Enqueue(CreatePending("next")));
        }

        Assert.False(File.Exists(path));
        Assert.Equal(original, Assert.Single(queue.Items));
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public void FailedReplacementPreservesMemoryDiskAndOrderAndCanBeRetried(bool remove)
    {
        var path = Path.Combine(_directory, "pending-punches.json");
        var first = CreatePending("first");
        var second = CreatePending("second");
        var third = CreatePending("third");
        var queue = new OfflinePunchQueue(path);
        queue.Enqueue(first);
        queue.Enqueue(second);
        var originalBytes = File.ReadAllBytes(path);

        // Reading remains possible, but replacing/deleting the destination is denied on Windows.
        using (var lockedFile = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.Read))
        {
            var error = Record.Exception(() =>
            {
                if (remove) queue.Remove(first);
                else queue.Enqueue(third);
            });
            // Windows replacement can report a sharing conflict as access denied.
            Assert.True(error is IOException or UnauthorizedAccessException,
                $"Expected a storage failure, got {error?.GetType().Name ?? "no exception"}.");

            Assert.Equal(new[] { first, second }, queue.Items);
            Assert.Equal(originalBytes, File.ReadAllBytes(path));
        }

        if (remove)
        {
            queue.Remove(first);
            Assert.Equal(second, Assert.Single(queue.Items));
            Assert.Equal(second, Assert.Single(new OfflinePunchQueue(path).Items));
        }
        else
        {
            queue.Enqueue(third);
            Assert.Equal(new[] { first, second, third }, queue.Items);
            Assert.Equal(new[] { first, second, third }, new OfflinePunchQueue(path).Items);
        }
    }

    [Fact]
    public void StaleQueueInstancesDoNotLoseNewPunchesOrResurrectRemovedPunches()
    {
        var path = Path.Combine(_directory, "pending-punches.json");
        var firstQueue = new OfflinePunchQueue(path);
        var secondQueue = new OfflinePunchQueue(path);
        var first = CreatePending("first");
        var second = CreatePending("second");
        var third = CreatePending("third");

        firstQueue.Enqueue(first);
        secondQueue.Enqueue(second);
        Assert.Equal(new[] { first, second }, new OfflinePunchQueue(path).Items);

        firstQueue.Remove(first);
        Assert.Equal(second, Assert.Single(new OfflinePunchQueue(path).Items));

        secondQueue.Enqueue(third);
        Assert.Equal(new[] { second, third }, new OfflinePunchQueue(path).Items);
    }

    [Fact]
    public async Task ConcurrentQueueInstancesRetainEveryDistinctPunch()
    {
        var path = Path.Combine(_directory, "pending-punches.json");
        var firstQueue = new OfflinePunchQueue(path);
        var secondQueue = new OfflinePunchQueue(path);
        var firstBatch = Enumerable.Range(0, 10).Select(i => CreatePending($"first-{i}")).ToArray();
        var secondBatch = Enumerable.Range(0, 10).Select(i => CreatePending($"second-{i}")).ToArray();

        await Task.WhenAll(
            Task.Run(() => { foreach (var pending in firstBatch) firstQueue.Enqueue(pending); }),
            Task.Run(() => { foreach (var pending in secondBatch) secondQueue.Enqueue(pending); }));

        var restored = new OfflinePunchQueue(path).Items;
        Assert.Equal(20, restored.Count);
        Assert.Equal(
            firstBatch.Concat(secondBatch).OrderBy(p => p.DedupeKey, StringComparer.Ordinal),
            restored.OrderBy(p => p.DedupeKey, StringComparer.Ordinal));
    }

    [Fact]
    public void ApplicationLeaseRejectsTheSameQueueUntilTheOwnerExits()
    {
        var path = Path.Combine(_directory, "pending-punches.json");
        using (var owner = PunchApplicationLease.TryAcquire(path))
        {
            Assert.NotNull(owner);
            using var duplicate = PunchApplicationLease.TryAcquire(path);
            Assert.Null(duplicate);
        }

        // A remaining sidecar is not a running owner: the OS lock, not file existence, is decisive.
        Assert.True(File.Exists(path + ".instance.lock"));
        using var nextOwner = PunchApplicationLease.TryAcquire(path);
        Assert.NotNull(nextOwner);
        Assert.False(File.Exists(path));
    }

    [Fact]
    public void ApplicationLeaseForAnotherQueueDoesNotBlockIndependentSyntheticStorage()
    {
        using var first = PunchApplicationLease.TryAcquire(Path.Combine(_directory, "first.json"));
        using var second = PunchApplicationLease.TryAcquire(Path.Combine(_directory, "second.json"));

        Assert.NotNull(first);
        Assert.NotNull(second);
    }

    private static PendingPunch CreatePending(string key)
        => new(
            "0123456789ABCDEF",
            new DateTimeOffset(2026, 10, 12, 8, 15, 30, TimeSpan.FromHours(9)),
            $"SYNTHETIC-{key}");

    private static void WriteQueue(string path, params PendingPunch[] items)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        File.WriteAllText(path, JsonSerializer.Serialize(items, new JsonSerializerOptions(JsonSerializerDefaults.Web)));
    }

    public void Dispose()
    {
        if (Directory.Exists(_directory))
        {
            Directory.Delete(_directory, recursive: true);
        }
    }
}
