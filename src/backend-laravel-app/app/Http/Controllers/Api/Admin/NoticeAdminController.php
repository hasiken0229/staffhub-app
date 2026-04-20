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
            'noticeType' => ['required', 'string', 'max:30'],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string'],
            'publishStartAt' => ['required', 'date'],
            'publishEndAt' => ['nullable', 'date'],
            'targetEmployeeId' => ['nullable', 'integer', 'min:1'],
            'relatedType' => ['nullable', 'string', 'max:30'],
            'relatedId' => ['nullable', 'integer', 'min:1'],
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
}
