using System.Runtime.InteropServices;
using System.Text;
using StaffHub.PunchApp.Models;

namespace StaffHub.PunchApp.Services;

public sealed class RcS380CardReaderService : ICardReaderService
{
    private const uint ScardScopeUser = 0x0000;
    private const uint ScardShareShared = 0x0002;
    private const uint ScardProtocolT0 = 0x0001;
    private const uint ScardProtocolT1 = 0x0002;
    private const uint ScardProtocolRaw = 0x00010000;
    private const int ScardSuccess = 0x00000000;

    private static readonly byte[] GetUidCommand = [0xFF, 0xCA, 0x00, 0x00, 0x00];

    private readonly AppSettings _settings;
    private readonly CancellationTokenSource _cts = new();
    private Task? _monitorTask;
    private string? _lastCardUid;
    private DateTimeOffset _lastSeenAt;

    public RcS380CardReaderService(AppSettings settings)
    {
        _settings = settings;
    }

    public event EventHandler<string>? CardScanned;

    public string StatusText { get; private set; } = "RC-S380 初期化待ち";

    public void Start()
    {
        _monitorTask ??= Task.Run(() => MonitorLoopAsync(_cts.Token));
    }

    public void Dispose()
    {
        _cts.Cancel();
        try
        {
            _monitorTask?.Wait(TimeSpan.FromSeconds(1));
        }
        catch
        {
        }
        _cts.Dispose();
    }

    private async Task MonitorLoopAsync(CancellationToken cancellationToken)
    {
        while (!cancellationToken.IsCancellationRequested)
        {
            IntPtr context = IntPtr.Zero;
            try
            {
                var result = NativeMethods.SCardEstablishContext(ScardScopeUser, IntPtr.Zero, IntPtr.Zero, out context);
                if (result != ScardSuccess)
                {
                    StatusText = $"PC/SC 初期化失敗: 0x{result:X8}";
                    await Task.Delay(_settings.PollIntervalMilliseconds, cancellationToken);
                    continue;
                }

                var readerName = FindReaderName(context);
                if (readerName is null)
                {
                    StatusText = "RC-S380 を待機中";
                    await Task.Delay(_settings.PollIntervalMilliseconds, cancellationToken);
                    continue;
                }

                StatusText = $"接続中: {readerName}";
                if (TryReadCardUid(context, readerName, out var cardUid))
                {
                    EmitIfNeeded(cardUid);
                }

                await Task.Delay(_settings.PollIntervalMilliseconds, cancellationToken);
            }
            catch (OperationCanceledException)
            {
                break;
            }
            catch (Exception ex)
            {
                StatusText = $"カード読取エラー: {ex.Message}";
                await Task.Delay(_settings.PollIntervalMilliseconds, cancellationToken);
            }
            finally
            {
                if (context != IntPtr.Zero)
                {
                    NativeMethods.SCardReleaseContext(context);
                }
            }
        }
    }

    private string? FindReaderName(IntPtr context)
    {
        var readers = ListReaders(context);
        if (!string.IsNullOrWhiteSpace(_settings.PreferredReaderName))
        {
            var preferred = readers.FirstOrDefault(x => x.Contains(_settings.PreferredReaderName!, StringComparison.OrdinalIgnoreCase));
            if (preferred is not null)
            {
                return preferred;
            }
        }

        return readers.FirstOrDefault(x =>
            x.Contains("RC-S380", StringComparison.OrdinalIgnoreCase) ||
            x.Contains("SONY", StringComparison.OrdinalIgnoreCase) ||
            x.Contains("PaSoRi", StringComparison.OrdinalIgnoreCase));
    }

    private static string[] ListReaders(IntPtr context)
    {
        uint readersLength = 0;
        var result = NativeMethods.SCardListReaders(context, null, null, ref readersLength);
        if (result != ScardSuccess || readersLength == 0)
        {
            return [];
        }

        var buffer = new StringBuilder((int)readersLength);
        result = NativeMethods.SCardListReaders(context, null, buffer, ref readersLength);
        if (result != ScardSuccess)
        {
            return [];
        }

        return buffer.ToString()
            .Split('\0', StringSplitOptions.RemoveEmptyEntries)
            .ToArray();
    }

