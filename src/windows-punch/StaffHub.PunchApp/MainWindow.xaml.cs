using System.Windows;
using StaffHub.PunchApp.ViewModels;

namespace StaffHub.PunchApp;

public partial class MainWindow : Window
{
    private readonly MainWindowViewModel _viewModel;

    public MainWindow()
    {
        InitializeComponent();
        _viewModel = new MainWindowViewModel();
        DataContext = _viewModel;

        StartPunchButton.Click += async (_, _) => await _viewModel.RestartReaderAsync();
        SimulateButton.Click += async (_, _) => await _viewModel.SimulateScanAsync();
        RetryButton.Click += async (_, _) => await _viewModel.RetryPendingAsync();
        Closed += (_, _) => _viewModel.Dispose();
    }
}
