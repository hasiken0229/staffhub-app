<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdminInitialPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'first-admin@example.test';
    private const INITIAL = 'Temporary-for-tests';
    private const NEW_PASSWORD = ' New permanent password ';

    private function admin(bool $change = true): int
    {
        return DB::table('users')->insertGetId(['name'=>'架空管理者','email'=>self::EMAIL,'password'=>Hash::make(self::INITIAL),'must_change_password'=>$change]);
    }

    private function employee(): int
    {
        return DB::table('employees')->insertGetId(['name'=>'確認　職員','employee_code'=>'TST-INITIAL','status'=>'ACTIVE','employment_type'=>'FULL_TIME','joined_on'=>'2020-01-01','created_at'=>now(),'updated_at'=>now()]);
    }

    private function login(string $password = self::INITIAL, string $audience = 'AUTO')
    {
        return $this->postJson('/api/auth/login', ['loginId'=>self::EMAIL,'password'=>$password,'audience'=>$audience]);
    }

    private function challenge(): string
    {
        $result = $this->login()->assertOk()->assertJsonPath('data.requiresPasswordChange', true)
            ->assertJsonPath('data.expiresInSeconds', 600)->assertJsonMissingPath('data.accessToken')
            ->assertJsonMissingPath('data.refreshToken')->assertJsonMissingPath('data.user');
        $this->assertStringContainsString('no-store', $result->headers->get('Cache-Control'));
        return $result->json('data.passwordChangeToken');
    }

    private function change(string $token, string $password = self::NEW_PASSWORD, ?string $confirmation = null)
    {
        return $this->postJson('/api/auth/password/initial', ['passwordChangeToken'=>$token,'password'=>$password,'passwordConfirmation'=>$confirmation ?? $password]);
    }

    public function test_pending_admin_gets_only_a_hashed_single_purpose_challenge(): void
    {
        $id = $this->admin();
        $this->login('wrong password')->assertUnauthorized();
        $token = $this->challenge();
        $this->assertDatabaseCount('api_access_tokens', 1);
        $this->assertDatabaseHas('api_access_tokens', ['tokenable_id'=>$id,'token_kind'=>'INITIAL_PASSWORD','token_hash'=>hash('sha256',$token)]);
        $this->withToken($token)->getJson('/api/admin/employees')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/refresh', ['refreshToken'=>$token])->assertUnauthorized();
        $this->assertDatabaseCount('api_access_tokens', 1);
        $this->assertDatabaseHas('users',['id'=>$id, 'must_change_password'=>true]);
    }

    public function test_only_latest_unexpired_challenge_can_change_password_and_issue_a_normal_session_once(): void
    {
        $id=$this->admin();
        $employee=$this->employee();
        DB::table('users')->where('id',$id)->update(['employee_id'=>$employee]);
        $old=$this->challenge();
        $current=$this->challenge();
        $this->change($old)->assertUnauthorized()->assertJsonPath('error.code','PASSWORD_CHANGE_EXPIRED');
        $this->change($current,'short')->assertUnprocessable()->assertJsonPath('error.details.0.field','password');
        $this->change($current,self::INITIAL)->assertUnprocessable()->assertJsonPath('error.details.0.field','password');
        $this->change($current,self::NEW_PASSWORD,'incorrect confirmation')->assertUnprocessable()->assertJsonPath('error.details.0.field','passwordConfirmation');
        $this->change($current,str_repeat('長',25))->assertUnprocessable();
        $this->assertDatabaseHas('users',['id'=>$id,'must_change_password'=>true]);

        $session=$this->change($current)->assertOk()->assertJsonPath('data.user.role','ADMIN')
            ->assertJsonPath('data.user.employeeId',$employee)->assertJsonPath('data.user.canUseEmployeePortal',true);
        $this->assertDatabaseHas('users',['id'=>$id,'must_change_password'=>false]);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, DB::table('users')->where('id',$id)->value('password')));
        $this->withToken($session->json('data.accessToken'))->getJson('/api/admin/employees')->assertOk();
        $this->change($current)->assertUnauthorized();
        $this->change($session->json('data.accessToken'))->assertUnauthorized();
        $this->change($session->json('data.refreshToken'))->assertUnauthorized();
        $this->login()->assertUnauthorized();
        $this->login(self::NEW_PASSWORD,'ADMIN')->assertOk()->assertJsonStructure(['data'=>['accessToken','refreshToken','user']]);
    }

    public function test_expired_challenge_and_previously_issued_access_or_refresh_are_refused_while_pending(): void
    {
        $id=$this->admin(false);
        $old=$this->login()->assertOk()->assertJsonMissingPath('data.passwordChangeToken');
        DB::table('users')->where('id',$id)->update(['must_change_password'=>true]);
        $this->postJson('/api/auth/refresh',['refreshToken'=>$old->json('data.refreshToken')])->assertUnauthorized();
        $this->withToken($old->json('data.accessToken'))->getJson('/api/admin/employees')->assertUnauthorized();
        $token=$this->challenge();
        $this->travel(10)->minutes();
        $this->change($token)->assertUnauthorized();
        $this->assertDatabaseHas('users',['id'=>$id,'must_change_password'=>true]);
        $this->assertTrue(Hash::check(self::INITIAL, DB::table('users')->where('id',$id)->value('password')));
    }

    public function test_database_error_during_session_issue_rolls_back_password_flag_and_challenge(): void
    {
        $id=$this->admin();
        $token=$this->challenge();
        $failOnce=true;
        DB::connection()->beforeExecuting(static function ($sql,$bindings) use (&$failOnce) {
            if ($failOnce && str_starts_with(strtolower($sql),'insert into') && str_contains($sql,'api_access_tokens') && in_array('ACCESS',$bindings,true)) {
                $failOnce=false;
                throw new \RuntimeException('Synthetic unavailable token store');
            }
        });
        $this->change($token)->assertInternalServerError();
        $this->assertDatabaseHas('users',['id'=>$id,'must_change_password'=>true]);
        $this->assertDatabaseHas('api_access_tokens',['token_kind'=>'INITIAL_PASSWORD','token_hash'=>hash('sha256',$token)]);
        $this->assertTrue(Hash::check(self::INITIAL, DB::table('users')->where('id',$id)->value('password')));
        $this->change($token)->assertOk();
    }

    public function test_provisioning_links_only_requested_employee_and_leaves_existing_admin_untouched(): void
    {
        $oldId=$this->admin(false);
        $old=DB::table('users')->where('id',$oldId)->first();
        $employee=$this->employee();
        $args=['email'=>'new-admin@example.test','employeeName'=>'確認 職員'];
        $this->artisan('staffhub:admin:add',$args)->expectsQuestion('仮パスワード（8文字以上）','short')->assertExitCode(2);
        $this->assertDatabaseCount('users',1);
        $this->artisan('staffhub:admin:add',$args)->expectsQuestion('仮パスワード（8文字以上）',self::INITIAL)->assertExitCode(0);
        $this->assertDatabaseHas('users',['email'=>'new-admin@example.test','employee_id'=>$employee,'must_change_password'=>true]);
        $this->artisan('staffhub:admin:add',['email'=>'other@example.test','employeeName'=>'確認職員'])
            ->expectsQuestion('仮パスワード（8文字以上）',self::INITIAL)->assertExitCode(1);
        $this->assertDatabaseCount('users',2);
        $this->assertEquals($old,DB::table('users')->where('id',$oldId)->first());
        $this->login()->assertOk()->assertJsonStructure(['data'=>['accessToken','refreshToken','user']])->assertJsonMissingPath('data.passwordChangeToken');
    }

    public function test_provisioning_refuses_email_already_used_by_an_employee_or_admin_and_ambiguous_name(): void
    {
        $this->admin(false);
        $employee=$this->employee();
        DB::table('employee_auth')->insert(['employee_id'=>$employee,'login_id'=>'employee@example.test','password_hash'=>Hash::make(self::INITIAL),'created_at'=>now(),'updated_at'=>now()]);
        foreach([self::EMAIL,'EMPLOYEE@EXAMPLE.TEST'] as $email){
            $this->artisan('staffhub:admin:add',['email'=>$email,'employeeName'=>'確認職員'])->expectsQuestion('仮パスワード（8文字以上）',self::INITIAL)->assertExitCode(1);
        }
        DB::table('employees')->insert(['name'=>'確認 職員','employee_code'=>'TST-DUP','status'=>'ACTIVE','employment_type'=>'FULL_TIME','joined_on'=>'2020-01-01']);
        $this->artisan('staffhub:admin:add',['email'=>'unused@example.test','employeeName'=>'確認職員'])->expectsQuestion('仮パスワード（8文字以上）',self::INITIAL)->assertExitCode(1);
        $this->assertDatabaseCount('users',1);
        $this->postJson('/api/auth/login',['loginId'=>'employee@example.test','password'=>self::INITIAL,'audience'=>'EMPLOYEE'])
            ->assertOk()->assertJsonPath('data.user.role','EMPLOYEE')->assertJsonMissingPath('data.passwordChangeToken');
    }
}
