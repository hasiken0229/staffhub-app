<?php

namespace App\Http\Middleware;

use App\Services\ApiResponse;
use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EmployeeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('UNAUTHORIZED', '認証が必要です。', 401);
        }

        $isAdmin = false;

        foreach (['is_admin', 'isAdmin'] as $property) {
            if (isset($user->{$property}) && filter_var($user->{$property}, FILTER_VALIDATE_BOOL)) {
                $isAdmin = true;
                break;
            }
        }

        $role = '';
        foreach (['role', 'user_type', 'userType'] as $property) {
            if (!isset($user->{$property})) {
                continue;
            }

            $role = strtoupper((string) $user->{$property});
            break;
        }

        if ($isAdmin) {
            $employeeUser = $this->resolveLinkedEmployeeUser($user);
            if ($employeeUser === null) {
                return ApiResponse::error('FORBIDDEN', '職員アカウントでの利用が必要です。', 403);
            }

            $request->setUserResolver(static fn () => $employeeUser);
            Auth::guard()->setUser($employeeUser);

            return $next($request);
        }

        if ($role !== '' && $role !== 'EMPLOYEE') {
            return ApiResponse::error('FORBIDDEN', '職員アカウントでの利用が必要です。', 403);
        }

        return $next($request);
    }

    private function resolveLinkedEmployeeUser(object $user): ?GenericUser
    {
        $employeeId = isset($user->employeeId) ? (int) $user->employeeId : 0;
        if ($employeeId <= 0) {
            return null;
        }

        $employee = DB::table('employees')->where('id', $employeeId)->first();
        if ($employee === null || strtoupper((string) $employee->status) !== 'ACTIVE') {
            return null;
        }

        return new GenericUser([
            'id' => (int) $employee->id,
            'role' => 'EMPLOYEE',
            'name' => $employee->name,
            'employeeCode' => $employee->employee_code,
            'is_admin' => false,
            'linkedAdminId' => isset($user->id) ? (int) $user->id : null,
        ]);
    }
}
