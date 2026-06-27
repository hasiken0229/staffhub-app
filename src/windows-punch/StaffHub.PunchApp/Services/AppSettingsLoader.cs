using System.IO;
using System.Text.Json;
using StaffHub.PunchApp.Models;

namespace StaffHub.PunchApp.Services;

public static class AppSettingsLoader
{
    private const string FileName = "punchsettings.json";

    public static AppSettings Load()
    {
        var path = Path.Combine(AppContext.BaseDirectory, FileName);
        if (!File.Exists(path))
        {
            return Default();
        }

        try
        {
            var json = File.ReadAllText(path);
            var dto = JsonSerializer.Deserialize<AppSettingsDto>(json, new JsonSerializerOptions
            {
                PropertyNameCaseInsensitive = true,
            });

            if (dto is null)
            {
                return Default();
            }

            return new AppSettings(
                NormalizeBaseUrl(dto.ApiBaseUrl),
                NormalizeDeviceCode(dto.DeviceCode),
                NormalizeDeviceSecret(dto.DeviceSecret),
                dto.DeviceName ?? "玄関端末",
                dto.AutoStartEnabled ?? true,
                dto.StartMinimized ?? true,
                dto.ReaderMode ?? "RC_S380",
                dto.PreferredReaderName,
                dto.PollIntervalMilliseconds is > 0 ? dto.PollIntervalMilliseconds.Value : 300);
        }
        catch
        {
            return Default();
        }
    }

    private static AppSettings Default()
        => new(
            "http://localhost:5000",
            "CHANGE_ME_DEVICE_CODE",
            "CHANGE_ME_DEVICE_SECRET",
            "玄関端末",
            true,
            true,
            "RC_S380",
            "RC-S380",
            300);

    private static string NormalizeBaseUrl(string? value)
    {
        var raw = string.IsNullOrWhiteSpace(value) ? Default().ApiBaseUrl : value.Trim();
        return raw.TrimEnd('/');
    }

    private static string NormalizeDeviceCode(string? value)
        => string.IsNullOrWhiteSpace(value) ? Default().DeviceCode : value.Trim();

    private static string NormalizeDeviceSecret(string? value)
        => string.IsNullOrWhiteSpace(value) ? Default().DeviceSecret : value.Trim();

    private sealed class AppSettingsDto
    {
        public string? ApiBaseUrl { get; set; }
        public string? DeviceCode { get; set; }
        public string? DeviceSecret { get; set; }
        public string? DeviceName { get; set; }
        public bool? AutoStartEnabled { get; set; }
        public bool? StartMinimized { get; set; }
        public string? ReaderMode { get; set; }
        public string? PreferredReaderName { get; set; }
        public int? PollIntervalMilliseconds { get; set; }
    }
}
