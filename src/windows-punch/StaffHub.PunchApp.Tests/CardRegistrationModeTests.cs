using System.IO;
using System.Net;
using System.Net.Http.Json;
using System.Runtime.ExceptionServices;
using System.Windows.Threading;
using StaffHub.PunchApp.Models;
using StaffHub.PunchApp.Services;
using StaffHub.PunchApp.ViewModels;
using Xunit;

namespace StaffHub.PunchApp.Tests;

public sealed class CardRegistrationModeTests
{
    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public void ClosingMaintenanceReturnsToPunching(bool readRegistrationCard)
    {
        Exception? failure = null;
        var thread = new Thread(() =>
        {
            var dispatcher = Dispatcher.CurrentDispatcher;
            dispatcher.InvokeAsync(async () =>
            {
                try { await CheckModeChange(readRegistrationCard); }
                catch (Exception ex) { failure = ex; }
                finally { dispatcher.InvokeShutdown(); }
            });
            Dispatcher.Run();
        });
        thread.SetApartmentState(ApartmentState.STA);
        thread.IsBackground = true;
        thread.Start();
        Assert.True(thread.Join(TimeSpan.FromSeconds(15)));
        if (failure is not null) ExceptionDispatchInfo.Capture(failure).Throw();
    }

    private static async Task CheckModeChange(bool readRegistrationCard)
    {
        var directory = Directory.CreateTempSubdirectory("staffhub-registration-test-");
        try
        {
            using var handler = new RegistrationHandler();
            using var client = new HttpClient(handler);
            var settings = new AppSettings("http://example.test", "mock", "test", "テスト端末", false, false, "MOCK", null, 300);
            string? clipboard = null;
            using var model = new MainWindowViewModel(settings,
                new OfflinePunchQueue(Path.Combine(directory.FullName, "queue.json")),
                new PunchReviewReceiptStore(Path.Combine(directory.FullName, "receipts.json")), client, value => clipboard = value);
            var window = new MainWindow(model);
            try
            {
                window.SetMaintenanceVisibility(true);
                model.CardRegistrationModeEnabled = true;
                Assert.Equal("カード登録専用", model.FeedbackStateLabel);
                Task? assignment = null;
                if (readRegistrationCard)
                {
                    using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(3));
                    while (model.IsCardRegistrationBusy) await Task.Delay(10, timeout.Token);
                    await model.HandleScannedAsync("TEST-CARD");
                    Assert.Equal("TEST-CARD", clipboard);
                    Assert.Equal("カード登録専用", model.FeedbackStateLabel);
                    Assert.Equal("打刻は記録されません", model.EmployeeName);
                    Assert.Empty(model.PendingPunches);
                    Assert.DoesNotContain("punch", handler.Operations);
                    assignment = model.RegisterLastCardAsync();
                    Assert.Contains("assign", handler.Operations);
                }

                window.SetMaintenanceVisibility(false);
                Assert.False(model.CardRegistrationModeEnabled);
                Assert.Equal("待機中", model.FeedbackStateLabel);
                Assert.Equal("カードをかざしてください", model.ResultHeadline);
                if (assignment is not null)
                {
                    handler.CompleteAssignment.SetResult();
                    await assignment;
                    Assert.Contains("登録しました", model.CardRegistrationMessage);
                    Assert.Equal("カードをかざしてください", model.ResultHeadline);
                }
                window.SetMaintenanceVisibility(true);
                Assert.False(model.CardRegistrationModeEnabled);
            }
            finally { window.Close(); }
        }
        finally { directory.Delete(true); }
    }

    private sealed class RegistrationHandler : HttpMessageHandler
    {
        public List<string> Operations { get; } = [];
        public TaskCompletionSource CompleteAssignment { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);

        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken token)
        {
            var operation = request.RequestUri!.Segments[^1];
            Operations.Add(operation);
            object data;
            if (operation == "employees")
            {
                data = new[] { new RegistrationEmployee(1, "TEST", "テスト職員", null, "ACTIVE") };
            }
            else if (operation == "assign")
            {
                await CompleteAssignment.Task.WaitAsync(token);
                data = new CardAssignmentResult(1, 1, "TEST", "テスト職員", "TEST-CARD", true, DateTimeOffset.Now, null);
            }
            else throw new InvalidOperationException($"Unexpected test endpoint {operation}");
            return new HttpResponseMessage(HttpStatusCode.OK) { Content = JsonContent.Create(new { data }) };
        }
    }
}
