<?php

declare(strict_types=1);

namespace Kanti\SshTunnel;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

use function in_array;
use function sprintf;

use const PHP_EOL;

final class SshTunnel
{
    public ?int $usedPort = null;

    /**
     * ssh password not implemented by design (use ssh keys https://lmddgtfy.net/?q=how%20to%20use%20ssh%20Keys)
     */
    public function __construct(
        private readonly string $sshHost,
        private readonly Remote $remote,
        private readonly string $sshUser = '',
        private readonly int $sshPort = 22,
        /**
         * Local port to bind to. If not set, a free port will be used.
         * if given an port is already in use, an exception will be thrown
         * @var int|null
         */
        private readonly ?int $localPort = null,
        /**
         * Time after which the master connection will be automatically closed if no connections are open
         * unites:
         *     ⟨none⟩  seconds
         *     s | S   seconds
         *     m | M   minutes
         *     h | H   hours
         *     d | D   days
         *     w | W   weeks
         *
         * examples:
         *     600     600 seconds (10 minutes)
         *     10m     10 minutes
         *     1h30m   1 hour 30 minutes (90 minutes)
         *
         * @see https://man7.org/linux/man-pages/man5/sshd_config.5.html#TIME_FORMATS
         * @var string
         */
        private readonly string $autoDisconnectTimeout = '10s',
        /**
         * If true, the tunnel will be closed after the Garbage Collector destroyed this object
         *
         * @var bool
         */
        private readonly bool $disconnectAfterTunnelDestroyed = true,
        private readonly bool $debugMasterOutput = false,
    ) {
        if (!preg_match('/^(\d+[smhdw])+$/i', $this->autoDisconnectTimeout)) {
            throw new InvalidArgumentException('Invalid autoDisconnectTimeout format given. Must be in the format of ⟨number⟩[s|m|h|d|w] (given: "' . $this->autoDisconnectTimeout . '")');
        }

        if ($this->localPort && ($this->localPort < 1 || $this->localPort > 65535)) {
            throw new InvalidArgumentException('Invalid localPort given. Must be in the range of 1-65535 (given: ' . $this->localPort . ')');
        }

        if ($this->sshPort && ($this->sshPort < 1 || $this->sshPort > 65535)) {
            throw new InvalidArgumentException('Invalid sshPort given. Must be in the range of 1-65535 (given: ' . $this->sshPort . ')');
        }
    }

    /**
     * @return int returns the local port
     * @throws RuntimeException
     */
    public function start(): int
    {
        $this->startMasterIfNotExists();

        $this->usedPort ??= $this->localPort ?? $this->getFreePort();

        $forwardOption = $this->getForwardPart();
        $command = 'ssh -O forward' . $forwardOption . $this->getControlSocketOptions() . $this->destinationPart();
        Process::fromShellCommandline($command)->mustRun();

        register_shutdown_function([$this, 'stop']);

        return $this->usedPort;
    }

    private function startMasterIfNotExists(): void
    {
        if ($this->isMasterRunning()) {
            echo 'SSH master already running.' . PHP_EOL;
            return;
        }

        echo 'Starting SSH master... ';
        $time = hrtime(true);

        $command = 'ssh ' . $this->getControlSocketOptions() . $this->destinationPart();
        Process::fromShellCommandline($command)->mustRun(function ($type, $buffer): void {
            if (!$this->debugMasterOutput) {
                return;
            }

            echo '    ' . implode('    ' . PHP_EOL, explode(PHP_EOL, $buffer));
        });

        $time = hrtime(true) - $time;
        echo sprintf('started. %.3f', $time / 1_000_000_000) . 's' . PHP_EOL;

        if ($this->isMasterRunning()) {
            return;
        }

        throw new RuntimeException('Could not start SSH master');
    }

    private function getFreePort(int $startPort = 49152, int $maxPort = 65535): int
    {
        for ($port = $startPort; $port <= $maxPort; $port++) {
            $socket = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);

            if ($socket) {
                fclose($socket); // Port is free
                return $port;
            }
        }

        throw new RuntimeException('Could not find a free port between ' . $startPort . ' and ' . $maxPort);
    }

    private function getForwardPart(): string
    {
        if (!$this->usedPort) {
            throw new RuntimeException('No local port set');
        }

        return sprintf(' -o ExitOnForwardFailure=yes -L%d:%s', $this->usedPort, $this->remote->path);
    }

    private function getControlSocketOptions(): string
    {
        return ' -v -f -N -o ControlMaster=auto -o ControlPersist=' . $this->autoDisconnectTimeout . ' -S ~/.ssh/master-%r@%h:%p ';
    }

    private function destinationPart(): string
    {
        $result = '';
        if ($this->sshPort !== 22) {
            $result = ' -p ' . $this->sshPort;
        }

        if (!$this->sshUser) {
            return $result . ' ' . $this->sshHost;
        }

        return $result . ' ' . $this->sshUser . '@' . $this->sshHost;
    }

    private function isMasterRunning(): bool
    {
        $process = Process::fromShellCommandline('ssh -O check -S ~/.ssh/master-%r@%h:%p ' . $this->destinationPart());
        $process->run();
        if (!in_array($process->getExitCode(), [0, 255])) {
            throw new ProcessFailedException($process);
        }

        $isSuccessful = $process->isSuccessful();
        if (!$isSuccessful) {
            $this->usedPort = null;
        }

        return $isSuccessful;
    }

    public function __destruct()
    {
        if ($this->disconnectAfterTunnelDestroyed) {
            $this->stop();
        }
    }

    public function stop(): void
    {
        if (!$this->isMasterRunning()) {
            return;
        }

        if (!$this->usedPort) {
            return;
        }

        $forwardOption = $this->getForwardPart();
        $command = 'ssh -O cancel' . $forwardOption . $this->getControlSocketOptions() . $this->destinationPart();
        Process::fromShellCommandline($command)->mustRun();
        $this->usedPort = null;
    }

    /**
     * this should not be needed in any way. The master is terminated automatically after 5s of inactivity
     */
    public function stopMaster(): void
    {
        if (!$this->isMasterRunning()) {
            return;
        }

        $command = 'ssh -O exit ' . $this->getControlSocketOptions() . $this->destinationPart();
        Process::fromShellCommandline($command)->mustRun();
    }
}
