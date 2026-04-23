# Exercício 2 — Feature Check-in de Profissional

Implementação da rota `POST /api/v1/checkin` usada pelos profissionais no app mobile ao iniciar um atendimento domiciliar. O foco do exercício é isolamento multi-tenant e qualidade dos testes — optei por uma implementação enxuta e direta, sem camadas extras.

## Arquitetura

### Fluxo de uma requisição

1. Sanctum autentica o bearer token → `$request->user()`.
2. `StoreCheckinRequest` valida o payload e, via `Rule::exists` escopado por `tenant_id`, garante que `profissional_id` e `paciente_id` pertencem ao tenant do usuário autenticado.
3. `CheckinController` cria o registro. O `tenant_id` é preenchido **automaticamente** pelo trait `BelongsToTenant` no model — nunca vem do request.
4. `CheckinResource` devolve o JSON já modelado.

### Isolamento multi-tenant — a decisão central

Três camadas independentes impedem qualquer vazamento horizontal. A redundância é intencional: cada uma por si já protege; juntas fecham lacunas que uma sozinha deixaria.

1. **Global scope (`TenantScope`)** em `Checkin`, `Profissional`, `Paciente`. Toda query feita via Eloquent já vem filtrada por `auth()->user()->tenant_id`. Isso significa que se um programador no futuro escrever `Profissional::find($id)` descuidado, o scope rejeita o registro de outro tenant retornando `null` — e `findOrFail` vira 404.

2. **Preenchimento automático no `creating`.** O trait seta `tenant_id` automaticamente no momento da criação. Mesmo se alguém fizer mass-assignment malicioso com `tenant_id` no payload, o trait sobrescreve com o do usuário autenticado.

3. **Validação no `FormRequest` via `Rule::exists` com `where('tenant_id', ...)`.** Antes mesmo de chegar ao controller, IDs de outro tenant retornam 422 com mensagem de validação — não 404. Isso é importante por dois motivos: (a) fail-fast, o erro está mais próximo da causa, e (b) a mensagem de validação é indistinguível de "ID não existe" (timing e corpo iguais), então não vaza informação sobre a existência de registros em outros tenants.

**Por que três camadas?** Se o global scope for desabilitado por engano (ex: `withoutGlobalScopes()` em uma query de relatório), as camadas 2 e 3 ainda seguram. Se alguém bypassar o FormRequest (chamada interna entre serviços), a camada 1 segura. Defense in depth é barato aqui — são poucas linhas de código.

### Por que não usei UUID/ULID como PK

Mantive `bigint` autoincrement porque (a) o enunciado deixa isso em aberto, (b) é o default do Laravel, (c) a substituição afeta o app mobile e outros serviços. Para enumeração, o isolamento por tenant já torna IDs inúteis fora do contexto. Em um sistema novo preferiria ULID — registrei como trade-off.

### Geolocalização

Coordenadas ficam em `DECIMAL(10, 7)` e `DECIMAL(11, 7)` (suficiente para ~1cm de precisão — muito mais que GPS de celular entrega). Escolhi `DECIMAL` sobre `DOUBLE` porque em domínio de saúde é comum exigir reprodutibilidade exata de valores — `DOUBLE` não garante isso.

**Validação das coordenadas:** `between:-90,90` e `between:-180,180`. Não validei "distância até o endereço do paciente" deliberadamente — isso é regra de negócio que deve morar em um service separado (ou em um job assíncrono de auditoria), não no caminho crítico do check-in. Um profissional pode estar temporariamente com GPS ruim; bloquear na hora criaria atrito operacional.

### `check_in_at` gerado pelo servidor

O timestamp **nunca** vem do cliente. Isso evita que um profissional registre check-in retroativamente para cobrir atrasos. Se no futuro precisarmos permitir correção manual, fazemos isso via endpoint dedicado com autorização extra e log de auditoria — não via parâmetro opcional aqui.

### Shape da resposta (201 Created)

