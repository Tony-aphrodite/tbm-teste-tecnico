# Exercício 1 — Code Review

Análise do `AtendimentoController` adaptado de sistema Laravel 9 de homecare em produção.

A ordem desta análise é **priorização por impacto real sobre dados de paciente**, não pela ordem em que o código aparece. Tudo que escrevi abaixo parte do pressuposto do enunciado: *esse sistema tem prontuário de paciente real*. Em homecare, vazar evolução clínica ou misturar dados entre tenants não é incidente de segurança — é violação de LGPD, de sigilo médico e potencialmente de resolução CFM.

---

## Resumo da priorização

| # | Problema | Severidade | Arquivo/linha |
|---|---|---|---|
| 1 | SQL Injection em `index()` (múltiplos vetores) | **Crítico** | `index()` |
| 2 | Isolamento multi-tenant controlado pelo cliente via header `X-Tenant-ID` | **Crítico** | `index()` |
| 3 | Path Traversal + falta de autorização em `downloadEvolucao()` | **Crítico** | `downloadEvolucao()` |
| 4 | Token de acesso baseado em `md5(id . time())` — previsível e enumerável | **Crítico** | `store()` |
| 5 | Mass Assignment em `store()` e `update()` via `$request->all()` | **Alto** | `store()`, `update()` |
| 6 | `update()` sem checagem de autorização nem de tenant, sem `findOrFail` | **Alto** | `update()` |
| 7 | Upload de imagem sem validação de tipo, tamanho ou sanitização do nome | **Alto** | `uploadImagem()` |
| 8 | Middleware customizado chama `$this->auth()` que não existe; retorna `redirect()->back()` em rota de API | **Alto** | `__construct()` |
| 9 | Sem paginação em `index()` — devolve o universo inteiro | **Médio** | `index()` |
| 10 | `DB::table()` em vez de Eloquent — perde scopes, eventos, accessors | **Médio** | `index()` |
| 11 | Falta de API Resource — expõe todas as colunas da tabela (incluindo `token_acesso`) | **Médio** | `store()`, `update()`, `index()` |
| 12 | Propriedade dinâmica `$this->Pacientes` (deprecada em PHP 8.2+, e não usada) | **Médio** | `__construct()` |
| 13 | `file_get_contents` em upload consome o arquivo inteiro em memória em vez de `store()` / stream | **Médio** | `uploadImagem()` |
| 14 | Ausência de rate limiting em upload/download | **Médio** | controller inteiro |
| 15 | Ausência de log de auditoria em acesso a PHI | **Médio** | controller inteiro |

**Por que essa ordem:** problemas 1–4 dão ao atacante acesso direto a dados de paciente (leitura ou escrita) sem pré-condição. São "one-shot" — basta o atacante descobrir o endpoint. Os de #5 em diante são sérios mas exigem contexto adicional (usuário autenticado, engenharia de input, etc.). A regra que apliquei foi: *qual bug, se explorado hoje, obriga a notificar a ANPD?* Esses entram no topo.

---

## Análise detalhada

### 🔴 1. SQL Injection — `index()` — **Crítico**

```php
$tenant = $request->header('X-Tenant-ID');
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
```

Todo input do cliente é concatenado direto numa cláusula `whereRaw`. Um `?status="+OR+"1"="1` já quebra o filtro. Pior: `profissional` entra num `LIKE`, ou seja, o atacante controla o pattern matching e o aspas-escape. Vetores: `X-Tenant-ID`, `status`, `profissional`, `data_inicio`, `data_fim` — **cinco pontos de injeção simultâneos**.

Impacto: leitura e escrita arbitrárias no banco, incluindo dump de `pacientes`, `atendimentos`, `evolucoes_clinicas`. Com `UNION SELECT` + `LOAD_FILE` em MySQL, pode chegar a arquivos do host.

**Correção:** Query Builder parametrizado (`where('status', $request->status)`) + `FormRequest` validando cada filtro com regex/enum. Nunca concatenar input. Ver `fixed/app/Http/Controllers/Api/AtendimentoController.php::index()` e `fixed/app/Http/Requests/IndexAtendimentoRequest.php`.

