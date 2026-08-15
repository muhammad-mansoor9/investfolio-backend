<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BaseController extends Controller
{
    /**
     * Legacy sendResponse method - wraps parent's successResponse
     *
     * @param mixed $result The data to return
     * @param string $message The success message
     * @param int $code HTTP status code (default 200)
     * @return JsonResponse
     */
    protected function sendResponse($result, string $message = 'Success', int $code = 200): JsonResponse
    {
        return $this->successResponse($result, $message, $code);
    }

    /**
     * Legacy sendError method - wraps parent's errorResponse
     *
     * @param string $error The error message
     * @param mixed $errorMessages Additional error details
     * @param int $code HTTP status code (default 404)
     * @return JsonResponse
     */
    protected function sendError(string $error, $errorMessages = [], int $code = 404): JsonResponse
    {
        return $this->errorResponse($error, $code, $errorMessages);
    }
}