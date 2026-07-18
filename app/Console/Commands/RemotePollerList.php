<?php

namespace App\Console\Commands;

use App\Console\LnmsCommand;
use App\Models\RemotePoller;

class RemotePollerList extends LnmsCommand
{
    protected $name = 'remote-poller:list';

    public function handle(): int
    {
        $pollers = RemotePoller::query()->withCount('services')->orderBy('poller_name')->get();

        if ($pollers->isEmpty()) {
            $this->line(trans('commands.remote-poller:list.none'));

            return 0;
        }

        $this->table(
            ['ID', 'Name', 'URL', 'Enabled', 'Version', 'Last Contact', 'Services', 'Last Error'],
            $pollers->map(fn (RemotePoller $poller) => [
                $poller->id,
                $poller->poller_name,
                $poller->url,
                $poller->enabled ? 'yes' : 'no',
                $poller->version ?? '-',
                $poller->last_contact?->diffForHumans() ?? 'never',
                $poller->services_count,
                $poller->last_error ? \Illuminate\Support\Str::limit($poller->last_error, 40) : '-',
            ])->all()
        );

        return 0;
    }
}