---

### 🔴 2. Isolamento multi-tenant via header do cliente — `index()` — **Crítico**

```php
$tenant = $request->header('X-Tenant-ID');
$where[] = 'a.tenant_id = "' . $tenant . '"';
```

O cliente escolhe qual tenant ele está consultando. Mandar `X-Tenant-ID: 2` lista os atendimentos do tenant 2, independentemente de qual tenant o usuário autenticado pertence. Isso é **vazamento horizontal de dados entre clientes** — em homecare, significa uma operadora vendo pacientes de outra operadora.

Isso é muito pior do que SQL Injection em um sentido: SQL injection exige alguma habilidade e pode ser detectado por WAF. Esse aqui qualquer cliente legítimo da API descobre por acidente.

Mesmo que o SQLi fosse corrigido, se o `tenant_id` continuar vindo do header, **o isolamento está quebrado**. Os dois bugs são independentes.

**Correção:** `tenant_id` **sempre** vem de `auth()->user()->tenant_id`. Never trust the client. Global scope `BelongsToTenant` nos models garante que qualquer query passa por esse filtro automaticamente. O header `X-Tenant-ID`, se existir, serve no máximo para troca explícita de contexto por admin de plataforma — e mesmo aí passa por `Gate`.

Ver `fixed/app/Models/Scopes/TenantScope.php` e `fixed/app/Models/Concerns/BelongsToTenant.php`.

---

### 🔴 3. Path Traversal + ausência total de autorização — `downloadEvolucao()` — **Crítico**

```php
public function downloadEvolucao($userId, $fileName)
{
    $filePath = 'app/users/' . $userId . '/' . $fileName;
    if (Storage::disk('local')->exists($filePath)) {
        return Storage::disk('local')->download($filePath);
    }
    abort(404);
}
```

Três problemas empilhados:

1. **Path traversal:** `$fileName = '../../.env'` resolve para `app/users/5/../../.env`. Sem sanitização.
2. **IDOR completo:** nenhuma checagem de que `$userId` é o usuário autenticado, nem que pertence ao mesmo tenant. Basta iterar `/atendimentos/evolucao/{1..10000}/{arquivo}`.
3. **Enumeração do nome do arquivo:** se o nome for previsível (ex.: `evolucao_2025-10-01.pdf`), dá para forçar bruta sem sequer adivinhar IDs.

Em prontuário clínico isso é catastrófico. É exatamente o tipo de falha que aparece em notícia de vazamento de dados de saúde.

**Correção:**
- Endpoint recebe apenas o `atendimento_id` (não um caminho).
- Carrega o `Atendimento` pelo model, que já vem com scope de tenant.
- `Gate::authorize('download', $atendimento)` valida se o usuário tem direito àquela evolução específica (profissional autor, coordenador, auditor, etc.).
- O caminho físico do arquivo é construído a partir de um UUID armazenado no próprio registro — **nunca** do input.
- Log de auditoria (quem baixou o quê e quando) é obrigatório para PHI.

Ver `fixed/app/Http/Controllers/Api/AtendimentoController.php::downloadEvolucao()`.

---

### 🟠 4. Token de acesso fraco — `store()` — **Alto (perto de Crítico)**

```php
$token = md5($atendimento->profissional_id . time());
```

MD5 não é hash seguro para autenticação. `time()` em segundos tem entropia ínfima. `profissional_id` é um inteiro sequencial pequeno. O espaço total efetivo é `~numero_de_profissionais × segundos_do_dia` — completamente enumerável offline em minutos.

Além disso, o token é gravado com `update()` sem ser tratado como segredo (fica em log de query, fica no response, etc.).

**Correção:** `Str::random(64)` ou `bin2hex(random_bytes(32))`, armazenado como `hash('sha256', $token)` no banco, comparado com `hash_equals`. Nunca retorne o token em listagens — só no momento da criação.

---

### 🟠 5. Mass Assignment — `store()` e `update()` — **Alto**

