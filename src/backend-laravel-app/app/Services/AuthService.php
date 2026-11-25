<?php

namespace App\Services;

use App\Mail\SystemNotificationMail;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class AuthService
{
    private const ACCESS_MINUTES = 8 * 60;

    private const REFRESH_DAYS = 30;
    private const INITIAL_CHANGE_MINUTES = 10;
    private const INITIAL_CHANGE_KIND = 'INITIAL_PASSWORD';

    public function login(array $payload): array
    {
        $audience = strtoupper(trim((string) ($payload['audience'] ?? 'AUTO')));
        $loginId = trim((string) $payload['loginId']);
        $password = (string) $payload['password'];

        if (! in_array($audience, ['AUTO', 'EMPLOYEE', 'ADMIN'], true)) {
            throw new ApiException('VALIDATION_ERROR', 'audience は AUTO / EMPLOYEE / ADMIN を指定してください。', 422, [
                ['field' => 'audience', 'message' => 'EMPLOYEE または ADMIN を指定してください。'],
            ]);
        }

        return DB::transaction(function () use ($audience, $loginId, $password) {
            $identity = match ($audience) {
                'ADMIN' => $this->resolveAdmin($loginId, $password),
                'EMPLOYEE' => $this->resolveEmployee($loginId, $password),
                default => $this->resolveAuto($loginId, $password),
            };

            if ($identity['mustChangePassword'] ?? false) {
                return $this->issueInitialPasswordChallenge($identity['tokenableId']);
            }

            return $this->issueTokenPair(
                $identity['tokenableType'],
                $identity['tokenableId'],
                $identity['role'],
                $identity['user'],
                $identity['userPayload']
            );
        });
    }

    private function issueInitialPasswordChallenge(int $adminId): array
    {
        // Login holds this user's row lock. A later login replaces the earlier challenge.
        DB::table('api_access_tokens')->where('tokenable_type', 'ADMIN')->where('tokenable_id', $adminId)->delete();
        $plain = $this->generateToken('ipc');
        $now = CarbonImmutable::now();
        DB::table('api_access_tokens')->insert([
            'tokenable_type' => 'ADMIN', 'tokenable_id' => $adminId, 'role' => 'ADMIN',
            'token_kind' => self::INITIAL_CHANGE_KIND, 'token_hash' => hash('sha256', $plain),
            'pair_key' => (string) Str::uuid(), 'expires_at' => $now->addMinutes(self::INITIAL_CHANGE_MINUTES),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return ['requiresPasswordChange' => true, 'passwordChangeToken' => $plain, 'expiresInSeconds' => self::INITIAL_CHANGE_MINUTES * 60];
    }

    public function completeInitialPasswordChange(string $plain, string $password): array
    {
        $expired = static fn () => new ApiException('PASSWORD_CHANGE_EXPIRED', '初回変更の期限が切れたか、すでに使用されています。もう一度ログインしてください。', 401);
        $challenge = DB::table('api_access_tokens')->where('token_hash', hash('sha256', $plain))
            ->where('tokenable_type', 'ADMIN')->where('token_kind', self::INITIAL_CHANGE_KIND);
        $adminId = (clone $challenge)->value('tokenable_id');
        if ($adminId === null) throw $expired();

        return DB::transaction(function () use ($adminId, $challenge, $password, $expired) {
            // Lock the user before their challenge, in the same order as login.
            $admin = DB::table('users')->where('id', $adminId)->lockForUpdate()->first();
            $token = $challenge->where('tokenable_id', $adminId)->lockForUpdate()->first();
            if (!$admin || !$admin->must_change_password || !$token || CarbonImmutable::parse($token->expires_at)->lessThanOrEqualTo(now())) {
                throw $expired();
            }
            if (str_contains($password, "\0") || (config('hashing.driver', 'bcrypt') === 'bcrypt' && strlen($password) > 72)) {
                throw new ApiException('VALIDATION_ERROR', 'パスワードは72バイト以内で指定してください。', 422, [['field' => 'password', 'message' => '使用できない文字または長すぎるパスワードです。']]);
            }
            if (Hash::check($password, $admin->password)) {
                throw new ApiException('VALIDATION_ERROR', '仮パスワードと異なるパスワードを入力してください。', 422, [
                    ['field' => 'password', 'message' => '仮パスワードと異なるパスワードを入力してください。'],
                ]);
            }
            DB::table('users')->where('id', $adminId)->update([
                'password' => Hash::make($password), 'must_change_password' => false, 'updated_at' => now(),
            ]);
            DB::table('api_access_tokens')->where('tokenable_type', 'ADMIN')->where('tokenable_id', $adminId)->delete();
            $user = $this->resolvePrincipal('ADMIN', (int) $adminId);
            return $this->issueTokenPair('ADMIN', (int) $adminId, 'ADMIN', $user, $this->serializeUser($user));
        }, 3);
    }

    public function refresh(string $refreshToken): array
    {
        return DB::transaction(function () use ($refreshToken) {
            // Read the single-use token only while holding its row lock. A second
            // connection must see the first rotation's deletion before issuing a pair.
            $token = DB::table('api_access_tokens')
                ->where('token_hash', hash('sha256', trim($refreshToken)))
                ->where('token_kind', 'REFRESH')
                ->lockForUpdate()
                ->first();
            if ($token === null || CarbonImmutable::parse($token->expires_at)->lessThanOrEqualTo(now())) {
                throw new ApiException('UNAUTHORIZED', 'リフレッシュトークンが無効です。', 401);
            }

            $user = $this->resolvePrincipal((string) $token->tokenable_type, (int) $token->tokenable_id);
            if ($user === null) {
                DB::table('api_access_tokens')
                    ->where('pair_key', $token->pair_key)
                    ->delete();

                throw new ApiException('UNAUTHORIZED', '利用者が無効です。ログインし直してください。', 401);
            }

            DB::table('api_access_tokens')
                ->where('pair_key', $token->pair_key)
                ->delete();

            return $this->issueTokenPair(
                (string) $token->tokenable_type,
                (int) $token->tokenable_id,
                (string) $token->role,
                $user,
                $this->serializeUser($user)
            );
        }, 3);
    }

    public function logout(?string $accessToken): void
    {
        if ($accessToken === null || trim($accessToken) === '') {
            return;
        }

        $token = $this->findToken($accessToken, 'ACCESS');
        if ($token === null) {
            return;
        }

        DB::table('api_access_tokens')
            ->where('pair_key', $token->pair_key)
            ->delete();
    }

    public function resolveAccessUser(?string $plainToken): ?GenericUser
    {
        if ($plainToken === null || trim($plainToken) === '') {
            return null;
        }

        $token = $this->findToken($plainToken, 'ACCESS');
        if ($token === null) {
            return null;
        }

        $user = $this->resolvePrincipal((string) $token->tokenable_type, (int) $token->tokenable_id);
        if ($user === null) {
            DB::table('api_access_tokens')
                ->where('pair_key', $token->pair_key)
                ->delete();

            return null;
        }

        DB::table('api_access_tokens')
            ->where('id', $token->id)
            ->update([
                'last_used_at' => now(),
                'updated_at' => now(),
            ]);

        return $user;
    }

    public function me(GenericUser $user): array
    {
        return $this->serializeUser($user);
    }

    public function requestEmployeePasswordReset(string $email): void
    {
        $email = $this->normalizeEmail($email);
        $employee = $this->findActiveEmployeeByEmail($email);
        if ($employee === null) {
            return;
        }

        $token = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ],
        );

        $resetUrl = $this->buildEmployeePasswordResetUrl($email, $token);
        Mail::to($email)->send(new SystemNotificationMail(
            '勤怠管理システム パスワード再設定',
            'パスワード再設定のご案内',
            [
                '以下のURLから新しいパスワードを設定してください。',
                $resetUrl,
                'このURLは60分以内に使用してください。',
                '心当たりがない場合は、このメールを破棄してください。',
            ],
            [
                '対象職員' => trim((string) $employee->employee_code.' '.(string) $employee->name),
            ],
        ));
    }

    public function resetEmployeePassword(string $email, string $token, string $password): void
    {
        $email = $this->normalizeEmail($email);
        $resetToken = DB::table('password_reset_tokens')->where('email', $email)->first();
        if (
            $resetToken === null ||
            ! Hash::check($token, (string) $resetToken->token) ||
            CarbonImmutable::parse((string) $resetToken->created_at)->lt(CarbonImmutable::now()->subMinutes(60))
        ) {
            throw new ApiException('VALIDATION_ERROR', '再設定URLが無効または期限切れです。', 422, [
                ['field' => 'token', 'message' => '再設定URLが無効または期限切れです。'],
            ]);
        }

        $employee = $this->findActiveEmployeeByEmail($email);
        if ($employee === null) {
            throw new ApiException('VALIDATION_ERROR', '再設定URLが無効または期限切れです。', 422, [
                ['field' => 'email', 'message' => '再設定URLが無効または期限切れです。'],
            ]);
        }

        DB::transaction(function () use ($email, $password, $employee): void {
            DB::table('employee_auth')
                ->where('employee_id', $employee->id)
                ->update([
                    'password_hash' => Hash::make($password),
                    'password_updated_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('password_reset_tokens')->where('email', $email)->delete();
            DB::table('api_access_tokens')
                ->where('tokenable_type', 'EMPLOYEE')
                ->where('tokenable_id', $employee->id)
                ->delete();
        });
    }

    private function resolveAdmin(string $loginId, string $password): array
    {
        $admin = DB::table('users')
            ->leftJoin('employees as linked_employee', 'linked_employee.id', '=', 'users.employee_id')
            ->select([
                'users.*',
                'linked_employee.id as linked_employee_id',
                'linked_employee.employee_code as linked_employee_code',
                'linked_employee.name as linked_employee_name',
                'linked_employee.status as linked_employee_status',
            ])
            ->where('email', $loginId)
            ->lockForUpdate()
            ->first();

        if ($admin === null || ! Hash::check($password, (string) $admin->password)) {
            throw new ApiException('UNAUTHORIZED', 'ログインIDまたはパスワードが正しくありません。', 401);
        }

        $linkedEmployeeId = $this->activeLinkedEmployeeId($admin);
        $linkedEmployeeCode = $linkedEmployeeId !== null ? (string) $admin->linked_employee_code : null;

        return [
            'tokenableType' => 'ADMIN',
            'tokenableId' => (int) $admin->id,
            'mustChangePassword' => (bool) $admin->must_change_password,
            'role' => 'ADMIN',
            'user' => new GenericUser([
                'id' => (int) $admin->id,
                'role' => 'ADMIN',
                'name' => $admin->name,
                'email' => $admin->email,
                'is_admin' => true,
                'employeeId' => $linkedEmployeeId,
                'employeeCode' => $linkedEmployeeCode,
                'canUseEmployeePortal' => $linkedEmployeeId !== null,
            ]),
            'userPayload' => [
                'id' => (int) $admin->id,
                'role' => 'ADMIN',
                'name' => $admin->name,
                'email' => $admin->email,
                'isAdmin' => true,
                'employeeId' => $linkedEmployeeId,
                'employeeCode' => $linkedEmployeeCode,
                'canUseEmployeePortal' => $linkedEmployeeId !== null,
            ],
        ];
    }

    private function resolveEmployee(string $loginId, string $password): array
    {
        $loginId = $this->normalizeEmail($loginId);
        $employee = DB::table('employee_auth as ea')
            ->join('employees as e', 'e.id', '=', 'ea.employee_id')
            ->select([
                'e.id',
                'e.employee_code',
                'e.name',
                'e.status',
                'ea.password_hash',
            ])
            ->where('ea.login_id', $loginId)
            ->first();

        if ($employee === null || ! Hash::check($password, (string) $employee->password_hash)) {
            throw new ApiException('UNAUTHORIZED', 'ログインIDまたはパスワードが正しくありません。', 401);
        }

        if (strtoupper((string) $employee->status) !== 'ACTIVE') {
            throw new ApiException('FORBIDDEN', '現在この職員はログインできません。', 403);
        }

        DB::table('employee_auth')
            ->where('employee_id', $employee->id)
            ->update([
                'last_login_at' => now(),
                'updated_at' => now(),
            ]);

        return [
            'tokenableType' => 'EMPLOYEE',
            'tokenableId' => (int) $employee->id,
            'role' => 'EMPLOYEE',
            'user' => new GenericUser([
                'id' => (int) $employee->id,
                'role' => 'EMPLOYEE',
                'name' => $employee->name,
                'employeeCode' => $employee->employee_code,
                'is_admin' => false,
            ]),
            'userPayload' => [
                'id' => (int) $employee->id,
                'role' => 'EMPLOYEE',
                'name' => $employee->name,
                'employeeCode' => $employee->employee_code,
                'isAdmin' => false,
            ],
        ];
    }

    private function issueTokenPair(
        string $tokenableType,
        int $tokenableId,
        string $role,
        GenericUser $user,
        array $userPayload
    ): array {
        $pairKey = (string) Str::uuid();
        $accessToken = $this->generateToken('atk');
        $refreshToken = $this->generateToken('rtk');
        $accessExpiresAt = CarbonImmutable::now()->addMinutes(self::ACCESS_MINUTES);
        $refreshExpiresAt = CarbonImmutable::now()->addDays(self::REFRESH_DAYS);

        $common = [
            'tokenable_type' => $tokenableType,
            'tokenable_id' => $tokenableId,
            'role' => $role,
            'pair_key' => $pairKey,
            'ip_address' => request()?->ip(),
            'user_agent' => Str::limit((string) request()?->userAgent(), 255, ''),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('api_access_tokens')->insert([
            [
                ...$common,
                'token_kind' => 'ACCESS',
                'token_hash' => hash('sha256', $accessToken),
                'expires_at' => $accessExpiresAt->format('Y-m-d H:i:s'),
                'last_used_at' => null,
            ],
            [
                ...$common,
                'token_kind' => 'REFRESH',
                'token_hash' => hash('sha256', $refreshToken),
                'expires_at' => $refreshExpiresAt->format('Y-m-d H:i:s'),
                'last_used_at' => null,
            ],
        ]);

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'tokenType' => 'Bearer',
            'expiresIn' => self::ACCESS_MINUTES * 60,
            'refreshExpiresIn' => self::REFRESH_DAYS * 24 * 60 * 60,
            'user' => $userPayload,
        ];
    }

    private function findToken(string $plainToken, string $tokenKind): ?object
    {
        return DB::table('api_access_tokens')
            ->where('token_hash', hash('sha256', trim($plainToken)))
            ->where('token_kind', $tokenKind)
            ->where('expires_at', '>', now())
            ->first();
    }

    private function resolveAuto(string $loginId, string $password): array
    {
        try {
            return $this->resolveAdmin($loginId, $password);
        } catch (ApiException $exception) {
            if ($exception->errorCode !== 'UNAUTHORIZED') {
                throw $exception;
            }
        }

        return $this->resolveEmployee($loginId, $password);
    }

    private function resolvePrincipal(string $tokenableType, int $tokenableId): ?GenericUser
    {
        if ($tokenableType === 'ADMIN') {
            $admin = DB::table('users')
                ->leftJoin('employees as linked_employee', 'linked_employee.id', '=', 'users.employee_id')
                ->select([
                    'users.*',
                    'linked_employee.id as linked_employee_id',
                    'linked_employee.employee_code as linked_employee_code',
                    'linked_employee.name as linked_employee_name',
                    'linked_employee.status as linked_employee_status',
                ])
                ->where('users.id', $tokenableId)
                ->first();
            if ($admin === null || $admin->must_change_password) {
                return null;
            }

            $linkedEmployeeId = $this->activeLinkedEmployeeId($admin);
            $linkedEmployeeCode = $linkedEmployeeId !== null ? (string) $admin->linked_employee_code : null;

            return new GenericUser([
                'id' => (int) $admin->id,
                'role' => 'ADMIN',
                'name' => $admin->name,
                'email' => $admin->email,
                'is_admin' => true,
                'employeeId' => $linkedEmployeeId,
                'employeeCode' => $linkedEmployeeCode,
                'canUseEmployeePortal' => $linkedEmployeeId !== null,
            ]);
        }

        if ($tokenableType === 'EMPLOYEE') {
            $employee = DB::table('employees')
                ->where('id', $tokenableId)
                ->where('status', 'ACTIVE')
                ->first();
            if ($employee === null) {
                return null;
            }

            return new GenericUser([
                'id' => (int) $employee->id,
                'role' => 'EMPLOYEE',
                'name' => $employee->name,
                'employeeCode' => $employee->employee_code,
                'is_admin' => false,
            ]);
        }

        return null;
    }

    private function generateToken(string $prefix): string
    {
        return $prefix.'_'.Str::random(64);
    }

    private function normalizeEmail(string $value): string
    {
        return Str::lower(trim($value));
    }

    private function findActiveEmployeeByEmail(string $email): ?object
    {
        return DB::table('employee_auth as ea')
            ->join('employees as e', 'e.id', '=', 'ea.employee_id')
            ->select(['e.id', 'e.employee_code', 'e.name', 'e.status'])
            ->where('ea.login_id', $email)
            ->where('e.status', 'ACTIVE')
            ->first();
    }

    private function buildEmployeePasswordResetUrl(string $email, string $token): string
    {
        $configuredUrl = trim((string) config('app.url'));
        $configured = parse_url($configuredUrl);
        if (
            $configured === false ||
            ! isset($configured['scheme'], $configured['host']) ||
            ! in_array(strtolower((string) $configured['scheme']), ['http', 'https'], true)
        ) {
            throw new ApiException('CONFIGURATION_ERROR', 'パスワード再設定URLのAPP_URL設定が不正です。', 500);
        }

        $port = isset($configured['port']) ? ':'.$configured['port'] : '';
        $originUrl = strtolower((string) $configured['scheme']).'://'.$configured['host'].$port;

        return $originUrl.'/dakoku/login/?'.http_build_query([
            'email' => $email,
            'resetToken' => $token,
        ]);
    }

    private function serializeUser(GenericUser $user): array
    {
        return [
            'id' => (int) $user->id,
            'role' => (string) $user->role,
            'name' => (string) $user->name,
            'employeeCode' => isset($user->employeeCode) ? (string) $user->employeeCode : null,
            'email' => isset($user->email) ? (string) $user->email : null,
            'isAdmin' => isset($user->is_admin) ? (bool) $user->is_admin : false,
            'employeeId' => isset($user->employeeId) ? (int) $user->employeeId : null,
            'canUseEmployeePortal' => isset($user->canUseEmployeePortal) ? (bool) $user->canUseEmployeePortal : false,
        ];
    }

    private function activeLinkedEmployeeId(object $admin): ?int
    {
        $employeeId = isset($admin->linked_employee_id) ? (int) $admin->linked_employee_id : 0;
        if ($employeeId <= 0) {
            return null;
        }

        return strtoupper((string) $admin->linked_employee_status) === 'ACTIVE' ? $employeeId : null;
    }
}
