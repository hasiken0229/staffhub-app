using System.IO;
using System.Net.Http;
using System.Text.Json;

namespace StaffHub.PunchApp.Services;

public sealed record QueueDiagnostic(string Status, int? PendingCount);
public sealed record SettingsDiagnostic(string Status, bool HasDeviceCode, bool HasDeviceSecret, string? ApiHost, string? ReaderMode);
public sealed record ConnectionDiagnostic(string Status, int? HttpStatus, double? ClockDifferenceSeconds);
public sealed record PunchDiagnosticReport(string Version, DateTimeOffset RecordedAt, SettingsDiagnostic Settings,
    QueueDiagnostic Queue, string ReaderStatus, string[] Readers, ConnectionDiagnostic Connection);

/// <summary>Does not construct a punch sender, reader monitor, or OfflinePunchQueue.</summary>
public static class PunchDiagnostics
{
    public static QueueDiagnostic InspectQueue(string path)
    {
        if (!File.Exists(path)) return new("MISSING", null);
        try
        {
            using var document = JsonDocument.Parse(File.ReadAllText(path));
            if (document.RootElement.ValueKind != JsonValueKind.Array) return new("INVALID", null);
            var keys = new HashSet<string>();
            foreach (var item in document.RootElement.EnumerateArray())
            {
                if (!item.TryGetProperty("dedupeKey", out var key) || string.IsNullOrWhiteSpace(key.GetString())
                    || !keys.Add(key.GetString()!) || !item.TryGetProperty("cardUid", out var card)
                    || string.IsNullOrWhiteSpace(card.GetString()) || !item.TryGetProperty("occurredAt", out var date)
                    || !DateTimeOffset.TryParse(date.GetString(), out _)) return new("INVALID", null);
            }
            return new("OK", document.RootElement.GetArrayLength());
        }
        catch (JsonException) { return new("INVALID", null); }
        catch (InvalidOperationException) { return new("INVALID", null); }
        catch (IOException) { return new("UNREADABLE", null); }
        catch (UnauthorizedAccessException) { return new("UNREADABLE", null); }
    }

    public static async Task<PunchDiagnosticReport> InspectAsync(string settingsPath, string queuePath,
        HttpMessageHandler? handler = null, Func<(string Status, string[] Readers)>? probeReaders = null)
    {
        SettingsDiagnostic settings;
        Uri? baseUri = null;
        try
        {
            if (!File.Exists(settingsPath)) settings = new("MISSING", false, false, null, null);
            else
            {
                using var document = JsonDocument.Parse(File.ReadAllText(settingsPath));
                string? Field(string name) => document.RootElement.EnumerateObject()
                    .Where(p => p.Name.Equals(name, StringComparison.OrdinalIgnoreCase))
                    .Select(p => p.Value.ValueKind == JsonValueKind.String ? p.Value.GetString() : null).FirstOrDefault();
                var url = Field("ApiBaseUrl");
                bool Present(string? value) => !string.IsNullOrWhiteSpace(value) && !value.Contains("CHANGE_ME", StringComparison.OrdinalIgnoreCase);
                var validUrl = Uri.TryCreate(url, UriKind.Absolute, out baseUri) && string.IsNullOrEmpty(baseUri.UserInfo)
                    && string.IsNullOrEmpty(baseUri.Query) && string.IsNullOrEmpty(baseUri.Fragment)
                    && (baseUri.Scheme == "https" || (baseUri.Scheme == "http" && baseUri.IsLoopback));
                settings = new(validUrl && Present(Field("DeviceCode")) && Present(Field("DeviceSecret")) ? "OK" : "INCOMPLETE",
                    Present(Field("DeviceCode")), Present(Field("DeviceSecret")), validUrl ? baseUri!.Authority : null, Field("ReaderMode"));
                if (!validUrl) baseUri = null;
            }
        }
        catch (Exception error) when (error is JsonException or IOException or UnauthorizedAccessException or InvalidOperationException)
        { settings = new("INVALID", false, false, null, null); }
        var queue = InspectQueue(queuePath);
        var readers = (probeReaders ?? RcS380CardReaderService.ProbeReaders)();
        var connection = new ConnectionDiagnostic("NOT_CHECKED", null, null);
        if (baseUri is not null)
        {
            using var client = new HttpClient(handler ?? new HttpClientHandler { AllowAutoRedirect = false });
            client.Timeout = TimeSpan.FromSeconds(10);
            try
            {
                var before = DateTimeOffset.UtcNow;
                using var response = await client.GetAsync(baseUri.AbsoluteUri.TrimEnd('/') + "/up");
                var after = DateTimeOffset.UtcNow;
                var midpoint = before + (after - before) / 2;
                connection = new(response.IsSuccessStatusCode ? "OK" : "HTTP_ERROR", (int)response.StatusCode,
                    response.Headers.Date is { } serverTime ? Math.Round((serverTime - midpoint).TotalSeconds, 1) : null);
            }
            catch (Exception error) when (error is HttpRequestException or TaskCanceledException)
            { connection = new("UNREACHABLE", null, null); }
        }
        return new(typeof(PunchDiagnostics).Assembly.GetName().Version?.ToString() ?? "unknown", DateTimeOffset.Now,
            settings, queue, readers.Status, readers.Readers, connection);
    }

    public static async Task<string> SaveAsync(string settingsPath, string queuePath, string outputDirectory)
    {
        var report = await InspectAsync(settingsPath, queuePath);
        Directory.CreateDirectory(outputDirectory);
        var path = Path.Combine(outputDirectory, $"diagnostics-{DateTime.Now:yyyyMMdd-HHmmss}-{Guid.NewGuid():N}.json");
        await File.WriteAllTextAsync(path, JsonSerializer.Serialize(report, new JsonSerializerOptions { WriteIndented = true }));
        return path;
    }
}
