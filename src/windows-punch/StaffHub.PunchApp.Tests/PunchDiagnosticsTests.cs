using System.IO;
using System.Net;
using System.Net.Http;
using System.Text.Json;
using StaffHub.PunchApp.Services;
using Xunit;

namespace StaffHub.PunchApp.Tests;

public sealed class PunchDiagnosticsTests
{
    [Theory]
    [InlineData("not json", "INVALID", null)]
    [InlineData("[]", "OK", 0)]
    [InlineData("[{}]", "INVALID", null)]
    public async Task Diagnostic_keeps_queue_unchanged_and_sends_only_health_get(string content, string status, int? count)
    {
        var directory = Path.Combine(Path.GetTempPath(), "staffhub-diagnostics-" + Guid.NewGuid());
        Directory.CreateDirectory(directory);
        try
        {
            var queue = Path.Combine(directory, "pending-punches.json");
            var settings = Path.Combine(directory, "punchsettings.json");
            await File.WriteAllTextAsync(queue, content);
            await File.WriteAllTextAsync(settings, """{"apiBaseUrl":"https://example.test/dakoku","deviceCode":"private-code","deviceSecret":"must-not-leak","readerMode":"RC_S380"}""");
            var handler = new HealthHandler();
            var report = await PunchDiagnostics.InspectAsync(settings, queue, handler, () => ("NOT_FOUND", []));
            Assert.Equal(status, report.Queue.Status);
            Assert.Equal(count, report.Queue.PendingCount);
            Assert.Equal(content, await File.ReadAllTextAsync(queue));
            Assert.Equal(2, Directory.GetFiles(directory).Length);
            Assert.Equal(1, handler.Requests);
            var json = JsonSerializer.Serialize(report);
            Assert.DoesNotContain("must-not-leak", json);
            Assert.DoesNotContain("private-code", json);
        }
        finally { Directory.Delete(directory, true); }
    }

    [Fact]
    public async Task Invalid_settings_do_not_fall_back_to_a_live_api()
    {
        var directory = Path.Combine(Path.GetTempPath(), "staffhub-diagnostics-" + Guid.NewGuid());
        Directory.CreateDirectory(directory);
        try
        {
            var settings = Path.Combine(directory, "settings.json");
            await File.WriteAllTextAsync(settings, "broken");
            var handler = new HealthHandler();
            var report = await PunchDiagnostics.InspectAsync(settings, Path.Combine(directory, "missing.json"), handler, () => ("NOT_FOUND", []));
            Assert.Equal("INVALID", report.Settings.Status);
            Assert.Equal("MISSING", report.Queue.Status);
            Assert.Null(report.Queue.PendingCount);
            Assert.Equal(0, handler.Requests);
        }
        finally { Directory.Delete(directory, true); }
    }

    private sealed class HealthHandler : HttpMessageHandler
    {
        public int Requests { get; private set; }
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            Requests++;
            Assert.Equal(HttpMethod.Get, request.Method);
            Assert.Equal("https://example.test/dakoku/up", request.RequestUri!.ToString());
            Assert.Null(request.Content);
            Assert.Null(request.Headers.Authorization);
            var response = new HttpResponseMessage(HttpStatusCode.OK);
            response.Headers.Date = DateTimeOffset.UtcNow;
            return Task.FromResult(response);
        }
    }
}
