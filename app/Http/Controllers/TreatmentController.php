<?php

namespace App\Http\Controllers;


use App\Models\Treatment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TreatmentController extends Controller
{
    /**
     * Get all treatments from a clinic.
     */
    public function index(Request $request, $clinicId)
    {
        $treatments = Treatment::where('clinic_id', $clinicId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'treatments' => $treatments
        ], 200);
    }

    /**
     * Update an existing treatment.
     */
    public function update(Request $request, $clinicId, $id)
    {
        $treatment = Treatment::where('clinic_id', $clinicId)
            ->where('id', $id)
            ->first();

        if (!$treatment) {
            return response()->json([
                'message' => 'Treatment not found.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'duration' => 'sometimes|required|integer|min:1',
            'price' => 'sometimes|required|numeric|min:0',
            'is_active' => 'sometimes|required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error.',
                'errors' => $validator->errors()
            ], 422);
        }

        $treatment->update($validator->validated());

        return response()->json([
            'message' => 'Treatment updated successfully.',
            'treatment' => $treatment->fresh()
        ], 200);
    }

    /**
     * Delete a treatment.
     */
    public function destroy($clinicId, $id)
    {
        $treatment = Treatment::where('clinic_id', $clinicId)
            ->where('id', $id)
            ->first();

        if (!$treatment) {
            return response()->json([
                'message' => 'Treatment not found.'
            ], 404);
        }

        $treatment->delete();

        return response()->json([
            'message' => 'Treatment deleted successfully.'
        ], 200);
    }
}
