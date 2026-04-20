using System.Net.Http;
using System.Net.Http.Json;
using StaffHub.PunchApp.Models;

namespace StaffHub.PunchApp.Services;

public interface ICardReaderService : IDisposable
{
    event EventHandler<string>? CardScanned;
    string StatusText { get; }
    void Start();
}

public sealed class MockCardReaderService : ICardReaderService
{
    public event EventHandler<string>? CardScanned;

    public string StatusText => "模擬カードリーダー";

    public void Start()
    {
    }

    public void SimulateScan(string cardUid)
    {
        if (!string.IsNullOrWhiteSpace(cardUid))
        {
            CardScanned?.Invoke(this, cardUid.Trim());
        }
    }

    public void Dispose()
    {
    }
}

public sealed class OfflinePunchQueue
{
    private readonly List<PendingPunch> _items = [];

    public IReadOnlyList<PendingPunch> Items => _items;

    public void Enqueue(PendingPunch item) => _items.Add(item);

    public void Remove(PendingPunch item) => _items.Remove(item);
}

public sealed class AttendanceApiException : Exception
{
    public AttendanceApiException(string code, string message)
        : base(message)
    {
        Code = code;
    }

    public string Code { get; }
}

public sealed class AttendanceApiClient
{
    private readonly HttpClient _httpClient = new();
    private readonly AppSettings _settings;

    public AttendanceApiClient(AppSettings settings)
    {
        _settings = settings;
        _httpClient.BaseAddress = new Uri(settings.ApiBaseUrl.TrimEnd('/') + "/");
    }

    public async Task<PunchResult> SendPunchAsync(string cardUid, CancellationToken cancellationToken = default)
    {
        var request = new PunchRequest(
            _settings.DeviceCode,
            _settings.DeviceSecret,
            cardUid,
            DateTimeOffset.Now,
            $"{_settings.DeviceCode}-{Guid.NewGuid():N}",
            "0.1.0");

        var response = await _httpClient.PostAsJsonAsync("api/attendance/punch", request, cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            var error = await response.Content.ReadFromJsonAsync<ApiErrorEnvelope>(cancellationToken: cancellationToken);
            var code = error?.Error?.Code ?? "API_ERROR";
            var message = error?.Error?.Message ?? "打刻APIの呼び出しに失敗しました。";
            throw new AttendanceApiException(code, message);
        }

        var body = await response.Content.ReadFromJsonAsync<ApiEnvelope<PunchResult>>(cancellationToken: cancellationToken);
        return body?.Data ?? throw new InvalidOperationException("打刻応答の解析に失敗しました。");
    }

    private sealed class ApiEnvelope<T>
    {
        public T? Data { get; set; }
    }

    private sealed class ApiErrorEnvelope
    {
        public ApiErrorBody? Error { get; set; }
    }

    private sealed class ApiErrorBody
    {
        public string? Code { get; set; }
        public string? Message { get; set; }
    }
}
