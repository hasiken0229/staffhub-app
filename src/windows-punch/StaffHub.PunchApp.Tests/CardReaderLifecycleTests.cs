using StaffHub.PunchApp.Models;
using StaffHub.PunchApp.Services;
using Xunit;

namespace StaffHub.PunchApp.Tests;

public sealed class CardReaderLifecycleTests
{
    private static AppSettings Settings(string mode) => new("http://127.0.0.1:1","synthetic","synthetic","test",false,false,mode,null,10);
    private sealed class Reader(string mode) : ICardReaderService
    {
        public string StatusText => mode;
        public event EventHandler<string>? CardScanned {add{}remove{}}
        public void Start(){}public void Dispose(){}
    }

    [Theory]
    [InlineData("MOCK",true,"MOCK")]
    [InlineData("PCSC",true,"PCSC")]
    [InlineData("FELICA",false,"FELICA")]
    [InlineData("AUTO",true,"FELICA")]
    [InlineData("AUTO",false,"PCSC")]
    [InlineData("RC_S380",true,"FELICA")]
    [InlineData("RC_S380",false,"PCSC")]
    [InlineData(" pcsc ",true,"PCSC")]
    public void FactoryHonorsExplicitModeAndLegacyAutomaticCompatibility(string mode,bool available,string expected)
    {
        var nativeCalls=0;
        using var reader=CardReaderFactory.Create(Settings(mode),()=>available,
            _=>{nativeCalls++;return new Reader("PCSC");},_=>{nativeCalls++;return new Reader("FELICA");});
        if(expected=="MOCK"){Assert.IsType<MockCardReaderService>(reader);Assert.Equal(0,nativeCalls);}
        else Assert.Equal(expected,reader.StatusText);
    }

    [Fact]
    public void UnknownModeNeverStartsANativeReader()
    {
        using var reader=CardReaderFactory.Create(Settings("invalid"),()=>throw new Exception("must not probe"),
            _=>throw new Exception("must not create"),_=>throw new Exception("must not create"));
        reader.Start();Assert.Contains("設定エラー",reader.StatusText);
    }

    [Theory]
    [InlineData("missing")]
    [InlineData("bitness")]
    [InlineData("entry")]
    [InlineData("false")]
    public async Task FelicaInitializationFailuresBecomeVisibleAndReconnectableWithoutNativeCalls(string failure)
    {
        using var reader=new FelicaLibraryCardReaderService(Settings("FELICA"),()=>failure switch
        {
            "missing"=>throw new DllNotFoundException(),"bitness"=>throw new BadImageFormatException(),
            "entry"=>throw new EntryPointNotFoundException(),_=>false,
        });
        reader.Start();await WaitUntil(()=>!reader.IsWorkerActive);
        Assert.True(reader.NeedsReconnect);Assert.DoesNotContain("初期化待ち",reader.StatusText);
        Assert.True(reader.StatusText.Contains("エラー")||reader.StatusText.Contains("失敗"));
        reader.Start();await WaitUntil(()=>!reader.IsWorkerActive);Assert.True(reader.NeedsReconnect);
    }

    [Fact]
    public async Task UnexpectedWorkerReturnIsStoppedAndAReplacementWorkerCanStart()
    {
        using var ended=new RcS380CardReaderService(Settings("PCSC"),_=>Task.CompletedTask);
        ended.Start();await WaitUntil(()=>!ended.IsWorkerActive);
        Assert.True(ended.NeedsReconnect);Assert.Contains("読取停止",ended.StatusText);
        using var replacement=new RcS380CardReaderService(Settings("PCSC"),token=>Task.Delay(Timeout.Infinite,token));
        replacement.Start();await WaitUntil(()=>replacement.IsWorkerActive);
        replacement.Dispose();Assert.False(replacement.IsWorkerActive);Assert.Contains("停止",replacement.StatusText);
    }

    [Fact]
    public async Task StopTimeoutDoesNotPermitASecondNativeWorkerWhileTheOldOneIsActive()
    {
        var release=new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        using var reader=new RcS380CardReaderService(Settings("PCSC"),_=>release.Task);
        reader.Start();await WaitUntil(()=>reader.IsWorkerActive);reader.Dispose();
        Assert.True(reader.IsWorkerActive);Assert.Contains("停止待ち",reader.StatusText);
        release.SetResult();await WaitUntil(()=>!reader.IsWorkerActive);
    }

    [Fact]
    public async Task FaultedSyntheticWorkerCanRestartWithoutKeepingTheOldFailureState()
    {
        var starts=0;
        using var worker=new CardReaderWorker(token=>Interlocked.Increment(ref starts)==1
            ? Task.FromException(new InvalidOperationException()) : Task.Delay(Timeout.Infinite,token),()=>"合成読取");
        worker.Start();await WaitUntil(()=>!worker.IsWorkerActive);Assert.Contains("エラー",worker.Failure);
        worker.Start();await WaitUntil(()=>starts==2);worker.Start();
        Assert.True(worker.IsWorkerActive);Assert.False(worker.NeedsReconnect);Assert.Null(worker.Failure);Assert.Equal(2,starts);
        worker.Dispose();Assert.False(worker.IsWorkerActive);
    }

    private static async Task WaitUntil(Func<bool> condition)
    {
        using var timeout=new CancellationTokenSource(TimeSpan.FromSeconds(5));
        while(!condition())await Task.Delay(10,timeout.Token);
    }
}
