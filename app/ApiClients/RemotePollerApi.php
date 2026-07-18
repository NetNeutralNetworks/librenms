<?php

/**
 * RemotePollerApi.php
 *
 * HTTP client for the LibreNMS remote service poller agent. All communication
 * is authenticated with mutual TLS: the poller's server certificate is
 * verified against the configured CA and our client certificate is presented
 * for the poller to verify.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 */

namespace App\ApiClients;

use App\Facades\LibrenmsConfig;
use App\Models\RemotePoller;
use Illuminate\Http\Client\PendingRequest;
use LibreNMS\Util\Http;

class RemotePollerApi
{
    public function __construct(
        protected RemotePoller $poller,
    ) {
    }

    /**
     * Push the desired check list to the remote poller and fetch the latest
     * cached results in the same round trip.
     *
     * @param  array  $checks  list of check definitions: id, type, target, args, interval
     * @return array decoded response: poller info, results, errors
     *
     * @throws \Illuminate\Http\Client\RequestException|\Illuminate\Http\Client\ConnectionException
     */
    public function sync(array $checks): array
    {
        return $this->client()
            ->post('/api/v1/sync', ['checks' => $checks])
            ->throw()
            ->json();
    }

    /**
     * Fetch poller identity/health information.
     *
     * @throws \Illuminate\Http\Client\RequestException|\Illuminate\Http\Client\ConnectionException
     */
    public function status(): array
    {
        return $this->client()
            ->get('/api/v1/status')
            ->throw()
            ->json();
    }

    protected function client(): PendingRequest
    {
        $client = Http::client()
            ->baseUrl(rtrim($this->poller->url, '/'))
            ->timeout((int) LibrenmsConfig::get('remote_pollers.timeout', 15))
            ->acceptJson();

        $options = [];
        if ($ca = LibrenmsConfig::get('remote_pollers.tls_ca')) {
            $options['verify'] = $ca;
        }
        if ($cert = LibrenmsConfig::get('remote_pollers.tls_cert')) {
            $options['cert'] = $cert;
        }
        if ($key = LibrenmsConfig::get('remote_pollers.tls_key')) {
            $options['ssl_key'] = $key;
        }

        return $options ? $client->withOptions($options) : $client;
    }
}
