<?php

namespace App\Libraries;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;

/**
 * The visitor's IP address, for rate limits and the "previous login" shown to the owner.
 *
 * Without florist.trustedProxies it is simply REMOTE_ADDR, and X-Forwarded-For is ignored. With trusted proxies (a
 * CDN in front of the site) it reads X-Forwarded-For the way Laravel/Symfony did: from the right, skipping the
 * trusted proxies, so the first address no trusted proxy vouches for is the client. CodeIgniter's own
 * getIPAddress() takes the LEFTMOST entry, which the visitor can write themselves ("X-Forwarded-For: 1.2.3.4"),
 * and would let them pick a new IP for every guess.
 */
final class ClientIp
{
    public static function of(RequestInterface $request): string
    {
        $remote = (string) ($request->getServer('REMOTE_ADDR') ?? '');
        if (! self::valid($remote)) {
            return '0.0.0.0';
        }
        $proxies = config('App')->proxyIPs;
        $header  = self::trustedHeader($remote, $proxies);
        if ($header === null || ! $request instanceof IncomingRequest) {
            return $remote;
        }

        $chain = [];
        foreach (explode(',', $request->getHeaderLine($header)) as $part) {
            $ip = trim($part);
            if (self::valid($ip)) {
                $chain[] = $ip;
            }
        }
        $chain[] = $remote;

        // Right to left: the first address that isn't one of our proxies is the client.
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (self::trustedHeader($chain[$i], $proxies) === null) {
                return $chain[$i];
            }
        }

        // Every hop is a trusted proxy: the leftmost is as close to the client as we can get.
        return $chain[0];
    }

    /**
     * The header a trusted proxy at this address passes the client IP in, or null when the address isn't trusted.
     *
     * @param array<string, string> $proxies Config\App::$proxyIPs (IP or CIDR range → header name)
     */
    private static function trustedHeader(string $ip, array $proxies): ?string
    {
        foreach ($proxies as $range => $header) {
            if (self::inRange($ip, (string) $range)) {
                return $header;
            }
        }

        return null;
    }

    private static function inRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];
        $ipBin           = @inet_pton($ip);
        $subnetBin       = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $maxBits = strlen($ipBin) * 8;
        $bits    = $bits === null ? $maxBits : (int) $bits;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $bytes     = intdiv($bits, 8);
        $remainder = $bits % 8;
        if (strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    private static function valid(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
