<?php

declare(strict_types=1);

namespace Kanti\SshTunnel;

use InvalidArgumentException;

final readonly class Remote
{
    private function __construct(public string $path)
    {
    }

    public static function tcp(int $remotePort, string $remoteHost = '127.0.0.1'): self
    {
        if (!$remoteHost) {
            throw new InvalidArgumentException('Invalid remoteHost given. Must be a non-empty string');
        }

        if ($remotePort < 1 || $remotePort > 65535) {
            throw new InvalidArgumentException('Invalid remotePort given. Must be in the range of 1-65535 (given: ' . $remotePort . ')');
        }

        return new self($remoteHost . ':' . $remotePort);
    }

    public static function socket(string $socketFilePath): self
    {
        if (!$socketFilePath) {
            throw new InvalidArgumentException('Invalid socketFilePath given. Must be a non-empty string');
        }

        return new self($socketFilePath);
    }
}
