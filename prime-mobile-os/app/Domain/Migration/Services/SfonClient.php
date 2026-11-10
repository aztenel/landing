<?php

namespace App\Domain\Migration\Services;

use App\Support\Exceptions\UserFacingException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * sfon Open API, read only (spec https://open-api.sfon.kz/open-api/v3/api-docs; TZ 00-sfon-paritet §7). The key comes
 * only from the server .env (services.sfon.key). Not more than 2 requests a second; «429 слишком много запросов» —
 * a pause of 60 s and one more try; pages of 100.
 */
class SfonClient
{
    public function configured(): bool
    {
        return (bool) config('services.sfon.key');
    }

    private function http(): PendingRequest
    {
        if (! $this->configured()) {
            throw UserFacingException::because('sfon_key', __('Ключ API sfon не задан. Его вписывают в файл .env на сервере (SFON_API_KEY), в чат не присылают.'));
        }

        return Http::baseUrl(rtrim((string) config('services.sfon.url'), '/'))->withHeaders(['X-API-Key' => (string) config('services.sfon.key')])
            ->acceptJson()->timeout(30);
    }

    private function call(string $method, string $path, array $query = [], ?array $body = null): mixed
    {
        for ($try = 0; $try < 2; $try++) {
            usleep(((int) config('services.sfon.pause_ms', 500)) * 1000);
            $req = $this->http();
            $res = $method === 'GET' ? $req->get($path, $query)
                : $req->withQueryParameters($query)->withBody(json_encode((object) ($body ?? []), JSON_UNESCAPED_UNICODE), 'application/json')->post($path);
            if ($res->status() === 429 && $try === 0) {
                sleep(app()->runningUnitTests() ? 0 : 60);

                continue;
            }
            if ($res->status() === 401) {
                throw UserFacingException::because('sfon_401', __('sfon не принял ключ API: нет, неверный, отключён или истёк.'));
            }
            if (! $res->successful()) {
                throw UserFacingException::because('sfon_http', __('sfon ответил ошибкой :s на :p', ['s' => $res->status(), 'p' => $path]));
            }

            return $res->json();
        }
        throw UserFacingException::because('sfon_429', __('sfon просит подождать (слишком много запросов). Повторите через минуту.'));
    }

    public function company(): array
    {
        return (array) $this->call('GET', '/v1/company');
    }

    /** @return list<array> */
    public function dictionary(string $what, array $query = []): array
    {
        $r = $this->call('GET', '/v1/dictionaries/'.$what, $query);

        return array_values((array) ($r['content'] ?? $r));
    }

    /** All products in stock by the filter, page by page (new — groups, used — one by one with productId). @return list<array> */
    public function products(array $filter = []): array
    {
        $out = [];
        for ($page = 0; $page < 500; $page++) {
            $r = (array) $this->call('POST', '/v1/products/search', ['page' => $page, 'size' => 100], $filter);
            array_push($out, ...array_values((array) ($r['content'] ?? [])));
            if (($r['last'] ?? true) || ($r['content'] ?? []) === []) {
                break;
            }
        }

        return $out;
    }

    public function used(int $productId): array
    {
        return (array) $this->call('GET', '/v1/products/old/'.$productId);
    }
}
