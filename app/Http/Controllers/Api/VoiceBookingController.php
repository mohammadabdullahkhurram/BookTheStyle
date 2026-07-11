<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Salon;
use App\Services\BookingApi\ApiError;
use App\Services\BookingApi\VoiceBookingApi;
use App\Services\BookingApi\VoiceInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The two Voice-AI Booking API endpoints (Stage 2, Architecture 4): GHL
 * Voice AI Custom Actions call these; the app's own engine answers. Thin
 * controller — request-shape validation here, everything real in
 * VoiceBookingApi. Every response is JSON with a speakable `message`;
 * failures are clean (never a stack trace) and logged by category
 * (api_validation vs engine) without PII or tokens.
 *
 * Wire tolerance: parameters are accepted from the JSON body OR the query
 * string ($request->input() merges both — GHL sends a query string with an
 * empty body), values are defensively percent-decoded (GHL double-encodes:
 * "Hair%2520Cut" arrives as the literal "Hair%20Cut"), and client fields
 * are accepted nested or flattened. See VoiceInput.
 */
class VoiceBookingController extends Controller
{
    public function availability(Request $request, VoiceBookingApi $api): JsonResponse
    {
        $salon = $this->salon($request);

        try {
            // Normalize into the request so the validated data is the
            // decoded, body-or-query merged form.
            $request->merge([
                'service' => VoiceInput::decode($request->input('service')),
                'stylist' => VoiceInput::decode($request->input('stylist')),
                'date' => VoiceInput::decode($request->input('date')),
                'date_to' => VoiceInput::decode($request->input('date_to')),
            ]);

            $input = $request->validate([
                'service' => ['required'],
                'stylist' => ['nullable'],
                'date' => ['nullable', 'string', 'max:40'],
                'date_to' => ['nullable', 'string', 'max:40'],
            ]);

            return response()->json($api->availability($salon, $input));
        } catch (ApiError $e) {
            return $this->apiError($salon, 'availability', $e);
        } catch (ValidationException $e) {
            return $this->invalidRequest($salon, 'availability', $e);
        }
    }

    public function create(Request $request, VoiceBookingApi $api): JsonResponse
    {
        $salon = $this->salon($request);

        try {
            // Normalize into the request so the validated data is the
            // decoded, body-or-query merged form (client: nested or flattened).
            $request->merge([
                'service' => VoiceInput::decode($request->input('service')),
                'stylist' => VoiceInput::decode($request->input('stylist')),
                'datetime' => VoiceInput::datetime($request->input('datetime')),
                'date' => VoiceInput::decode($request->input('date')),
                'time' => VoiceInput::decode($request->input('time')),
                'client' => VoiceInput::client($request),
                'notes' => VoiceInput::decode($request->input('notes')),
                'ghl_contact_id' => VoiceInput::decode($request->input('ghl_contact_id')),
            ]);

            // The slot may arrive as date + time (primary — GHL-friendly)
            // or as a combined ISO datetime; VoiceBookingApi requires one
            // of the shapes and answers with a speakable 422 otherwise.
            $input = $request->validate([
                'service' => ['required'],
                'stylist' => ['nullable'],
                'datetime' => ['nullable', 'string', 'max:40'],
                'date' => ['nullable', 'string', 'max:40'],
                'time' => ['nullable', 'string', 'max:20'],
                'client' => ['required', 'array'],
                'client.name' => ['required', 'string', 'max:255'],
                'client.phone' => ['nullable', 'string', 'max:50'],
                'client.email' => ['nullable', 'email', 'max:255'],
                'notes' => ['nullable', 'string', 'max:1000'],
                'ghl_contact_id' => ['nullable', 'string', 'max:64'],
            ]);

            $result = $api->create($salon, $input);

            return response()->json($result, $result['success'] ? 201 : 409);
        } catch (ApiError $e) {
            return $this->apiError($salon, 'create', $e);
        } catch (ValidationException $e) {
            return $this->invalidRequest($salon, 'create', $e);
        }
    }

    private function salon(Request $request): Salon
    {
        /** @var Salon */
        return $request->attributes->get('bookingApiSalon');
    }

    private function apiError(Salon $salon, string $endpoint, ApiError $e): JsonResponse
    {
        Log::info('Booking API request refused', [
            'category' => 'api_validation',
            'endpoint' => $endpoint,
            'salon_id' => $salon->id,
            'error' => $e->errorCode,
        ]);

        return response()->json($e->toResponse(), $e->status);
    }

    private function invalidRequest(Salon $salon, string $endpoint, ValidationException $e): JsonResponse
    {
        Log::info('Booking API request malformed', [
            'category' => 'api_validation',
            'endpoint' => $endpoint,
            'salon_id' => $salon->id,
            'fields' => array_keys($e->errors()),
        ]);

        return response()->json([
            'success' => false,
            'error' => 'invalid_request',
            'message' => collect($e->errors())->flatten()->first() ?? __('The request was invalid.'),
            'fields' => array_keys($e->errors()),
        ], 422);
    }
}
