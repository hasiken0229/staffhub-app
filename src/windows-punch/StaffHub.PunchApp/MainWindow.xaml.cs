using System.Windows;
using System.Windows.Media.Animation;
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
        _viewModel.PropertyChanged += (_, args) =>
        {
            if (args.PropertyName == nameof(MainWindowViewModel.ResultPulseKey))
            {
                AnimateResultGlyph();
            }
        };
        Closed += (_, _) => _viewModel.Dispose();
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
