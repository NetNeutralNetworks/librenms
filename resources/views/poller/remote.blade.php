@extends('poller.index')

@section('title', __('Remote Pollers'))

@section('content')

@parent

@if(session('poller_message'))
<div class="alert alert-info">{{ session('poller_message') }}</div>
@endif

<div class="panel panel-default">
    <div class="panel-heading"><strong>{{ __('Add Remote Poller') }}</strong></div>
    <div class="panel-body">
        <form method="post" action="{{ route('poller.remote.store') }}" class="form-inline">
            {{ csrf_field() }}
            <div class="form-group">
                <label for="poller_name" class="sr-only">{{ __('Name') }}</label>
                <input type="text" name="poller_name" id="poller_name" class="form-control" placeholder="{{ __('Name') }}" value="{{ old('poller_name') }}" required>
            </div>
            <div class="form-group" style="margin-left: 8px;">
                <label for="url" class="sr-only">{{ __('URL') }}</label>
                <input type="text" name="url" id="url" class="form-control" size="40" placeholder="https://poller1.example.com:8443" value="{{ old('url') }}" required>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-left: 8px;">{{ __('Add') }}</button>
            @if($errors->any())
            <span class="text-danger" style="margin-left: 8px;">{{ $errors->first() }}</span>
            @endif
        </form>
        <small class="text-muted">{{ __('The poller must present a certificate signed by the configured CA (remote_pollers.tls_ca) and this server authenticates with its client certificate (remote_pollers.tls_cert / remote_pollers.tls_key).') }}</small>
    </div>
</div>

<div class="table-responsive">
    <table class="table table-striped table-bordered table-hover table-condensed">
        <tr>
            <th>{{ __('ID') }}</th>
            <th>{{ __('Name') }}</th>
            <th>{{ __('URL') }}</th>
            <th>{{ __('Status') }}</th>
            <th>{{ __('Version') }}</th>
            <th>{{ __('Last Contact') }}</th>
            <th>{{ __('Services') }}</th>
            <th>{{ __('Last Error') }}</th>
            <th>{{ __('Actions') }}</th>
        </tr>
        @forelse ($remote_pollers as $poller)
        <tr>
            <td>{{ $poller->id }}</td>
            <td>{{ $poller->poller_name }}</td>
            <td>{{ $poller->url }}</td>
            <td>
                @if(! $poller->enabled)
                    <span class="label label-default">{{ __('disabled') }}</span>
                @elseif($poller->last_contact && $poller->last_contact->gt(now()->subMinutes(5)))
                    <span class="label label-success">{{ __('online') }}</span>
                @else
                    <span class="label label-danger">{{ __('unreachable') }}</span>
                @endif
            </td>
            <td>{{ $poller->version ?? '-' }}</td>
            <td>{{ $poller->last_contact?->diffForHumans() ?? __('never') }}</td>
            <td>{{ $poller->services_count }}</td>
            <td>{{ \Illuminate\Support\Str::limit($poller->last_error ?? '', 60) }}</td>
            <td>
                <form method="post" action="{{ route('poller.remote.toggle', $poller) }}" style="display: inline;">
                    {{ csrf_field() }}
                    <button type="submit" class="btn btn-xs {{ $poller->enabled ? 'btn-warning' : 'btn-success' }}">
                        {{ $poller->enabled ? __('Disable') : __('Enable') }}
                    </button>
                </form>
                <form method="post" action="{{ route('poller.remote.destroy', $poller) }}" style="display: inline;" onsubmit="return confirm('{{ __('Remove this remote poller and all of its check assignments?') }}');">
                    {{ csrf_field() }}
                    {{ method_field('DELETE') }}
                    <button type="submit" class="btn btn-xs btn-danger">{{ __('Delete') }}</button>
                </form>
            </td>
        </tr>
        @empty
        <tr>
            <td colspan="9">{{ __('No remote pollers have been registered yet.') }}</td>
        </tr>
        @endforelse
    </table>
</div>

@foreach ($remote_pollers as $poller)
    @if($poller->services->isNotEmpty())
    <div class="panel panel-default">
        <div class="panel-heading"><strong>{{ __('Checks on :name', ['name' => $poller->poller_name]) }}</strong></div>
        <div class="table-responsive">
            <table class="table table-striped table-condensed">
                <tr>
                    <th>{{ __('Service') }}</th>
                    <th>{{ __('Device') }}</th>
                    <th>{{ __('Type') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Message') }}</th>
                    <th>{{ __('Last Checked') }}</th>
                </tr>
                @foreach ($poller->services as $service)
                <tr>
                    <td>{{ $service->service_name ?: $service->service_type }}</td>
                    <td>
                        @if($service->device)
                        <a href="{{ url('device/device=' . $service->device->device_id . '/tab=services/') }}">{{ $service->device->displayName() }}</a>
                        @endif
                    </td>
                    <td>{{ $service->service_type }}</td>
                    <td>
                        @php($status_map = [0 => ['success', __('OK')], 1 => ['warning', __('Warning')], 2 => ['danger', __('Critical')]])
                        @php([$label, $text] = $status_map[$service->pivot->last_status] ?? ['default', __('Unknown')])
                        <span class="label label-{{ $label }}">{{ $text }}</span>
                    </td>
                    <td>{{ $service->pivot->last_message }}</td>
                    <td>{{ $service->pivot->last_checked ? \Carbon\Carbon::parse($service->pivot->last_checked)->diffForHumans() : __('never') }}</td>
                </tr>
                @endforeach
            </table>
        </div>
    </div>
    @endif
@endforeach

@endsection
