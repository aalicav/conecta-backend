<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EntityDocumentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EntityDocumentTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = EntityDocumentType::query();

        if ($request->has('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $documentTypes = $query->get();

        return response()->json($documentTypes);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|string',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:entity_document_types',
            'description' => 'nullable|string',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'expiration_alert_days' => 'nullable|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $documentType = EntityDocumentType::create($request->all());

        return response()->json($documentType, 201);
    }

    public function update(Request $request, EntityDocumentType $documentType)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'expiration_alert_days' => 'nullable|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $documentType->update($request->all());

        return response()->json($documentType);
    }

    public function destroy(EntityDocumentType $documentType)
    {
        $documentType->delete();
        return response()->json(null, 204);
    }
} 