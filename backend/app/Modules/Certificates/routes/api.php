<?php

declare(strict_types=1);

use App\Modules\Certificates\Http\Controllers\CertificateController;
use App\Modules\Certificates\Http\Controllers\CertificateTemplateController;
use Illuminate\Support\Facades\Route;

Route::get('/certificates/verify/{code}', [CertificateController::class, 'verify'])
    ->name('certificates.verify');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/certificates', [CertificateController::class, 'index']);
    Route::get('/certificates/{certificate}', [CertificateController::class, 'show']);
    Route::post('/certificates/{certificate}/regenerate', [CertificateController::class, 'regenerate']);

    Route::get('/certificate-templates', [CertificateTemplateController::class, 'index']);
    Route::post('/certificate-templates', [CertificateTemplateController::class, 'store']);
    Route::get('/certificate-templates/{template}', [CertificateTemplateController::class, 'show']);
    Route::put('/certificate-templates/{template}', [CertificateTemplateController::class, 'update']);
    Route::delete('/certificate-templates/{template}', [CertificateTemplateController::class, 'destroy']);
});
