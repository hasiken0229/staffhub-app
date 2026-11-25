using System.IO;
using System.Net;
using System.Net.Http.Json;
using System.Runtime.ExceptionServices;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Threading;
using StaffHub.PunchApp.Models;
using StaffHub.PunchApp.Services;
using StaffHub.PunchApp.ViewModels;
using Xunit;

namespace StaffHub.PunchApp.Tests;

public sealed class ShortShiftConfirmationTests
{
    [Theory]
    [InlineData(false, false, "CARD-A")]
    [InlineData(true, true, "CARD-A")]
    [InlineData(true, false, "CARD-B")]
    public void UnselectedCancelledOrOtherCardIsAnOrdinaryNewScan(bool choose, bool cancel, string retapCard)
    {
        WithTerminal(async (model, window, api, _) =>
        {
            await model.HandleScannedAsync("CARD-A");
            Assert.True(model.ShowShortShiftActions);
            if (choose) Click(window, "ConfirmShortShiftButton");
            if (cancel) Click(window, "ContinueWorkingButton");
            await model.HandleScannedAsync(retapCard);

            Assert.Equal(2, api.Requests.Count);
            Assert.Equal(retapCard, api.Requests[1].CardUid);
            Assert.NotEqual(api.Requests[0].DedupeKey, api.Requests[1].DedupeKey);
            Assert.All(api.Requests, request => Assert.False(request.ConfirmShortShift));
            if (retapCard == "CARD-B")
            {
                Assert.False(model.ShowShortShiftActions);
                Assert.Equal("職員B", model.EmployeeName);
            }
        });
    }

    [Fact]
    public void SelectingClockOutAndRescanningTheSameCardConfirmsTheOriginalExactlyOnce()
    {
        WithTerminal(async (model, window, api, _) =>
        {
            await model.HandleScannedAsync("CARD-A");
            Click(window, "ConfirmShortShiftButton");
            Assert.False(model.CanChooseShortShift);
            // An already-queued repeat Click notification must not cancel or send the choice.
            Click(window, "ConfirmShortShiftButton");
            Assert.Single(api.Requests);
            await model.HandleScannedAsync("CARD-A");
            Assert.False(model.ShowShortShiftActions);
            Assert.Empty(model.ReviewReceipts);
            Assert.Equal("退勤", model.LastEventType);
            await model.HandleScannedAsync("CARD-A");
            Assert.False(model.ShowShortShiftActions);

            var confirmation = Assert.Single(api.Requests, request => request.ConfirmShortShift);
            Assert.Equal(api.Requests[0].DedupeKey, confirmation.DedupeKey);
            Assert.Equal(api.Requests[0].OccurredAt, confirmation.OccurredAt);
            Assert.Equal(api.Requests[0].CardUid, confirmation.CardUid);
            Assert.NotEqual(confirmation.DedupeKey, api.Requests[2].DedupeKey);
        });
    }

    [Fact]
    public void LateWarningForThePreviousCardDoesNotOfferItsConfirmationToAnotherEmployee()
    {
        WithTerminal(async (model, _, api, _) =>
        {
            api.DelayRequestNumber = 1;
            var first = model.HandleScannedAsync("CARD-A");
            await api.DelayedRequestStarted.Task.WaitAsync(TimeSpan.FromSeconds(3));
            var second = model.HandleScannedAsync("CARD-B");
            api.ReleaseResponse.SetResult();
            await Task.WhenAll(first, second);

            Assert.Equal(new[] { "CARD-A", "CARD-B" }, api.Requests.Select(request => request.CardUid));
            Assert.All(api.Requests, request => Assert.False(request.ConfirmShortShift));
            Assert.Equal("職員B", model.EmployeeName);
            Assert.False(model.ShowShortShiftActions);
        });
    }

    [Fact]
    public void EveningClockOutLeavesOneHistoricalShortTapInTheRecentListAndMaintenanceLedger()
    {
        WithTerminal(async (model, window, api, queuePath) =>
        {
            await model.HandleScannedAsync("CARD-A");
            var first = Assert.Single(model.PunchHistory);
            Assert.Equal("再提示時は退勤未確定", first.StatusText);
            Assert.Equal("職員A", first.EmployeeName);
            Click(window, "ContinueWorkingButton");
            api.OrdinaryClockOut = true;
            await model.HandleScannedAsync("CARD-A");
            Assert.Equal("退勤", model.PunchHistory[0].EventTypeText);
            Assert.Equal("送信済", model.PunchHistory[0].StatusText);
            Assert.Equal(2, model.PunchHistory.Count);
            Assert.Equal("再提示", model.PunchHistory[1].EventTypeText);
            Assert.DoesNotContain(model.PunchHistory, entry => entry.StatusText.Contains("要確認"));
            var historical = Assert.Single(model.ReviewReceipts);
            Assert.True(historical.WasShortShiftTap);
            Assert.Equal("SERVER_REVIEW_REQUIRED", historical.Code);
            Assert.Contains("この時刻", historical.ReasonText);
            Assert.Equal(first.StatusText, historical.StatusText);
            var store = new PunchReviewReceiptStore(Path.Combine(Path.GetDirectoryName(queuePath)!, "receipts.json"));
            Assert.Equal(historical, Assert.Single(store.ReadAll()));
        });
    }

