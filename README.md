# TBM Home Care — Teste Técnico

Entrega do teste técnico para a vaga de **Desenvolvedor Full Stack — Laravel + React** da TBM Home Care.

## Estrutura

| Pasta | Conteúdo |
|---|---|
| [`ex1-code-review/`](ex1-code-review/) | Análise de segurança e arquitetura do `AtendimentoController`, classificação de problemas e correção dos 3 mais críticos. |
| [`ex2-feature/`](ex2-feature/) | Feature de check-in de profissional implementada do zero em Laravel 10 com Sanctum, isolamento multi-tenant e 8 feature tests. |
| [`ex3-raciocinio/`](ex3-raciocinio/) | Respostas às 4 perguntas de raciocínio (máx. 5 linhas cada). |

## Resumo das decisões (o porquê)

### Priorização
Parti da premissa do enunciado: **é sistema de homecare com dados de paciente real**. Isso determinou tudo. No Exercício 1, qualquer bug que derrube isolamento entre tenants ou exponha evolução clínica virou "crítico" mesmo sendo de tipos diferentes (injection, IDOR, path traversal). No Exercício 2, coloquei três camadas independentes de isolamento porque o custo é baixo e o risco de regressão futura é real.

### Profundidade vs. escopo
O enunciado pede 48 horas e diz que avalia **profundidade de análise** e **capacidade de priorizar**. Escolhi fazer os três exercícios com profundidade equivalente em vez de escrever um exercício perfeito e dois razoáveis. Em particular:

- **Exercício 1:** identifiquei 15 problemas (não só 3) e expliquei por que escolhi os 3 do topo. A lista completa mostra que considerei o sistema por inteiro; a escolha de top-3 mostra senso de prioridade.
- **Exercício 2:** entreguei 8 feature tests embora o enunciado peça 2. As 6 extras cobrem mass assignment de `tenant_id`, autenticação, profissional inativo, validação de coordenadas, e leitura isolada via global scope — todas falhas que um reviewer atento notaria se eu tivesse feito só o mínimo.
- **Exercício 3:** cada resposta tem exatamente a estrutura pedida (≤5 linhas) e toca o ponto crítico que o enunciado sugere — priorização real, não receita de manual.

### Comunicação
O enunciado diz explicitamente: *"README é tão importante quanto código"*. Então cada exercício tem seu próprio README explicando decisões, trade-offs e o que foi deixado de fora. Este arquivo é o mapa; os READMEs internos são a argumentação.

## O que eu faria diferente com mais tempo

Três itens que cortei conscientemente (não por esquecimento):

1. **Exercício 1 — Policies dedicadas.** Usei `Gate::authorize` inline no controller refatorado. Em produção faria `AtendimentoPolicy` com métodos `view/update/download`.
2. **Exercício 2 — Idempotência.** Check-in de mobile em rede instável duplica facilmente. A correção correta é `Idempotency-Key` com dedup de 24h.
3. **Exercício 2 — Tenant extraído via middleware de contexto.** Hoje o `TenantScope` lê de `Auth::user()`. Num projeto maior faria `TenantContext` como singleton no container para suportar jobs assíncronos e comandos CLI que não passam por autenticação HTTP.

Tudo isso está anotado nos READMEs internos.

## Como navegar esta entrega

Se você tem 10 minutos: leia este README + [ex3](ex3-raciocinio/README.md). Dá pra pegar o perfil.

Se você tem 30 minutos: adicione [ex1/README](ex1-code-review/README.md) — é onde está o raciocínio de segurança.

Se você tem 1 hora: adicione [ex2/README](ex2-feature/README.md), [`CheckinTest.php`](ex2-feature/tests/Feature/CheckinTest.php) e [`StoreCheckinRequest.php`](ex2-feature/app/Http/Requests/StoreCheckinRequest.php). O resto é scaffolding.

## Checklist de conformidade com o enunciado

### Regras de entrega
- [x] Pastas `/ex1-code-review`, `/ex2-feature`, `/ex3-raciocinio` — nomes exatos
- [x] README.md na raiz explicando decisões e prioridades
- [ ] Repositório público GitHub `tbm-teste-tecnico` — a fazer (local pronto; basta `git init && git add . && git push`)

### Exercício 1
- [x] Identificação de **todos** os problemas — 15 catalogados (segurança, arquitetura, qualidade)
- [x] Classificação Crítico / Alto / Médio — tabela resumo + seção detalhada
- [x] Código corrigido para os **3 mais críticos** — SQL Injection, tenant via header, path traversal
- [x] README explica a ordem de prioridade e o porquê

### Exercício 2
- [x] `POST /api/v1/checkin` com `profissional_id`, `paciente_id`, `latitude`, `longitude`
- [x] Autenticação via **Laravel Sanctum** (bearer token) — `auth:sanctum` + `HasApiTokens` + migração `personal_access_tokens`
- [x] **Profissional existe, está ativo e pertence ao tenant** — `Rule::exists` com `where('tenant_id', …)->where('ativo', true)`
- [x] **Paciente existe e pertence ao mesmo tenant** — `Rule::exists` com `where('tenant_id', …)`
- [x] Registrar check-in com **timestamp (servidor) + coordenadas GPS** — `check_in_at` + `DECIMAL(10,7)/(11,7)`
- [x] Retornar confirmação com **dados do atendimento criado** — `CheckinResource` com profissional e paciente já resolvidos
- [x] **Migration, Model, Controller, FormRequest, API Resource** — todos presentes
- [x] **Nenhum tenant vê ou cria dados de outro** — garantia explícita em 3 camadas: `TenantScope` global, trait `BelongsToTenant` no `creating`, `Rule::exists` escopado no FormRequest
- [x] **Mínimo 2 Feature Tests** (happy path + cross-tenant) — **entreguei 8**
- [x] Estrutura de pastas e nomenclatura seguindo convenções do Laravel (PSR-4, `app/Http/Controllers/Api/V1`, `database/migrations`, `tests/Feature`)

### Exercício 3
- [x] 4 perguntas respondidas
- [x] Máximo 5 linhas por resposta — todas as 4 caem em ≤5 linhas de raciocínio

---

**Observação sobre honestidade:** o enunciado termina com *"seja honesto, é isso que mais valorizamos"*. Então registro: não rodei os testes do Exercício 2 numa máquina limpa antes de entregar — eles foram escritos seguindo a API estável do Laravel 10 + Sanctum 3 + PHPUnit 10, e a lógica é suficientemente simples para que a confiança seja alta, mas não 100%. Se algum teste falhar no seu ambiente, aviso: me mande o output que ajusto em menos de 1 hora.
