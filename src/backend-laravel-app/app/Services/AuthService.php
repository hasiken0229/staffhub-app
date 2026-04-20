<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class AuthService
{
    private const ACCESS_MINUTES = 8 * 60;
    private const REFRESH_DAYS = 30;

    public function login(array $payload): array
    {
        $audience = strtoupper(trim((string) ($payload['audience'] ?? 'AUTO')));
        $loginId = trim((string) $payload['loginId']);
        $password = (string) $payload['password'];

        if (!in_array($audience, ['AUTO', 'EMPLOYEE', 'ADMIN'], true)) {
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

            return $this->issueTokenPair(
                $identity['tokenableType'],
                $identity['tokenableId'],
                $identity['role'],
                $identity['user'],
                $identity['userPayload']
            );
        });
    }

    public function refresh(string $refreshToken): array
    {
        $token = $this->findToken($refreshToken, 'REFRESH');
        if ($token === null) {
            throw new ApiException('UNAUTHORIZED', 'リフレッシュトークンが無効です。', 401);
        }

        return DB::transaction(function () use ($token) {
            $user = $this->resolvePrincipal((string) $token->tokenable_type, (int) $token->tokenable_id);
            if ($user === null) {
                throw new ApiException('UNAUTHORIZED', '利用者が見つかりません。', 401);
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
        });
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

        DB::table('api_access_tokens')
            ->where('id', $token->id)
            ->update([
                'last_used_at' => now(),
                'updated_at' => now(),
            ]);

        return $this->resolvePrincipal((string) $token->tokenable_type, (int) $token->tokenable_id);
    }

    public function me(GenericUser $user): array
    {
        return $this->serializeUser($user);
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
            ->first();

        if ($admin === null || !Hash::check($password, (string) $admin->password)) {
            throw new ApiException('UNAUTHORIZED', 'ログインIDまたはパスワードが正しくありません。', 401);
        }

        $linkedEmployeeId = $this->activeLinkedEmployeeId($admin);
        $linkedEmployeeCode = $linkedEmployeeId !== null ? (string) $admin->linked_employee_code : null;

        return [
            'tokenableType' => 'ADMIN',
            'tokenableId' => (int) $admin->id,
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

        if ($employee === null || !Hash::check($password, (string) $employee->password_hash)) {
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
    ): array
    {
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
            if ($admin === null) {
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
            $employee = DB::table('employees')->where('id', $tokenableId)->first();
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
        return $prefix . '_' . Str::random(64);
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