    [Fact]
    public void RetryingOnlyAnUnselectedShortTapReportsItsHistoricalState()
    {
        WithTerminal(async (model, _, api, _) =>
        {
            api.DropNextCandidateResponse = true;
            await model.HandleScannedAsync("CARD-A");
            Assert.Equal(1, model.PendingCount);
            await model.RetryPendingAsync();
            Assert.Equal(0, model.PendingCount);
            Assert.Equal("再提示の時刻を保存しました", model.ResultHeadline);
            Assert.DoesNotContain("管理者", model.ResultDetail);
            Assert.False(model.ShowShortShiftActions);
            Assert.Equal("再提示時は退勤未確定", Assert.Single(model.PunchHistory, row => row.EventTypeText == "再提示").StatusText);
        });
    }

    [Fact]
    public void RefusedExplicitConfirmationKeepsTheAdministratorsReviewWarning()
    {
        WithTerminal(async (model, window, api, _) =>
        {
            await model.HandleScannedAsync("CARD-A");
            Click(window, "ConfirmShortShiftButton");
            api.ConfirmationNeedsReview = true;
            await model.HandleScannedAsync("CARD-A");
            Assert.False(model.ShowShortShiftActions);
            Assert.Contains("管理者", model.ResultHeadline);
            var receipt = Assert.Single(model.ReviewReceipts);
            Assert.False(receipt.WasShortShiftTap);
            Assert.Contains("管理者", receipt.ReasonText);
            Assert.Contains("要確認", Assert.Single(model.PunchHistory).StatusText);
        });
    }

    [Fact]
    public void LostConfirmationResponseKeepsTheOriginalApprovalOnDiskForRetry()
    {
        WithTerminal(async (model, window, api, queuePath) =>
        {
            await model.HandleScannedAsync("CARD-A");
            Click(window, "ConfirmShortShiftButton");
            api.DropNextConfirmationResponse = true;
            await model.HandleScannedAsync("CARD-A");

            var persisted = Assert.Single(new OfflinePunchQueue(queuePath).Items);
            Assert.True(persisted.ConfirmShortShift);
            Assert.Equal(api.Requests[0].DedupeKey, persisted.DedupeKey);
            Assert.Equal(api.Requests[0].OccurredAt, persisted.OccurredAt);
            Assert.False(model.ShowShortShiftActions);
            await model.RetryPendingAsync();

            Assert.Empty(new OfflinePunchQueue(queuePath).Items);
            Assert.Empty(model.ReviewReceipts);
            Assert.Equal(3, api.Requests.Count);
            Assert.All(api.Requests.Skip(1), request =>
            {
                Assert.True(request.ConfirmShortShift);
                Assert.Equal(persisted.CardUid, request.CardUid);
                Assert.Equal(persisted.DedupeKey, request.DedupeKey);
                Assert.Equal(persisted.OccurredAt, request.OccurredAt);
            });
            Assert.Single(api.ConfirmedKeys);
        });
    }

    [Theory]
    [InlineData(200)]
    [InlineData(2000)]
    [InlineData(10000)]
    public void NextEmployeeOnlyGetsCompletionAfterTheirOwnResponse(int delayMs)
    {
        var tones = new List<PunchTone>();
        WithTerminal(async (model, _, api, _) =>
        {
            api.OrdinaryClockOut = true;
            await model.HandleScannedAsync("CARD-A");
            Assert.Equal("打刻完了", model.FeedbackStateLabel);
            Assert.Equal(new[] { PunchTone.CardRead, PunchTone.PunchRecorded }, tones);
            tones.Clear();
            var labels = new List<string>();
            model.PropertyChanged += (_, args) =>
            {
                if (args.PropertyName == nameof(model.FeedbackStateLabel)) labels.Add(model.FeedbackStateLabel);
            };
            api.DelayRequestNumber = 2;
            var next = model.HandleScannedAsync("CARD-B");
            await api.DelayedRequestStarted.Task.WaitAsync(TimeSpan.FromSeconds(3));
            await Task.Delay(delayMs);
            Assert.Equal(new[] { "送信中" }, labels);
            Assert.Equal("送信中", model.FeedbackStateLabel);
            Assert.Equal("確認中", model.EmployeeName);
            Assert.Equal(new[] { PunchTone.CardRead }, tones);
            api.ReleaseResponse.SetResult();
            await next;
            Assert.Equal(new[] { "送信中", "打刻完了" }, labels);
            Assert.Equal("職員B", model.EmployeeName);
            Assert.Equal(new[] { PunchTone.CardRead, PunchTone.PunchRecorded }, tones);
        }, tones.Add);
    }

