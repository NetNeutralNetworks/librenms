<?php

namespace App\Console\Commands;

use App\ApiClients\RemotePollerApi;
use App\Console\LnmsCommand;
use App\Models\RemotePoller;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class RemotePollerAdd extends LnmsCommand
{
    protected $name = 'remote-poller:add';

    public function __construct()
    {
        parent::__construct();

        $this->addArgument('name', InputArgument::REQUIRED);
        $this->addArgument('url', InputArgument::REQUIRED);
        $this->addOption('disabled', null, InputOption::VALUE_NONE);
        $this->addOption('skip-check', null, InputOption::VALUE_NONE);
    }

    public function handle(): int
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:remote_pollers,poller_name',
            'url' => 'required|url|starts_with:https://',
        ]);

        $poller = new RemotePoller([
            'poller_name' => $this->argument('name'),
            'url' => $this->argument('url'),
            'enabled' => ! $this->option('disabled'),
        ]);

        if (! $this->option('skip-check')) {
            try {
                $status = (new RemotePollerApi($poller))->status();
                $poller->version = $status['version'] ?? null;
                $poller->last_contact = now();
            } catch (\Exception $e) {
                $this->error(trans('commands.remote-poller:add.unreachable', ['error' => $e->getMessage()]));

                return 1;
            }
        }

        $poller->save();
        $this->line(trans('commands.remote-poller:add.added', [
            'name' => $poller->poller_name,
            'id' => $poller->id,
        ]));

        return 0;
    }
}
