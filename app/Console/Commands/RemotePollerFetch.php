<?php

namespace App\Console\Commands;

use App\ApiClients\RemotePollerApi;
use App\Console\LnmsCommand;
use App\Facades\LibrenmsConfig;
use App\Models\Eventlog;
use App\Models\RemotePoller;
use App\Models\RemotePollerService;
use App\Models\Service;
use Carbon\Carbon;
use LibreNMS\Enum\Severity;
use Symfony\Component\Console\Input\InputArgument;

class RemotePollerFetch extends LnmsCommand
{
    protected $name = 'remote-poller:fetch';

    public function __construct()
    {
        parent::__construct();

        $this->addArgument('poller', InputArgument::OPTIONAL);
    }

    public function handle(): int
    {
        $spec = $this->argument('poller');

        if ($spec === null) {
            $pollers = RemotePoller::isEnabled()->get();
        } else {
            $pollers = RemotePoller::where('poller_name', $spec)
                ->orWhere('id', (int) $spec)->get();
        }

        if ($pollers->isEmpty()) {
            $this->error(trans('commands.remote-poller:fetch.no_pollers'));

            return 1;
        }

        $failed = 0;
        foreach ($pollers as $poller) {
            if (! $this->syncPoller($poller)) {
                $failed++;
            }
        }

        return $failed ? 1 : 0;
    }

    private function syncPoller(RemotePoller $poller): bool
    {
        $services = $poller->services()
            ->where('service_disabled', 0)
            ->with('device')
            ->get();

        $checks = $services->map(fn (Service $service) => [
            'id' => $service->service_id,
            'type' => $service->service_type,
            'target' => $service->service_ip ?: ($service->device?->pollerTarget() ?? ''),
            'args' => (string) $service->service_param,
            'interval' => (int) LibrenmsConfig::get('remote_pollers.check_frequency', 60),
        ])->values()->all();

        try {
            $payload = (new RemotePollerApi($poller))->sync($checks);
        } catch (\Exception $e) {
            $poller->fill(['last_error' => $e->getMessage()])->save();
            $this->error(trans('commands.remote-poller:fetch.failed', [
                'poller' => $poller->poller_name,
                'error' => $e->getMessage(),
            ]));

            return false;
        }

        $this->storeResults($poller, $services, $payload);

        $poller->fill([
            'version' => $payload['poller']['version'] ?? $poller->version,
            'last_contact' => Carbon::now(),
            'last_error' => empty($payload['errors']) ? null : implode('; ', $payload['errors']),
        ])->save();

        $this->line(trans('commands.remote-poller:fetch.success', [
            'poller' => $poller->poller_name,
            'checks' => count($checks),
            'results' => count($payload['results'] ?? []),
        ]));

        return true;
    }

    private function storeResults(RemotePoller $poller, $services, array $payload): void
    {
        $services = $services->keyBy('service_id');

        foreach ($payload['results'] ?? [] as $result) {
            /** @var Service $service */
            $service = $services->get($result['id'] ?? 0);
            if (! $service || ! isset($result['status'])) {
                continue;
            }

            $row = RemotePollerService::firstOrNew([
                'remote_poller_id' => $poller->id,
                'service_id' => $service->service_id,
            ]);

            $old_status = $row->exists ? $row->last_status : null;
            $new_status = (int) $result['status'];

            $row->fill([
                'last_status' => $new_status,
                'last_message' => (string) ($result['output'] ?? ''),
                'last_perf' => (string) ($result['perf'] ?? ''),
                'last_checked' => isset($result['checked_at'])
                    ? Carbon::createFromTimestamp((int) $result['checked_at'])
                    : Carbon::now(),
            ])->save();

            if ($old_status !== null && $old_status !== $new_status) {
                Eventlog::log(
                    sprintf(
                        "Service '%s' on remote poller '%s' changed status from %d to %d: %s",
                        $service->service_name ?: $service->service_type,
                        $poller->poller_name,
                        $old_status,
                        $new_status,
                        $result['output'] ?? ''
                    ),
                    $service->device_id,
                    'service',
                    match ($new_status) {
                        0 => Severity::Ok,
                        1 => Severity::Warning,
                        2 => Severity::Error,
                        default => Severity::Notice,
                    }
                );
            }
        }
    }
}
