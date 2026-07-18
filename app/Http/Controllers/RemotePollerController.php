<?php

namespace App\Http\Controllers;

use App\ApiClients\RemotePollerApi;
use App\Models\RemotePoller;
use App\Models\RemotePollerService;
use Illuminate\Http\Request;

class RemotePollerController extends Controller
{
    public function index()
    {
        $this->authorize('admin');

        return view('poller.remote', [
            'current_tab' => 'remote',
            'remote_pollers' => RemotePoller::query()
                ->with(['services.device'])
                ->withCount('services')
                ->orderBy('poller_name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('admin');

        $validated = $request->validate([
            'poller_name' => 'required|string|max:255|unique:remote_pollers,poller_name',
            'url' => 'required|url|starts_with:https://',
        ]);

        $poller = new RemotePoller($validated + ['enabled' => true]);

        try {
            $status = (new RemotePollerApi($poller))->status();
            $poller->version = $status['version'] ?? null;
            $poller->last_contact = now();
        } catch (\Exception $e) {
            $poller->last_error = $e->getMessage();
        }

        $poller->save();

        return redirect()->route('poller.remote')->with(
            'poller_message',
            $poller->last_error
                ? __('Remote poller :name added, but it could not be contacted: :error', ['name' => $poller->poller_name, 'error' => $poller->last_error])
                : __('Remote poller :name added', ['name' => $poller->poller_name])
        );
    }

    public function toggle(RemotePoller $remotePoller)
    {
        $this->authorize('admin');

        $remotePoller->enabled = ! $remotePoller->enabled;
        $remotePoller->save();

        return redirect()->route('poller.remote')->with(
            'poller_message',
            __('Remote poller :name :state', [
                'name' => $remotePoller->poller_name,
                'state' => $remotePoller->enabled ? __('enabled') : __('disabled'),
            ])
        );
    }

    public function destroy(RemotePoller $remotePoller)
    {
        $this->authorize('admin');

        RemotePollerService::where('remote_poller_id', $remotePoller->id)->delete();
        $remotePoller->delete();

        return redirect()->route('poller.remote')->with(
            'poller_message',
            __('Remote poller :name removed', ['name' => $remotePoller->poller_name])
        );
    }
}
