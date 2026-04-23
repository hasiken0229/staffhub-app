using System.Windows;
using System.Windows.Controls;
using System.Windows.Media.Animation;
using StaffHub.PunchApp.ViewModels;

namespace StaffHub.PunchApp;

public partial class MainWindow : Window
{
    private readonly MainWindowViewModel _viewModel;
    private int _titleClickCount;
    private DateTime _lastTitleClickAt = DateTime.MinValue;
    private bool _isMaintenanceVisible;

    public MainWindow()
    {
        InitializeComponent();
        _viewModel = new MainWindowViewModel();
        DataContext = _viewModel;
        SetMaintenanceVisibility(false);

        AppTitleText.MouseLeftButtonUp += (_, _) => HandleTitleClick();
        StartPunchButton.Click += async (_, _) => await _viewModel.RestartReaderAsync();
        SimulateButton.Click += async (_, _) => await _viewModel.SimulateScanAsync();
        RetryButton.Click += async (_, _) => await _viewModel.RetryPendingAsync();
        _viewModel.PropertyChanged += (_, args) =>
        {
            if (args.PropertyName == nameof(MainWindowViewModel.ResultPulseKey))
            {
                AnimateResultGlyph();
            }
        };
        Closed += (_, _) => _viewModel.Dispose();
    }

    private void HandleTitleClick()
    {
        var now = DateTime.Now;
        _titleClickCount = (now - _lastTitleClickAt).TotalSeconds <= 2 ? _titleClickCount + 1 : 1;
        _lastTitleClickAt = now;

        if (_titleClickCount < 3)
        {
            return;
        }

        _titleClickCount = 0;
        SetMaintenanceVisibility(!_isMaintenanceVisible);
    }

    private void SetMaintenanceVisibility(bool isVisible)
    {
        _isMaintenanceVisible = isVisible;
        MaintenancePanel.Visibility = isVisible ? Visibility.Visible : Visibility.Collapsed;
        MaintenanceColumn.Width = isVisible ? new GridLength(1, GridUnitType.Star) : new GridLength(0);
        ResultPanel.Margin = isVisible ? new Thickness(0, 0, 20, 0) : new Thickness(0);
        Grid.SetColumnSpan(ResultPanel, isVisible ? 1 : 2);
    }

    private void AnimateResultGlyph()
    {
        ResultGlyphText.Opacity = 0.25;

        if (ResultGlyphText.RenderTransform is not System.Windows.Media.ScaleTransform scale)
        {
            return;
        }

        var scaleX = new DoubleAnimation(0.72, 1.08, TimeSpan.FromMilliseconds(160))
        {
            AutoReverse = true,
            EasingFunction = new QuadraticEase { EasingMode = EasingMode.EaseOut },
        };
        var scaleY = new DoubleAnimation(0.72, 1.08, TimeSpan.FromMilliseconds(160))
        {
            AutoReverse = true,
            EasingFunction = new QuadraticEase { EasingMode = EasingMode.EaseOut },
        };
        var opacity = new DoubleAnimation(0.25, 1, TimeSpan.FromMilliseconds(180))
        {
            EasingFunction = new QuadraticEase { EasingMode = EasingMode.EaseOut },
        };

        scale.BeginAnimation(System.Windows.Media.ScaleTransform.ScaleXProperty, scaleX);
        scale.BeginAnimation(System.Windows.Media.ScaleTransform.ScaleYProperty, scaleY);
        ResultGlyphText.BeginAnimation(OpacityProperty, opacity);
    }
}
