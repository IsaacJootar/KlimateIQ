<?php

namespace App\Services\Earthdata;

use App\Support\Concerns\ResolvesCaBundle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * NASA's AppEEARS API for point-sampled MODIS products (e.g. vegetation index).
 *
 * Unlike NASA POWER, this is a task-based API: submit a request, poll until it finishes,
 * then download the result — not a single request/response round trip. In practice a
 * point request over a handful of coordinates finishes in under a minute, but the
 * contract is genuinely asynchronous, so callers must poll rather than assume completion.
 */
class AppEearsClient
{
    use ResolvesCaBundle;

    private const BASE_URL = 'https://appeears.earthdatacloud.nasa.gov/api';

    public function __construct(
        private readonly ?string $username,
        private readonly ?string $password,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            username: config('services.earthdata.username'),
            password: config('services.earthdata.password'),
        );
    }

    public function isConfigured(): bool
    {
        return ! empty($this->username) && ! empty($this->password);
    }

    /**
     * Submit a single-point task and return its task_id.
     */
    public function submitPointTask(string $taskName, string $product, string $layer, string $pointId, float $latitude, float $longitude, Carbon $start, Carbon $end): string
    {
        $response = $this->client()
            ->withToken($this->token())
            ->post(self::BASE_URL.'/task', [
                'task_type' => 'point',
                'task_name' => $taskName,
                'params' => [
                    'dates' => [['startDate' => $start->format('m-d-Y'), 'endDate' => $end->format('m-d-Y')]],
                    'layers' => [['product' => $product, 'layer' => $layer]],
                    'coordinates' => [
                        ['id' => $pointId, 'latitude' => $latitude, 'longitude' => $longitude],
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("AppEEARS task submission failed with status {$response->status()}: {$response->body()}");
        }

        return $response->json('task_id');
    }

    /**
     * Poll until the task is done (or errored/timed out), checking every $pollSeconds up
     * to $timeoutSeconds total. Returns false rather than throwing on timeout — a slow
     * task for one region/week must not abort the ones around it.
     */
    public function waitUntilDone(string $taskId, int $timeoutSeconds = 240, int $pollSeconds = 5): bool
    {
        $deadline = now()->addSeconds($timeoutSeconds);

        while (now()->lessThan($deadline)) {
            try {
                $response = $this->client()->withToken($this->token())->get(self::BASE_URL."/status/{$taskId}");
            } catch (ConnectionException) {
                // A single dropped connection over many polls (up to $timeoutSeconds worth)
                // shouldn't abort an otherwise-fine task — just try again next tick.
                sleep($pollSeconds);

                continue;
            }

            if ($response->failed()) {
                throw new RuntimeException("AppEEARS status check failed with status {$response->status()} for task {$taskId}.");
            }

            $status = $response->json('status');

            if ($status === 'done') {
                return true;
            }

            if (in_array($status, ['error', 'expired'], true)) {
                return false;
            }

            sleep($pollSeconds);
        }

        return false;
    }

    /**
     * Download the task's CSV results file (point requests always produce exactly one).
     */
    public function fetchResultsCsv(string $taskId): ?string
    {
        $bundle = $this->client()->withToken($this->token())->get(self::BASE_URL."/bundle/{$taskId}");

        if ($bundle->failed()) {
            throw new RuntimeException("AppEEARS bundle lookup failed with status {$bundle->status()} for task {$taskId}.");
        }

        $csvFile = collect($bundle->json('files', []))->firstWhere('file_type', 'csv');

        if ($csvFile === null) {
            return null;
        }

        $file = $this->client()->withToken($this->token())->get(self::BASE_URL."/bundle/{$taskId}/{$csvFile['file_id']}");

        if ($file->failed()) {
            throw new RuntimeException("AppEEARS file download failed with status {$file->status()} for task {$taskId}.");
        }

        return $file->body();
    }

    /**
     * Bearer token, cached just under its 48-hour expiry so a weekly ingestion run
     * doesn't log in fresh for every region/source combination.
     */
    private function token(): string
    {
        return Cache::remember('appeears_bearer_token', now()->addHours(47), function () {
            if (! $this->isConfigured()) {
                throw new RuntimeException('NASA Earthdata credentials are not configured.');
            }

            $response = $this->client()
                ->withBasicAuth($this->username, $this->password)
                ->withHeaders(['Content-Length' => '0'])
                ->post(self::BASE_URL.'/login');

            if ($response->failed()) {
                throw new RuntimeException("AppEEARS login failed with status {$response->status()}: {$response->body()}");
            }

            return $response->json('token');
        });
    }

    private function client()
    {
        return Http::withOptions(['verify' => $this->caBundle()])->timeout(30);
    }
}
