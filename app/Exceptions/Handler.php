<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->renderable(function (Throwable $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                $errors = [];
                foreach ($e->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $errors[] = ['code' => 'validation', 'field' => $field, 'detail' => $message];
                    }
                }

                return ApiResponse::error('Les données sont invalides.', 422, $errors);
            }

            if ($e instanceof AuthenticationException) {
                return ApiResponse::error('Non authentifié.', 401, [
                    ['code' => 'unauthenticated', 'detail' => 'Non authentifié.'],
                ]);
            }

            if ($e instanceof AuthorizationException || $e instanceof UnauthorizedException) {
                return ApiResponse::error('Accès refusé.', 403, [
                    ['code' => 'forbidden', 'detail' => $e->getMessage() ?: 'Accès refusé.'],
                ]);
            }

            if ($e instanceof ModelNotFoundException) {
                return ApiResponse::error('Ressource introuvable.', 404, [
                    ['code' => 'not_found', 'detail' => 'Ressource introuvable.'],
                ]);
            }

            if ($e instanceof HttpException) {
                return ApiResponse::error($e->getMessage() ?: 'Erreur HTTP.', $e->getStatusCode());
            }

            return null;
        });
    }
}
