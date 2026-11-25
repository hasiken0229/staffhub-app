<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class AddLinkedAdministrator extends Command
{
    protected $signature = 'staffhub:admin:add {email} {employeeName} {--verified-hash-stdin : 8文字以上を検証済みのbcryptコスト12ハッシュを標準入力から受け取る}';
    protected $description = '在職中の職員1名に紐づく、初回パスワード変更必須の管理者を追加する';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = preg_replace('/[ 　]+/u', '', (string) $this->argument('employeeName'));
        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            $this->error('職員氏名と有効なメールアドレスを指定してください。');
            return self::INVALID;
        }
        if ($this->option('verified-hash-stdin')) {
            $hash = rtrim((string) fgets(STDIN), "\r\n");
            if (!preg_match('~^\$2[by]\$12\$[./A-Za-z0-9]{53}$~D', $hash)) {
                $this->error('検証済みハッシュの形式またはコストが正しくありません。');
                return self::INVALID;
            }
            // PHP's algorithm metadata recognizes its own $2y$ spelling of modern bcrypt.
            $hash = '$2y$'.substr($hash, 4);
        } else {
            $password = (string) $this->secret('仮パスワード（8文字以上）');
            if (mb_strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
                $this->error('bcryptで扱える8文字以上・72バイト以内の仮パスワードを入力してください。');
                return self::INVALID;
            }
            $hash = Hash::make($password);
        }

        try {
            return DB::transaction(fn () => $this->createLinked($email, $name, $hash), 3);
        } catch (QueryException) {
            $this->error('データベースへの登録が完了しませんでした。既存のメール・職員の紐付けを確認してください。');
            return self::FAILURE;
        }
    }

    private function createLinked(string $email, string $name, #[\SensitiveParameter] string $hash): int
    {
            $people = DB::table('employees')->whereRaw("REPLACE(REPLACE(name, ' ', ''), '　', '') = ?", [$name])
                ->pluck('id');
            $person = $people->count() === 1 ? DB::table('employees')->where('id', $people->first())->lockForUpdate()->first(['id','name','status','joined_on','retired_on']) : null;
            $today = now()->toDateString();
            if (!$person || preg_replace('/[ 　]+/u', '', $person->name) !== $name || $person->status !== 'ACTIVE' || $person->joined_on > $today || ($person->retired_on && $person->retired_on < $today)) {
                $this->error('指定氏名で在職中の職員を一意に確認できません。');
                return self::FAILURE;
            }
            if (DB::table('users')->whereRaw('LOWER(TRIM(email)) = ?', [$email])->orWhere('employee_id', $person->id)->exists()
                || DB::table('employee_auth')->whereRaw('LOWER(TRIM(login_id)) = ?', [$email])->exists()) {
                $this->error('そのメールまたは職員にはすでにアカウントが設定されています。');
                return self::FAILURE;
            }
            DB::table('users')->insert([
                'name' => $person->name, 'email' => $email, 'employee_id' => $person->id,
                'password' => $hash, 'must_change_password' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->info('本人に紐づく管理者を1件追加しました。初回ログイン時にパスワード変更が必要です。');
            return self::SUCCESS;
    }
}
