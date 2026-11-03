<?php

declare(strict_types=1);

namespace UbModePhp\Mutation;

use Symfony\Component\Process\Process;

/** A live, unreaped session leader reserves the group identity throughout cleanup. */
final class ProcessSupervisor
{
    public static function main(array $arguments): never
    {
        if (posix_setsid() === -1) {
            fwrite(STDERR, "cannot create mutation session\n");
            exit(125);
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): void {
        });
        pcntl_signal(SIGINT, static function (): void {
        });
        $owner = posix_getppid();
        $control = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($control === false) {
            fwrite(STDERR, "cannot create mutation guardian channel\n");
            exit(125);
        }
        $guardian = pcntl_fork();
        if ($guardian === -1) {
            exit(125);
        }
        if ($guardian === 0) {
            fclose($control[0]);
            fwrite($control[1], "ready\n");
            // Only the supervisor owns the other endpoint; a live group member reserves the group, so no old PID is signaled.
            while (!feof($control[1])) {
                fread($control[1], 1);
            }
            self::removeConfiguration($arguments);
            posix_kill(-posix_getpgrp(), SIGKILL);
            exit(125);
        }
        fclose($control[1]);
        stream_set_timeout($control[0], 2);
        if (fgets($control[0]) !== "ready\n") {
            fwrite(STDERR, "mutation guardian did not become ready\n");
            posix_kill(-posix_getpgrp(), SIGKILL);
            exit(125);
        }
        $child = pcntl_fork();
        if ($child === -1) {
            exit(125);
        }
        if ($child === 0) {
            // Never let engine/workers keep the guardian's owner channel alive.
            fclose($control[0]);
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
            $argv = [$arguments[1], ...array_slice($arguments, 3)];
            $argc = count($argv);
            $_SERVER['argv'] = $argv;
            $_SERVER['argc'] = $argc;
            $_SERVER['SCRIPT_FILENAME'] = $argv[0];
            require $argv[0];
            exit(0);
        }
        $finished = false;
        while (true) {
            if (pcntl_waitpid($guardian, $guardianStatus, WNOHANG) !== 0) {
                fwrite(STDERR, "mutation guardian exited unexpectedly\n");
                posix_kill(-posix_getpgrp(), SIGKILL);
                exit(125);
            }
            if (!$finished && pcntl_waitpid($child, $status, WNOHANG) === $child) {
                $exit = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 128 + pcntl_wtermsig($status);
                $temporary = $arguments[2] . '.tmp';
                if (
                    file_put_contents($temporary, json_encode(['exit' => $exit], JSON_THROW_ON_ERROR)) === false
                    || !rename($temporary, $arguments[2])
                ) {
                    fwrite(STDERR, "cannot publish engine exit status\n");
                    exit(125);
                }
                $finished = true;
            }
            if (posix_getppid() !== $owner) {
                // Parent disappeared. This live leader still owns its own group.
                self::removeConfiguration($arguments);
                posix_kill(-posix_getpid(), SIGKILL);
                exit(125);
            }
            usleep(20000);
        }
    }

    private static function removeConfiguration(array $arguments): void
    {
        foreach (array_slice($arguments, 3) as $argument) {
            if (str_starts_with($argument, '--configuration=')) {
                @unlink(substr($argument, strlen('--configuration=')));
            }
        }
    }

    /**
     * Signals only an active owned child; an old pid file never restores ownership.
     *
     * @param Process $process
     * @param ?callable $signal
     * @return bool
     */
    public static function stop(Process $process, ?callable $signal = null): bool
    {
        if (!$process->isRunning() || ($pid = $process->getPid()) === null) {
            return false;
        }
        if (posix_getpgid($pid) !== $pid || posix_getsid($pid) !== $pid) {
            return false;
        }
        $signal ??= posix_kill(...);
        // Do not poll/reap the leader between signals: even a zombie reserves its pid.
        $signal(-$pid, SIGTERM);
        usleep(200000);
        $signal(-$pid, SIGKILL);
        $process->stop(0);
        return true;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ProcessSupervisor::main($argv);
}
