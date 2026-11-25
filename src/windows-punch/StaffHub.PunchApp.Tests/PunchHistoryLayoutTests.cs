using System.IO;
using System.Runtime.ExceptionServices;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Markup;
using System.Xml.Linq;
using Xunit;

namespace StaffHub.PunchApp.Tests;

public class PunchHistoryLayoutTests
{
    [Theory]
    [InlineData(980, 620, false)]
    [InlineData(980, 620, true)]
    [InlineData(1200, 720, false)]
    [InlineData(1200, 720, true)]
    public void TenthReceiptCanBeReached(int width, int height, bool maintenance)
    {
        Exception? failure = null;
        var thread = new Thread(() =>
        {
            try
            {
                CheckHistory(width, height, maintenance);
            }
            catch (Exception error)
            {
                failure = error;
            }
        });
        thread.SetApartmentState(ApartmentState.STA);
        thread.Start();
        Assert.True(thread.Join(TimeSpan.FromSeconds(15)));
        if (failure != null) ExceptionDispatchInfo.Capture(failure).Throw();
    }

    private static void CheckHistory(int width, int height, bool maintenance)
    {
        // Load the real layout without starting the device, network or queue services.
        var source = XDocument.Load(Path.Combine(AppContext.BaseDirectory, "Fixtures", "MainWindow.xaml"));
        source.Root!.Attribute(XName.Get("Class", "http://schemas.microsoft.com/winfx/2006/xaml"))!.Remove();
        var window = (Window)XamlReader.Parse(source.ToString());
        var history = (ScrollViewer)window.FindName("PunchHistoryScroll");
        var panel = (Border)window.FindName("MaintenancePanel");
        panel.Visibility = maintenance ? Visibility.Visible : Visibility.Collapsed;
        ((RowDefinition)window.FindName("MaintenanceRow")).Height = new GridLength(maintenance ? 260 : 0);
        var layout = (FrameworkElement)window.Content;
        layout.DataContext = new
        {
            PunchHistory = Enumerable.Range(1, 10).Select(i => new
            {
                EmployeeName = "職員" + i, TimeText = "09:00", EventTypeText = "出勤",
                StatusText = "受付", ResultText = "確認済み",
            }).ToArray(),
        };
        void Layout()
        {
            layout.Measure(new Size(width, height));
            layout.Arrange(new Rect(0, 0, width, height));
            layout.UpdateLayout();
        }
        Layout();
        Assert.True(history.Focusable && history.IsTabStop);
        Assert.True(history.ScrollableHeight > 0 && history.ViewportHeight > 0);
        history.ScrollToEnd();
        Layout();
        var receipts = (ItemsControl)history.Content;
        var last = (FrameworkElement)receipts.ItemContainerGenerator.ContainerFromIndex(9);
        var bounds = last.TransformToAncestor(history).TransformBounds(new Rect(last.RenderSize));
        Assert.InRange(history.VerticalOffset, history.ScrollableHeight - 1, history.ScrollableHeight + 1);
        Assert.InRange(bounds.Bottom, 0, history.ActualHeight + 1);
        window.Close();
    }
}
