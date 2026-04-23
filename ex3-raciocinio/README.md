# Exercício 3 — Raciocínio

Cada resposta em até 5 linhas, direto ao ponto.

---

## Pergunta 1 — Listagem do mês leva 12 segundos

Rodo `EXPLAIN` primeiro, mas as três hipóteses de maior probabilidade:
1. **Falta de índice** em `tenant_id`, `data`, `status` — full scan é a causa #1 em sistema Laravel que cresceu sem revisar schema.
2. **N+1 no Eloquent** — listagem acessando `$a->paciente->nome` sem `with()` multiplica o tempo facilmente por 100.
3. **Sem paginação ou `SELECT *` carregando campos TEXT/BLOB** — puxar o mês inteiro satura rede e memória mesmo com índice bom.

---

## Pergunta 2 — Credencial hardcoded + CORS `*` + middleware duplicado

Ordem: **credencial → CORS → middleware**.
1. **Credencial primeiro**: já pode estar comprometida agora. Rotaciono a senha do banco *antes* de remover do código; histórico git continua vulnerável, então BFG/`git filter-repo` se o repo é público.
2. **CORS `*`** depois — é brecha ativa, mas exige vítima autenticada em site malicioso. Fix de 2 linhas.
3. **Middleware duplicado** por último: dívida técnica, não brecha. Entra no próximo refactor de auth.

---

## Pergunta 3 — 2 dias, controller de 1.100 linhas no caminho

Nenhuma das três opções puras. Faço **strangler cirúrgico**: a feature nova entra em classe nova (controller + service), não dentro do monstro. Do arquivo antigo toco só o mínimo para a feature funcionar. O resto vira ticket de débito no README. Refactor big-bang em 2 dias sem teste de regressão é irresponsável; entregar sujo sobre sujo dobra a dívida.

---

## Pergunta 4 — `$fillable = ['*']` em 13 models

**Big-bang**: risco alto de quebrar fluxos que dependem implicitamente de campos. **Gradual**: models ficam vulneráveis durante a migração.
Escolho **gradual, priorizado por risco**: começo pelos models que tocam PHI e financeiro. Para cada: `grep ->create\|->update\|->fill`, whitelist explícita, suite de teste, deploy isolado. Um model por PR, duas semanas para fechar os 13.