    private static bool TryReadCardUid(IntPtr context, string readerName, out string cardUid)
    {
        cardUid = string.Empty;
        IntPtr cardHandle = IntPtr.Zero;
        try
        {
            var result = NativeMethods.SCardConnect(context, readerName, ScardShareShared, ScardProtocolT0 | ScardProtocolT1 | ScardProtocolRaw, out cardHandle, out var activeProtocol);
            if (result != ScardSuccess)
            {
                return false;
            }

            // Inference: RC-S380 is documented by Sony as PC/SC 2.0 compatible for FeliCa.
            // We use the common PC/SC GET DATA APDU (FF CA 00 00 00) to retrieve the card identifier.
            // On FeliCa/RC-S380 this is expected to return the IDm bytes.
            var sendPci = new ScardIoRequest
            {
                Protocol = activeProtocol,
                PciLength = (uint)Marshal.SizeOf<ScardIoRequest>()
            };
            var receiveBuffer = new byte[256];
            var receiveLength = receiveBuffer.Length;

            result = NativeMethods.SCardTransmit(cardHandle, ref sendPci, GetUidCommand, GetUidCommand.Length, IntPtr.Zero, receiveBuffer, ref receiveLength);
            if (result != ScardSuccess || receiveLength < 2)
            {
                return false;
            }

            if (receiveBuffer[receiveLength - 2] != 0x90 || receiveBuffer[receiveLength - 1] != 0x00)
            {
                return false;
            }

            var payloadLength = receiveLength - 2;
            cardUid = Convert.ToHexString(receiveBuffer[..payloadLength]);
            return !string.IsNullOrWhiteSpace(cardUid);
        }
        finally
        {
            if (cardHandle != IntPtr.Zero)
            {
                NativeMethods.SCardDisconnect(cardHandle, 0);
            }
        }
    }

    private void EmitIfNeeded(string cardUid)
    {
        var now = DateTimeOffset.Now;
        if (cardUid == _lastCardUid && (now - _lastSeenAt).TotalMilliseconds < 1500)
        {
            return;
        }

        _lastCardUid = cardUid;
        _lastSeenAt = now;
        StatusText = $"カード検知: {cardUid}";
        CardScanned?.Invoke(this, cardUid);
    }

    private static class NativeMethods
    {
        [DllImport("winscard.dll", CharSet = CharSet.Unicode)]
        public static extern int SCardEstablishContext(uint dwScope, IntPtr notUsed1, IntPtr notUsed2, out IntPtr phContext);

        [DllImport("winscard.dll", CharSet = CharSet.Unicode)]
        public static extern int SCardReleaseContext(IntPtr hContext);

        [DllImport("winscard.dll", CharSet = CharSet.Unicode)]
        public static extern int SCardListReaders(IntPtr hContext, string? mszGroups, StringBuilder? mszReaders, ref uint pcchReaders);

        [DllImport("winscard.dll", CharSet = CharSet.Unicode)]
        public static extern int SCardConnect(IntPtr hContext, string szReader, uint dwShareMode, uint dwPreferredProtocols, out IntPtr phCard, out uint pdwActiveProtocol);

        [DllImport("winscard.dll")]
        public static extern int SCardDisconnect(IntPtr hCard, uint dwDisposition);

        [DllImport("winscard.dll")]
        public static extern int SCardTransmit(
            IntPtr hCard,
            ref ScardIoRequest pioSendPci,
            byte[] pbSendBuffer,
            int cbSendLength,
            IntPtr pioRecvPci,
            [Out] byte[] pbRecvBuffer,
            ref int pcbRecvLength);
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct ScardIoRequest
    {
        public uint Protocol;
        public uint PciLength;
    }
}
