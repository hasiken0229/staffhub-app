using System.IO;

namespace StaffHub.PunchApp.Services;

/// <summary>One normal application per queue path, including separate Windows sessions.</summary>
public sealed class PunchApplicationLease : IDisposable
{
    private readonly FileStream _handle;

    private PunchApplicationLease(FileStream handle) => _handle = handle;

    public static PunchApplicationLease? TryAcquire(string storagePath)
    {
        var path = Path.GetFullPath(storagePath);
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        try
        {
            return new PunchApplicationLease(new FileStream(path + ".instance.lock",
                FileMode.OpenOrCreate, FileAccess.ReadWrite, FileShare.None));
        }
        catch (IOException exception) when (OfflinePunchQueue.IsSharingViolation(exception))
        {
            return null;
        }
    }

    // OS handle release also works after a crash. The empty sidecar is intentionally kept.
    public void Dispose() => _handle.Dispose();
}