```php
$atendimento = Atendimento::create($request->all());
$atendimento->update($request->all());
```

Qualquer campo do request é gravado no model. Se `Atendimento` tem `tenant_id`, `status`, `token_acesso`, `valor_cobrado`, `profissional_id` no `$fillable`, o cliente sobrescreve todos. Pode-se criar um atendimento já com `status=faturado`, `tenant_id` de outro cliente, ou injetar o próprio `token_acesso`.

**Correção:** `FormRequest` com `validated()` e whitelist explícita. `tenant_id` sempre preenchido pelo servidor. Ver `fixed/app/Http/Requests/StoreAtendimentoRequest.php`.

---

### 🟠 6. `update()` sem `findOrFail` e sem autorização — **Alto**

```php
$atendimento = Atendimento::find($id);
$atendimento->update($request->all());
```

- `find($id)` retorna `null` se não existe — `->update()` em `null` é um 500.
- Nenhuma checagem de tenant: o usuário do tenant A pode atualizar atendimento do tenant B passando o ID.
- Mass assignment (#5) reaparece aqui.

**Correção:** Com `BelongsToTenant` + global scope, `Atendimento::findOrFail($id)` já devolve 404 para IDs fora do tenant do usuário. Adicione `Gate::authorize('update', $atendimento)` para regras de negócio (por ex., atendimento faturado não pode ser editado).

---

### 🟠 7. Upload de imagem sem validação — `uploadImagem()` — **Alto**

```php
$extension = $request->file('imagem')->getClientOriginalExtension();
$nome = $request->input('nome') . '_' . time() . '.' . $extension;
Storage::disk('local')->put('app/users/fotos/' . $nome, file_get_contents($request->file('imagem')));
```

Problemas:
- `getClientOriginalExtension()` vem do cliente — mandar `shell.php` com content-type de imagem passa.
- Se o disk `local` aponta para dentro do `public/`, o atacante fez RCE.
- `$request->input('nome')` concatenado ao path: path injection trivial (`nome=../../../../../../var/www/html/shell`).
- `file_get_contents` carrega tudo em memória.
- Sem limite de tamanho, sem MIME real validado.

**Correção:** `FormRequest` com `mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=4096`. Nome gerado via `Str::uuid()` — nunca do cliente. Disk separado (`profiles`) fora do webroot. Content-type validado pelo conteúdo real, não pela extensão. Use `->store()` em vez de `put(file_get_contents(...))`.

---

### 🟠 8. Middleware quebrado — `__construct()` — **Alto**

```php
$this->middleware(function ($request, $next) {
    if (!$this->auth()) {
        session()->put('getMessage', 'Acesso negado');
        return redirect()->back();
    }
    return $next($request);
});
```

- `$this->auth()` não é um método de `Controller`. Em runtime isso é `BadMethodCallException`. Provavelmente nunca foi exercitado — o que significa que o middleware `'auth'` da linha anterior é o único vigente, e essa closure só existe no papel.
- Em rota de API, `redirect()->back()` retorna HTML de redirect ou erro de `Symfony\Component\HttpFoundation\Session\SessionNotFoundException`.
- `session()->put()` em API stateless não faz sentido.

**Correção:** Aplicar `auth:sanctum` na rota, não no construtor. Apagar esse middleware morto.

---

### 🟡 9. Sem paginação — `index()` — **Médio**

`->get()` sem `paginate()` nem `limit()`. Em um sistema real de homecare com milhares de atendimentos por mês, uma consulta sem filtro pode retornar centenas de milhares de linhas — OOM no servidor, timeout no cliente. Já vi isso derrubar produção.

**Correção:** `->paginate(50)` com `per_page` validado (máx 100).

---

### 🟡 10. `DB::table()` em vez de Eloquent — **Médio**

Usar `DB::table()` bypassa:
- Global scopes (inclusive o `BelongsToTenant` que seria a correção de #2).
- Accessors / casts.
- Eventos (`deleting`, `updated`) — quebra auditoria.
- Relações e eager loading.

**Correção:** `Atendimento::with(['paciente', 'profissional'])->...`.

---

### 🟡 11. Sem API Resource — **Médio**

`response()->json($atendimento)` expõe a tabela crua, incluindo `token_acesso`, `created_at`, `updated_at`, flags internas. Resposta também fica acoplada ao schema do banco — qualquer migration vira breaking change na API.

**Correção:** `AtendimentoResource` controla o shape do JSON. Ver `fixed/app/Http/Resources/AtendimentoResource.php`.

---

### 🟡 12. Propriedade dinâmica `$this->Pacientes` — **Médio**

```php
$this->Pacientes = new Paciente();
```

Criada no construtor, nunca usada, nome em PascalCase (viola PSR-12), gera warning em PHP 8.2 (`Creation of dynamic property is deprecated`). Provavelmente resíduo de refatoração antiga.

**Correção:** remover.

---

### 🟡 13. `file_get_contents` em upload — **Médio**

Puxa o arquivo inteiro para memória antes de gravar. Em upload de 50 MB com 20 requests concorrentes você comeu 1 GB de RAM. Além disso, `->put($path, file_get_contents(...))` é redundante — o `UploadedFile` tem `->store()` que faz isso via stream.

**Correção:** `$request->file('imagem')->storeAs($dir, $nome, 'profiles')`.

---

### 🟡 14. Sem rate limiting — **Médio**

Download e upload são endpoints perfeitos para brute force (enumerar IDs, testar path traversal) e DoS (upload de 1 GB repetido). Devem estar atrás de `throttle:60,1` no mínimo, com throttle por usuário em download de PHI.

---

### 🟡 15. Sem log de auditoria — **Médio**

Acesso a prontuário **precisa** ser auditado (LGPD art. 37, boas práticas de saúde). O controller inteiro não loga nada. Minimamente: quem acessou qual atendimento, quando, de qual IP, com qual resultado.

---

## Os 3 problemas que corrigi

Escolhi **#1 SQL Injection**, **#2 Tenant via header** e **#3 Path Traversal em download**. Raciocínio:

- Os três levam a vazamento direto de dados clínicos de paciente, **sem pré-condição**. Basta o atacante bater no endpoint.
- Os três são triviais de explorar — nenhum exige engenharia social, nenhum exige credenciais privilegiadas.
- Em conjunto, eles compõem a história de um *data breach* de cliente inteiro: SQL injection dumpa a lista de atendimentos, falha de tenant confirma quais pertencem a cada operadora, path traversal baixa a evolução clínica assinada.

**#4 (token md5)** ficou de fora da lista de "top 3" porque o impacto depende do desenho do fluxo do token — precisa saber como e onde ele é usado. Se o token autentica o profissional no app mobile, é Crítico-Crítico. Se é só um identificador secundário, é Alto. Sem ver o restante do sistema, preferi não assumir.

**#5 (mass assignment)** é Alto, mas requer que o atacante já esteja autenticado e conheça o schema. Resolvê-lo sem resolver #1 e #2 não muda a superfície de ataque externa.

Os arquivos corrigidos estão em [`fixed/`](fixed/). Preservei o estilo do projeto (Laravel 9, namespaces `App\Http\Controllers\Api`) e mantive o controller enxuto — lógica de construção de arquivo foi para um service pequeno (`EvolucaoClinicaService`) porque concentrar policy de path num único lugar evita regressão.

---

## Trade-offs e o que deixei de fora

- **Não migrei tudo para `DB::transaction`**: `store()` + `update(token)` seriam duas queries em transação ideal, mas adicionar isso deixaria a correção mais longa que o pedido. Anotei como débito.
- **Não introduzi UUID em vez de ID autoincrement**: é a decisão correta para evitar enumeração, mas é uma mudança de schema que afeta o app mobile. Comentário no código indica.
- **Não implementei log de auditoria completo**: só deixei o gancho (`activity()->log(...)` comentado) porque a ferramenta de auditoria do projeto real não é visível daqui.
- **Policy vs Gate:** usei `Gate::authorize` direto no controller. Em projeto maior faria `AtendimentoPolicy` — decisão deliberada de escopo.
