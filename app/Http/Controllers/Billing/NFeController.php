<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class NFeController extends Controller
{
    public function index(Request $request)
    {
        $query = BillingBatch::with(['healthPlan', 'contract'])
            ->whereNotNull('nfe_number')
            ->orderBy('created_at', 'desc');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nfe_number', 'like', "%{$search}%")
                    ->orWhere('nfe_key', 'like', "%{$search}%")
                    ->orWhereHas('healthPlan', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('contract', function ($q) use ($search) {
                        $q->where('number', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->has('status')) {
            $query->where('nfe_status', $request->status);
        }

        if ($request->has('start_date')) {
            $query->whereDate('nfe_authorization_date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('nfe_authorization_date', '<=', $request->end_date);
        }

        $nfes = $query->paginate(10);

        return response()->json($nfes);
    }

    public function show($id)
    {
        $nfe = BillingBatch::with(['healthPlan', 'contract', 'items.procedure'])
            ->whereNotNull('nfe_number')
            ->findOrFail($id);

        return response()->json($nfe);
    }

    public function downloadXML($id)
    {
        $nfe = BillingBatch::whereNotNull('nfe_number')
            ->findOrFail($id);

        if (!$nfe->nfe_xml) {
            return response()->json(['message' => 'XML não encontrado'], 404);
        }

        try {
            $xmlContent = Storage::get($nfe->nfe_xml);
            
            return response($xmlContent, 200, [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => "attachment; filename=nfe-{$nfe->nfe_number}.xml",
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao baixar XML da NFe', [
                'nfe_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Erro ao baixar XML'], 500);
        }
    }

    public function cancel($id)
    {
        $nfe = BillingBatch::whereNotNull('nfe_number')
            ->findOrFail($id);

        if ($nfe->nfe_status !== 'authorized') {
            return response()->json([
                'message' => 'Apenas notas fiscais autorizadas podem ser canceladas',
            ], 400);
        }

        try {
            // Aqui você implementaria a lógica de cancelamento usando a biblioteca SPED-NFe
            // Por enquanto, apenas atualizamos o status
            $nfe->update([
                'nfe_status' => 'cancelled',
            ]);

            return response()->json([
                'message' => 'Nota fiscal cancelada com sucesso',
                'nfe' => $nfe,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao cancelar NFe', [
                'nfe_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Erro ao cancelar nota fiscal'], 500);
        }
    }
} 