# Como rodar os testes

Este diretório contém **apenas os arquivos da feature** — não é um install completo do Laravel. Foi uma escolha deliberada para manter o repositório enxuto e focado no que o exercício pede (arquitetura, isolamento multi-tenant, qualidade dos testes).

## Caminho recomendado (mais rápido)

```bash
# 1. Criar um Laravel 10 limpo fora deste repo
composer create-project laravel/laravel:^10 tbm-checkin
cd tbm-checkin

# 2. Instalar Sanctum
composer require laravel/sanctum:^3.3
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"

# 3. Copiar os arquivos deste exercício por cima do projeto
#    (sobrescreve composer.json, phpunit.xml, routes/api.php e adiciona o resto)
cp -r /caminho/para/ex2-feature/* ./

# 4. Rodar os testes
composer install
php artisan test
```

Saída esperada: **8 testes, todos verdes**, usando SQLite em memória.

## Observação

Mantive `composer.json`, `phpunit.xml`, `routes/api.php` e `tests/TestCase.php` mesmo sabendo que o `laravel new` já os traz — quem clonar este repo e aplicar sobre um install existente precisa ver o que foi alterado. Qualquer coisa fora das pastas `app/`, `database/`, `routes/api.php` e `tests/Feature/CheckinTest.php` é arquivo padrão do skeleton do Laravel; não é entrega deste exercício.

## Configuração do Sanctum

Assumo que `app/Http/Kernel.php` aplica o middleware `EnsureFrontendRequestsAreStateful` ao grupo `api` (default do `php artisan install:api` em Laravel 11+, ou após `vendor:publish` em Laravel 10). Para este teste em particular os testes usam `Sanctum::actingAs`, que não depende desse middleware — então os testes passam mesmo sem a configuração completa.

## Checklist do que você deve conseguir observar

- [ ] `php artisan test` → 8 passed
- [ ] `php artisan route:list` → mostra `POST api/v1/checkin` com middleware `auth:sanctum`
- [ ] `php artisan migrate:status` → 6 migrações listadas
