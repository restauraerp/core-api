<?php

namespace App\Support\Site;

/**
 * The DNS questions custom-domain verification asks, behind a class so tests
 * can answer them without the network.
 */
class DnsLookup
{
    /** @return list<string> lower-case CNAME targets, without the trailing dot */
    public function cnames(string $host): array
    {
        $records = @dns_get_record($host, DNS_CNAME) ?: [];

        return array_values(array_map(fn ($r) => strtolower(rtrim((string) ($r['target'] ?? ''), '.')), $records));
    }

    /** @return list<string> IPv4 addresses */
    public function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A) ?: [];

        return array_values(array_map(fn ($r) => (string) ($r['ip'] ?? ''), $records));
    }
}
