using System.Windows;
using System.IO;
using StaffHub.PunchApp.Services;

namespace StaffHub.PunchApp;

public partial class App : Application
{
    private PunchApplicationLease? _applicationLease;

    protected override async void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);
        if (e.Args.Contains("--diagnostics", StringComparer.OrdinalIgnoreCase))
        {
            try
            {
                var outputIndex = Array.IndexOf(e.Args, "--output");
                var output = outputIndex >= 0 && outputIndex + 1 < e.Args.Length ? e.Args[outputIndex + 1]
                    : Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "StaffHub", "PunchApp", "Diagnostics");
                var path = await PunchDiagnostics.SaveAsync(Path.Combine(AppContext.BaseDirectory, "punchsettings.json"),
                    Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "StaffHub", "PunchApp", "pending-punches.json"), output);
                if (outputIndex < 0) MessageBox.Show($"診断結果を保存しました。打刻・再送は実行していません。\n{path}", "打刻端末の診断");
                Shutdown(0);
            }
            catch { MessageBox.Show("診断結果を保存できませんでした。保存先を確認してください。", "打刻端末の診断"); Shutdown(1); }
            return;
        }
        try
        {
            _applicationLease = PunchApplicationLease.TryAcquire(OfflinePunchQueue.DefaultStoragePath());
            if (_applicationLease is null)
            {
                MessageBox.Show("打刻アプリは既に起動しています。開いている打刻画面を使用してください。",
                    "打刻アプリの起動");
                Shutdown(0);
                return;
            }
            ShutdownMode = ShutdownMode.OnMainWindowClose;
            MainWindow = new MainWindow();
            MainWindow.Show();
        }
        catch (Exception exception) when (exception is IOException or InvalidDataException or UnauthorizedAccessException)
        {
            MessageBox.Show("未送信打刻の保存先を確認できないため、打刻の受付を開始していません。\n"
                + "未送信ファイルを削除・移動せず、管理者へ連絡してください。\n"
                + "ファイルが使用中の場合は、原因を確認してから再起動してください。",
                "未送信打刻の保護", MessageBoxButton.OK, MessageBoxImage.Error);
            Shutdown(1);
        }
    }

    protected override void OnExit(ExitEventArgs e)
    {
        _applicationLease?.Dispose();
        base.OnExit(e);
    }
}
