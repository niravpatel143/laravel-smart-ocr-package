<?php

namespace LaravelSmartOCR\Services;

use LaravelSmartOCR\Exceptions\InvalidDocumentException;

/**
 * Validates a URL before any network connection is made.
 *
 * Blocks:
 *   - non-HTTP/HTTPS schemes
 *   - localhost / loopback
 *   - private IPv4 ranges (RFC 1918)
 *   - link-local (169.254.x.x)
 *   - private/reserved IPv6 ranges
 *   - AWS/GCP/Azure metadata service addresses
 */
class UrlSecurityValidator
{
    /** IPv4 CIDR blocks that are always rejected */
    private const BLOCKED_IPV4_CIDRS = [
        '0.0.0.0/8',         // This host
        '10.0.0.0/8',        // Private
        '100.64.0.0/10',     // Shared address space
        '127.0.0.0/8',       // Loopback
        '169.254.0.0/16',    // Link-local / metadata (AWS, GCP, Azure)
        '172.16.0.0/12',     // Private
        '192.0.0.0/24',      // IETF protocol assignments
        '192.168.0.0/16',    // Private
        '198.18.0.0/15',     // Benchmarking
        '198.51.100.0/24',   // TEST-NET-2 (RFC 5737)
        '203.0.113.0/24',    // TEST-NET-3 (RFC 5737)
        '240.0.0.0/4',       // Reserved
        '255.255.255.255/32',
    ];

    /** Well-known metadata service IPs */
    private const BLOCKED_METADATA_IPS = [
        '169.254.169.254', // AWS / GCP / Azure IMDS
        'fd00:ec2::254',   // AWS IPv6 metadata
        '::1',             // IPv6 loopback
    ];

    public function validate(string $url): void
    {
        $parsed = parse_url($url);

        if (! $parsed || empty($parsed['host'])) {
            throw new InvalidDocumentException("Invalid URL: cannot parse host.");
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidDocumentException(
                "Remote URL scheme [{$scheme}] is not allowed. Only http and https are accepted."
            );
        }

        $host = $parsed['host'];

        // Resolve host to IP(s) — catches hostnames that map to private ranges
        $resolvedIps = $this->resolveHost($host);

        foreach ($resolvedIps as $ip) {
            $this->assertIpAllowed($ip);
        }
    }

    /**
     * Validate a redirect target (called per redirect hop).
     */
    public function validateRedirect(string $url): void
    {
        $this->validate($url);
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function resolveHost(string $host): array
    {
        // If host is already an IP, return it directly
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (empty($records)) {
            // Also try gethostbyname as fallback
            $ip = gethostbyname($host);
            if ($ip !== $host) {
                return [$ip];
            }
            // Cannot resolve — block by default
            throw new InvalidDocumentException("Cannot resolve host [{$host}] for remote URL.");
        }

        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    private function assertIpAllowed(string $ip): void
    {
        if (in_array($ip, self::BLOCKED_METADATA_IPS, true)) {
            throw new InvalidDocumentException(
                "Remote URL resolves to a blocked address (metadata service or loopback)."
            );
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::BLOCKED_IPV4_CIDRS as $cidr) {
                if ($this->ipInCidr($ip, $cidr)) {
                    throw new InvalidDocumentException(
                        "Remote URL resolves to a private or reserved IP address."
                    );
                }
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Block IPv6 loopback, link-local, and unique-local
            if (str_starts_with($ip, 'fe80:')   // link-local
                || str_starts_with($ip, 'fc00:') // unique-local
                || str_starts_with($ip, 'fd')    // unique-local
                || $ip === '::1'
            ) {
                throw new InvalidDocumentException(
                    "Remote URL resolves to a private or reserved IPv6 address."
                );
            }
        }
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $ipLong      = ip2long($ip);
        $networkLong = ip2long($network);
        $mask        = ~((1 << (32 - (int) $bits)) - 1);

        return ($ipLong & $mask) === ($networkLong & $mask);
    }
}
