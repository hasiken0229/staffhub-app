using System.IO;
using StaffHub.PunchApp.Models;

namespace StaffHub.PunchApp.Services;

public static class CardReaderFactory
{
    private const string FelicaDirectory = @"C:\Program Files\Common Files\Sony Shared\FeliCaLibrary";

    public static string ResolveMode(string? configured, bool felicaAvailable)
    {
        var mode = (configured ?? "RC_S380").Trim().ToUpperInvariant();
        // Existing deployments used RC_S380 while selection was automatic.
        if (mode is "AUTO" or "RC_S380" or "") return felicaAvailable ? "FELICA" : "PCSC";
        return mode is "MOCK" or "PCSC" or "FELICA" ? mode : "INVALID";
    }

    public static ICardReaderService Create(AppSettings settings, Func<bool>? hasFelica = null,
        Func<AppSettings, ICardReaderService>? pcsc = null, Func<AppSettings, ICardReaderService>? felica = null)
    {
        var configured = settings.ReaderMode.Trim().ToUpperInvariant();
        var available = (configured is "AUTO" or "RC_S380" or "")
            && (hasFelica ?? (() => File.Exists(Path.Combine(FelicaDirectory, "felica.dll"))))();
        return ResolveMode(configured, available) switch
        {
            "MOCK" => new MockCardReaderService(),
            "PCSC" => (pcsc ?? (value => new RcS380CardReaderService(value)))(settings),
            "FELICA" => (felica ?? CreateFelica)(settings),
            _ => new UnavailableReader("設定エラー: ReaderMode は MOCK / PCSC / FELICA / AUTO を指定してください"),
        };
    }

    private static ICardReaderService CreateFelica(AppSettings settings)
    {
        var path = Environment.GetEnvironmentVariable("PATH") ?? "";
        if (!path.Split(';').Contains(FelicaDirectory, StringComparer.OrdinalIgnoreCase))
            Environment.SetEnvironmentVariable("PATH", path + ";" + FelicaDirectory);
        return new FelicaLibraryCardReaderService(settings);
    }

    private sealed class UnavailableReader(string status) : ICardReaderService
    {
        public event EventHandler<string>? CardScanned { add { } remove { } }
        public string StatusText => status;
        public void Start() { }
        public void Dispose() { }
    }
}
