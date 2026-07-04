<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\HitPay\HitPayService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

class HitPayWebhookController extends Controller
{
    public function __invoke(Request $request, Company $company, HitPayService $service): Response
    {
        if (! $company->hitpayConfigured()) {
            abort(404);
        }

        try {
            $service->handleWebhook($company, $request->post());
        } catch (InvalidArgumentException $e) {
            // Bad signature / currency mismatch: reject so HitPay retries & it surfaces in logs
            abort(400, $e->getMessage());
        }

        return response('OK');
    }
}
