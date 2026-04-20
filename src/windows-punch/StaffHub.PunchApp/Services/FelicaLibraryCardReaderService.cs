using System.Runtime.InteropServices;
using StaffHub.PunchApp.Models;

namespace StaffHub.PunchApp.Services;

public sealed class FelicaLibraryCardReaderService : ICardReaderService
{
    private static readonly TimeSpan DuplicateSuppressWindow = TimeSpan.FromSeconds(2);
    private readonly AppSettings _settings;
    private readonly CancellationTokenSource _cts = new();
    private Task? _worker;
    private string? _lastIdm;
    private DateTimeOffset _lastSeenAt;

    public FelicaLibraryCardReaderService(AppSettings settings)
    {
        _settings = settings;
    }

    public event EventHandler<string>? CardScanned;

    public string StatusText { get; private set; } = "FeliCaライブラリ 初期化待ち";

    public void Start()
    {
        _worker ??= Task.Run(() => PollLoopAsync(_cts.Token));
    }

    public void Dispose()
    {
        _cts.Cancel();
        try
        {
            _worker?.Wait(TimeSpan.FromSeconds(3));
        }
        catch
        {
        }

        _cts.Dispose();
    }

    private async Task PollLoopAsync(CancellationToken cancellationToken)
    {
        // Inference: function names and structure layout are based on commonly published
        // FeliCa SDK samples that use initialize_library/open_reader_writer_auto/
        // polling_and_get_card_information with RC-S380.
        if (!FelicaNative.initialize_library())
        {
            StatusText = "FeliCaライブラリ初期化失敗";
            return;
        }

        try
        {
            if (!FelicaNative.open_reader_writer_auto())
            {
                StatusText = "RC-S380 をオープンできません";
                return;
            }

            try
            {
                FelicaNative.set_polling_timeout(2000);
                StatusText = "RC-S380 待機中";

                var systemCode = new byte[] { 0xFF, 0xFF };
                var idmBuffer = new byte[8];
                var pmmBuffer = new byte[8];

                var systemCodeHandle = GCHandle.Alloc(systemCode, GCHandleType.Pinned);
                var idmHandle = GCHandle.Alloc(idmBuffer, GCHandleType.Pinned);
                var pmmHandle = GCHandle.Alloc(pmmBuffer, GCHandleType.Pinned);

                try
                {
                    var polling = new StructurePolling
                    {
                        system_code = systemCodeHandle.AddrOfPinnedObject(),
                        time_slot = 0x0F
                    };

                    var cardInfo = new StructureCardInformation
                    {
                        card_idm = idmHandle.AddrOfPinnedObject(),
                        card_pmm = pmmHandle.AddrOfPinnedObject()
                    };

                    while (!cancellationToken.IsCancellationRequested)
                    {
                        byte numberOfCards = 0;
                        Array.Clear(idmBuffer, 0, idmBuffer.Length);
                        Array.Clear(pmmBuffer, 0, pmmBuffer.Length);

                        if (FelicaNative.polling_and_get_card_information(ref polling, ref numberOfCards, ref cardInfo)
                            && numberOfCards > 0)
                        {
                            var idm = Convert.ToHexString(idmBuffer);
                            if (IsValidIdm(idm))
                            {
                                EmitIfNeeded(idm);
                                StatusText = $"カード検知: {idm}";
                            }
                            else
                            {
                                StatusText = "無効なカードIDを検知";
                            }
                        }
                        else
                        {
                            StatusText = "RC-S380 待機中";
                            _lastIdm = null;
                        }

                        await Task.Delay(_settings.PollIntervalMilliseconds, cancellationToken);
                    }
                }
                finally
                {
                    if (systemCodeHandle.IsAllocated) systemCodeHandle.Free();
                    if (idmHandle.IsAllocated) idmHandle.Free();
                    if (pmmHandle.IsAllocated) pmmHandle.Free();
                }
            }
            finally
            {
                FelicaNative.close_reader_writer();
            }
        }
        finally
        {
            FelicaNative.dispose_library();
        }
    }

    private void EmitIfNeeded(string idm)
    {
        var now = DateTimeOffset.Now;
        if (idm == _lastIdm && now - _lastSeenAt < DuplicateSuppressWindow)
        {
            return;
        }

        _lastIdm = idm;
        _lastSeenAt = now;
        CardScanned?.Invoke(this, idm);
    }

    private static bool IsValidIdm(string idm)
        => !string.IsNullOrWhiteSpace(idm) && idm != "0000000000000000";

    [StructLayout(LayoutKind.Sequential)]
    private struct StructurePolling
    {
        public IntPtr system_code;
        public byte time_slot;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct StructureCardInformation
    {
        public IntPtr card_idm;
        public IntPtr card_pmm;
    }

    private static class FelicaNative
    {
        private const string DllPath = "felica.dll";

        [DllImport(DllPath, CallingConvention = CallingConvention.Cdecl)]
        public static extern bool initialize_library();

        [DllImport(DllPath, CallingConvention = CallingConvention.Cdecl)]
        public static extern bool dispose_library();

        [DllImport(DllPath, CallingConvention = CallingConvention.Cdecl)]
        public static extern bool open_reader_writer_auto();

        [DllImport(DllPath, CallingConvention = CallingConvention.Cdecl)]
        public static extern bool close_reader_writer();

        [DllImport(DllPath, CallingConvention = CallingConvention.Cdecl)]
        public static extern bool set_polling_timeout(uint timeout);

        [DllImport(DllPath, CallingConvention = CallingConvention.Cdecl)]
        public static extern bool polling_and_get_card_information(
            ref StructurePolling polling,
            ref byte number_of_cards,
            ref StructureCardInformation card_information);
    }
}
