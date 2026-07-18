<?php

namespace App\Console\Commands;

use App\Console\LnmsCommand;
use App\Models\RemotePoller;
use App\Models\RemotePollerService;
use Symfony\Component\Console\Input\InputArgument;

class RemotePollerRemove extends LnmsCommand
{
    protected $name = 'remote-poller:remove';

    public function __construct()
    {
        parent::__construct();

        $this->addArgument('poller', InputArgument::REQUIRED);
    }

    public function handle(): int
    {
        $spec = $this->argument('poller');
        $poller = RemotePoller::where('poller_name', $spec)
            ->orWhere('id', (int) $spec)->first();

        if (! $poller) {
            $this->error(trans('commands.remote-poller:remove.not_found', ['poller' => $spec]));

            return 1;
        }

        RemotePollerService::where('remote_poller_id', $poller->id)->delete();
        $poller->delete();

        $this->line(trans('commands.remote-poller:remove.removed', ['name' => $poller->poller_name]));

        return 0;
    }
}
