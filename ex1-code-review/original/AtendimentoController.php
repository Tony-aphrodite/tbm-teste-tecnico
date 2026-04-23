<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Paciente;
use App\Models\Atendimento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AtendimentoController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            if (!$this->auth()) {
                session()->put('getMessage', 'Acesso negado');
                return redirect()->back();
            }
            return $next($request);
        });
        $this->Pacientes = new Paciente();
    }

    // Lista atendimentos com filtros
    public function index(Request $request)
    {
        $tenant = $request->header('X-Tenant-ID');
        $where = [];
        $where[] = 'a.tenant_id = "' . $tenant . '"';

        if ($request->has('status')) {
            $where[] = 'a.status = "' . $request->status . '"';
        }
        if ($request->has('profissional')) {
            $where[] = 'pr.nome like "%' . $request->profissional . '%"';
        }
        if ($request->has('data_inicio')) {
            $where[] = 'a.data BETWEEN "' . $request->data_inicio . '" AND "' . $request->data_fim . '"';
        }

        $whereRaw = implode(' AND ', $where);

        $atendimentos = DB::table('atendimentos as a')
            ->join('pacientes p', 'a.paciente_id', '=', 'p.id')
            ->join('profissionais pr', 'a.profissional_id', '=', 'pr.id')
            ->whereRaw($whereRaw)
            ->get();

        return response()->json($atendimentos);
    }

    // Cria atendimento
    public function store(Request $request)
    {
        $atendimento = Atendimento::create($request->all());

        // Token para o profissional acessar o atendimento
        $token = md5($atendimento->profissional_id . time());
        $atendimento->update(['token_acesso' => $token]);

        return response()->json($atendimento, 201);
    }

    // Atualiza atendimento
    public function update(Request $request, $id)
    {
        $atendimento = Atendimento::find($id);
        $atendimento->update($request->all());

        return response()->json($atendimento);
    }

    // Download de evolução clínica
    public function downloadEvolucao($userId, $fileName)
    {
        $filePath = 'app/users/' . $userId . '/' . $fileName;

        if (Storage::disk('local')->exists($filePath)) {
            return Storage::disk('local')->download($filePath);
        }

        abort(404);
    }

    // Upload de imagem do profissional
    public function uploadImagem(Request $request)
    {
        $extension = $request->file('imagem')->getClientOriginalExtension();
        $nome = $request->input('nome') . '_' . time() . '.' . $extension;

        Storage::disk('local')->put(
            'app/users/fotos/' . $nome,
            file_get_contents($request->file('imagem'))
        );

        return response()->json(['arquivo' => $nome]);
    }
}
