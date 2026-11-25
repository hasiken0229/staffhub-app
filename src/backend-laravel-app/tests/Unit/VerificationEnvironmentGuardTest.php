<?php
namespace Tests\Unit;
use App\Support\VerificationEnvironmentGuard;
use PHPUnit\Framework\TestCase;
final class VerificationEnvironmentGuardTest extends TestCase
{
    public function test_rejects_production_remote_storage_database_and_notifications_before_any_connection(): void
    {
        $root=sys_get_temp_dir().'/staffhub-guard-'.bin2hex(random_bytes(5));mkdir($root);mkdir($root.'/storage');
        file_put_contents($root.'/.staffhub-verification','1');file_put_contents($root.'/test.sqlite','');
        $safe=['environment'=>'testing','url'=>'http://127.0.0.1:8130','root'=>$root,'storage'=>$root.'/storage',
            'chatEnabled'=>false,'chatCredentials'=>'','mailer'=>'array','driver'=>'mysql',
            'database'=>['host'=>'127.0.0.1','database'=>'staffhub_verification_e2e']];
        try {
            VerificationEnvironmentGuard::assertSafe($safe); $this->addToAssertionCount(1);
            foreach ([['environment'=>'production'],['url'=>'https://attendance.example.org/dakoku'],['storage'=>dirname($root)],
                ['database'=>['host'=>'remote.example','database'=>'staffhub_verification_e2e']],
                ['database'=>['host'=>'127.0.0.1','database'=>'staffhub_production']],['chatEnabled'=>true],['chatCredentials'=>'live.json'],['mailer'=>'smtp']] as $unsafe) {
                try {VerificationEnvironmentGuard::assertSafe(array_replace($safe,$unsafe));$this->fail('Unsafe environment accepted');}
                catch(\RuntimeException $error){$this->assertStringContainsString('結合テストは停止',$error->getMessage());}
            }
        } finally { unlink($root.'/.staffhub-verification');unlink($root.'/test.sqlite');rmdir($root.'/storage');rmdir($root); }
    }
}