O enunciado pede "confirmação com os dados do atendimento criado". Retorno o check-in com os nomes do profissional e paciente já resolvidos (via eager load), em vez de apenas os IDs crus. Assim o app mobile não precisa de um roundtrip extra para montar a tela de confirmação.

```json
{
  "data": {
    "id": 123,
    "profissional": { "id": 7, "nome": "Maria Silva" },
    "paciente":     { "id": 42, "nome": "João Souza" },
    "localizacao":  { "latitude": -29.9177, "longitude": -51.1836 },
    "check_in_at":  "2026-04-24T14:32:10-03:00",
    "created_at":   "2026-04-24T14:32:10-03:00"
  }
}
```

## Estrutura de arquivos

```
app/
  Http/
    Controllers/Api/V1/CheckinController.php
    Requests/StoreCheckinRequest.php
    Resources/CheckinResource.php
  Models/
    Checkin.php
    Paciente.php
    Profissional.php
    Tenant.php
    User.php
    Concerns/BelongsToTenant.php
    Scopes/TenantScope.php
database/
  factories/
    CheckinFactory.php
    PacienteFactory.php
    ProfissionalFactory.php
    TenantFactory.php
    UserFactory.php
  migrations/
    2026_04_24_000000_create_tenants_table.php
    2026_04_24_000001_create_users_table.php
    2026_04_24_000002_create_personal_access_tokens_table.php
    2026_04_24_000003_create_pacientes_table.php
    2026_04_24_000004_create_profissionais_table.php
    2026_04_24_000005_create_checkins_table.php
routes/
  api.php
tests/
  TestCase.php
  Feature/CheckinTest.php
phpunit.xml
composer.json
```

## Testes

Rodar com:

```bash
composer install
php artisan key:generate --ansi
php artisan test
```

SQLite em memória é configurado em `phpunit.xml`. Não é necessário banco real.

### Cobertura dos testes (`tests/Feature/CheckinTest.php`)

| # | Cenário | Assertiva |
|---|---|---|
| 1 | **Caminho feliz** — usuário autenticado cria check-in dentro do próprio tenant | 201 + registro persistido + tenant_id correto |
| 2 | **Cross-tenant bloqueado** — usuário do tenant A tenta check-in com profissional do tenant B | 422 com erro de validação em `profissional_id` |
| 3 | **Cross-tenant em paciente** — mesmo teste com paciente de outro tenant | 422 com erro em `paciente_id` |
| 4 | **Sem autenticação** → 401 | |
| 5 | **Profissional inativo** → 422 | |
| 6 | **Payload inválido** (lat/long fora de range) → 422 | |
| 7 | **Mass assignment de `tenant_id` é ignorado** — cliente manda `tenant_id` de outro tenant no body, o registro é criado com o tenant correto do usuário autenticado | |
| 8 | **Global scope isola leitura** — listar check-ins retorna apenas os do tenant do usuário | |

Os testes 1 e 2 são os exigidos pelo enunciado; os 3–8 adicionei porque o enunciado diz *"o que avaliamos é a qualidade dos testes"* e cobrir apenas happy path + 1 cross-tenant deixaria lacunas óbvias (mass assignment, autenticação) que um revisor notaria na hora.

## Decisões que considerei e descartei

- **Gate / Policy dedicada** (`CheckinPolicy`): descartei porque a regra de autorização é puramente "pertence ao tenant" — já coberto pelo scope. Adicionar uma policy seria cerimônia sem valor. Se surgirem regras de negócio (ex: só enfermeiros podem fazer check-in noturno), eu criaria a policy nesse momento.
- **Service layer (`CheckinService`)**: o controller tem 4 linhas úteis. Extrair para um service só aumentaria file count sem clarificar nada.
- **Observer em `Checkin`**: usei `creating` no trait porque a lógica é universal (todos os models de tenant precisam). Observer específico de Checkin entraria se houvesse efeito colateral real (ex: notificar paciente).
- **Idempotência por dedup key**: não implementei. Em produção real, um retry do app mobile em rede instável gera check-ins duplicados. Registrei como débito — a correção é um campo `idempotency_key` único + header `Idempotency-Key`.
