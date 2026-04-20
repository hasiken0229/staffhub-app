<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiException;
use App\Services\ApiResponse;
use App\Services\NoticeService;
use Illuminate\Http\Request;

final class NotificationController extends Controller
{
    public function __construct(private readonly NoticeService $noticeService)
    {
    }

    public function index(Request $request)
    {
        $result = $this->noticeService->listForEmployee((int) $request->user()->id, $request->only([
            'page',
            'perPage',
        ]));

        return ApiResponse::ok($result['items'], $result['meta']);
    }

    public function markRead(Request $request, int $id)
    {
        $payload = $request->validate([
            'sourceType' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            return ApiResponse::ok(
                $this->noticeService->markRead((int) $request->user()->id, $id, $payload['sourceType'] ?? null)
            );
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
