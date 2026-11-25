<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\NoticeService;
use Illuminate\Http\Request;

final class NoticeAdminController extends Controller
{
    public function __construct(private readonly NoticeService $noticeService)
    {
    }

    public function index(Request $request)
    {
        $result = $this->noticeService->listForAdmin($request->only([
            'noticeType',
            'activeOnly',
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'noticeType' => ['required', 'string', 'in:GENERAL,PAYROLL_INFO,SYSTEM'],
            'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string'],
            'publishStartAt' => ['required', 'date'],
            'publishEndAt' => ['nullable', 'date'],
            'targetEmployeeId' => ['nullable', 'integer', 'min:1', 'exists:employees,id'],
            'relatedType' => ['nullable', 'string', 'max:30'],
            'relatedId' => ['nullable', 'integer', 'min:1'],
            'requestId' => ['nullable', 'uuid'],
        ]);

        try {
            return ApiResponse::ok($this->noticeService->storeForAdmin($payload, $request->user()));
        } catch (ApiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->details,
            );
        }
    }

    public function show(int $id)
    {
        try { return ApiResponse::ok($this->noticeService->detailForAdmin($id)); }
        catch (ApiException $error) { return ApiResponse::error($error->errorCode, $error->getMessage(), $error->status, $error->details); }
    }

    public function update(Request $request, int $id)
    {
        $payload = $request->validate([
            'noticeType' => ['required', 'string', 'in:GENERAL,PAYROLL_INFO,SYSTEM'], 'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string'], 'publishStartAt' => ['required', 'date'], 'publishEndAt' => ['nullable', 'date'],
            'revision' => ['required', 'integer', 'min:1'],
        ]);
        try { return ApiResponse::ok($this->noticeService->updateForAdmin($id, $payload, $request->user())); }
        catch (ApiException $error) { return ApiResponse::error($error->errorCode, $error->getMessage(), $error->status, $error->details); }
    }

    public function stop(Request $request, int $id)
    {
        $payload = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        try { return ApiResponse::ok($this->noticeService->stopForAdmin($id, (int) $payload['revision'], $request->user())); }
        catch (ApiException $error) { return ApiResponse::error($error->errorCode, $error->getMessage(), $error->status, $error->details); }
    }
}
