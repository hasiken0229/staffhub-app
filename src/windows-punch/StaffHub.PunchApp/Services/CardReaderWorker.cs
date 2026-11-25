namespace StaffHub.PunchApp.Services;

public interface IReaderWorkerStatus
{
    bool IsWorkerActive { get; }
    bool NeedsReconnect { get; }
}

/// <summary>Owns worker completion and native failures so UI never stays at initialization forever.</summary>
public sealed class CardReaderWorker : IDisposable, IReaderWorkerStatus
{
    private readonly Func<CancellationToken, Task> _run;
    private readonly Func<string> _status;
    private readonly CancellationTokenSource _cts = new();
    private readonly object _sync = new();
    private Task? _worker;
    private bool _disposed;
    private volatile string? _failure;
    public string? Failure => _failure;
    public bool IsWorkerActive => _worker is { IsCompleted: false };
    public bool NeedsReconnect => _worker is { IsCompleted: true } || _failure is not null;

    public CardReaderWorker(Func<CancellationToken, Task> run, Func<string> status) { _run = run; _status = status; }

    public void Start()
    {
        lock (_sync)
        {
            ObjectDisposedException.ThrowIf(_disposed, this);
            if (IsWorkerActive) return;
            _failure = null;
            _worker = Task.Run(async () =>
            {
                try
                {
                    await _run(_cts.Token);
                    if (!_cts.IsCancellationRequested) _failure = $"読取停止: {_status()}。再接続してください";
                }
                catch (OperationCanceledException) when (_cts.IsCancellationRequested) { }
                catch (Exception error)
                {
                    _failure = error switch
                    {
                        DllNotFoundException => "読取初期化エラー: DLLまたは依存DLLがありません。管理者に確認してください",
                        BadImageFormatException => "読取初期化エラー: DLLの形式・32/64bitが一致しません。管理者に確認してください",
                        EntryPointNotFoundException => "読取初期化エラー: DLLの必要な関数がありません。管理者に確認してください",
                        _ => $"カード読取エラー: {error.GetType().Name}。再接続してください",
                    };
                }
                finally { if (_cts.IsCancellationRequested) _failure = "カード読取を停止しました"; }
            });
        }
    }

    public void Dispose()
    {
        lock (_sync)
        {
            if (_disposed) return;
            _disposed = true;
            _cts.Cancel();
        }
        var stopped = _worker is null || _worker.Wait(TimeSpan.FromSeconds(3));
        if (stopped) _cts.Dispose();
        else
        {
            _failure = "カード読取の停止待ちです。二重起動せず、管理者に確認してください";
            _ = _worker!.ContinueWith(_ => _cts.Dispose(), TaskScheduler.Default);
        }
    }
}