    private static void Click(MainWindow window, string name)
    {
        var button = Assert.IsType<Button>(window.FindName(name));
        button.RaiseEvent(new RoutedEventArgs(Button.ClickEvent));
    }

    private static void WithTerminal(Func<MainWindowViewModel, MainWindow, PunchHandler, string, Task> check, Action<PunchTone>? tone = null)
    {
        Exception? failure = null;
        var thread = new Thread(() =>
        {
            var dispatcher = Dispatcher.CurrentDispatcher;
            dispatcher.InvokeAsync(async () =>
            {
                var directory = Directory.CreateTempSubdirectory("short-shift-test-");
                try
                {
                    using var api = new PunchHandler();
                    using var client = new HttpClient(api);
                    var queuePath = Path.Combine(directory.FullName, "pending.json");
                    var settings = new AppSettings("http://example.test", "test", "secret", "テスト端末", false, false, "MOCK", null, 300);
                    using var model = new MainWindowViewModel(settings, new OfflinePunchQueue(queuePath),
                        new PunchReviewReceiptStore(Path.Combine(directory.FullName, "receipts.json")), client, _ => { }, tone);
                    var window = new MainWindow(model);
                    try { await check(model, window, api, queuePath); }
                    finally { window.Close(); }
                }
                catch (Exception error) { failure = error; }
                finally { directory.Delete(true); dispatcher.InvokeShutdown(); }
            });
            Dispatcher.Run();
        });
        thread.SetApartmentState(ApartmentState.STA);
        thread.IsBackground = true;
        thread.Start();
        Assert.True(thread.Join(TimeSpan.FromSeconds(30)));
        if (failure is not null) ExceptionDispatchInfo.Capture(failure).Throw();
    }

    private sealed class PunchHandler : HttpMessageHandler
    {
        public List<PunchRequest> Requests { get; } = [];
        public HashSet<string> ConfirmedKeys { get; } = [];
        private readonly Dictionary<string, long> _ids = [];
        public int DelayRequestNumber { get; set; }
        public bool DropNextConfirmationResponse { get; set; }
        public bool DropNextCandidateResponse { get; set; }
        public bool ConfirmationNeedsReview { get; set; }
        public bool OrdinaryClockOut { get; set; }
        public TaskCompletionSource DelayedRequestStarted { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
        public TaskCompletionSource ReleaseResponse { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);

        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage message, CancellationToken token)
        {
            if (message.RequestUri!.Segments[^1] == "heartbeat")
                return Ok(new HeartbeatResult(true, DateTimeOffset.Now, true, 0));
            Assert.Equal("punch", message.RequestUri.Segments[^1]);
            var request = await message.Content!.ReadFromJsonAsync<PunchRequest>(token);
            Assert.NotNull(request);
            Requests.Add(request);
            if (Requests.Count == DelayRequestNumber)
            {
                DelayedRequestStarted.SetResult();
                await ReleaseResponse.Task.WaitAsync(token);
            }
            if (!_ids.ContainsKey(request.DedupeKey)) _ids.Add(request.DedupeKey, _ids.Count + 1);
            var employee = request.CardUid == "CARD-B" ? new EmployeeBrief(2, "B", "職員B") : new EmployeeBrief(1, "A", "職員A");
            var result = new PunchResult(_ids[request.DedupeKey], employee, "CLOCK_IN", "SUCCESS", "出勤を記録しました。", request.OccurredAt, false);
            if (request.ConfirmShortShift || OrdinaryClockOut)
            {
                if (ConfirmationNeedsReview)
                    return Ok(result with { EventType = null, ResultType = "WARNING", ReasonCode = "SHORT_SHIFT_REVIEW_ONLY", ResultMessage = "この時刻を管理者に確認してください。" });
                ConfirmedKeys.Add(request.DedupeKey);
                if (DropNextConfirmationResponse)
                {
                    DropNextConfirmationResponse = false;
                    throw new HttpRequestException("テスト用の応答喪失");
                }
                result = result with { EventType = "CLOCK_OUT", ResultMessage = "退勤を記録しました。" };
            }
            else if (request.CardUid == "CARD-A")
            {
                if (DropNextCandidateResponse)
                {
                    DropNextCandidateResponse = false;
                    throw new HttpRequestException("テスト用の応答喪失");
                }
                if (ConfirmedKeys.Count > 0)
                    return new HttpResponseMessage(HttpStatusCode.BadRequest)
                    {
                        Content = JsonContent.Create(new { error = new { code = "TOO_MANY_PUNCHES", message = "本日の出退勤は記録済みです。" } })
                    };
                result = result with { EventType = null, ResultType = "WARNING", ReasonCode = "SHORT_SHIFT_CONFIRMATION_REQUIRED", ResultMessage = "短時間の退勤を確認してください。" };
            }
            return Ok(result);
        }

        private static HttpResponseMessage Ok(object data) => new(HttpStatusCode.OK) { Content = JsonContent.Create(new { data }) };
    }
}
