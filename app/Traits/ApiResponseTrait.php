<?php

namespace App\Traits;

trait ApiResponseTrait
{
    public function success(string $message, array $data = [], int $code = 200)
    {
        return response()->json(array_merge([
            'success' => true,
            'message' => $message,
        ], $data), $code);
    }

    public function error(string $message, int $code = 400, $errors = null, array $data = [])
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json(array_merge($response, $data), $code);
    }
}