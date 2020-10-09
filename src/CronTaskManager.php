<?php

namespace App;

class CronTaskManager
{

    private static $indexFilePath = __DIR__ . '/../../index.php';
    private static $phpExecutable = '/usr/bin/php';
    private static $cronLocksDir = __DIR__ . '/cron_locks';
    private static $tasks = [];

    public static function addTask(string $when, callable $callable, ...$args): void
    {
        $task = ['when' => $when, 'callable' => $callable, 'args' => $args];
        if ($callable instanceof \Closure) {
            $reflection = new \ReflectionFunction($callable);
            $taskId = md5(json_encode([$when, $reflection->getFileName() . $reflection->getStartLine(), $args]));
        } else {
            $taskId = md5(json_encode([$when, $callable, $args]));
        }
        self::$tasks[$taskId] = $task;
    }

    public static function getTasks(): array
    {
        return self::$tasks;
    }

    public static function clearTasks(): void
    {
        self::$tasks = [];
    }

    public static function run(?string $route = null, bool $force = false): void
    {
        if (!self::$tasks) return;
        foreach (self::$tasks as $taskId => $task) {
            self::runTask($route, $taskId, $task['callable'], $task['args'], $task['when'], $force);
        }
    }

    public static function register(bool $runAfterRequest = false): void
    {
        $sapi = php_sapi_name();
        if ($sapi == 'cli') {
            if (isset($_SERVER['argv'][1]) && $_SERVER['argv'][1] == 'cron') {
                $route = $_SERVER['argv'][2] ?? null;
                $force = $_SERVER['argv'][3] ?? false;

                self::run($route, $force);
            }
        } else {
            if ($runAfterRequest) {
                if (in_array($sapi, ['apache', 'apache2filter', 'apache2handler'])) {
                    ob_start();
                    register_shutdown_function(function () {
                        ignore_user_abort(true);
                        session_write_close();
                        header("Content-Encoding: none");
                        header("Content-Length: " . ob_get_length());
                        header("Connection: close");
                        ob_end_flush();
                        flush();
                        self::run();
                    });
                } else if (in_array($sapi, ['cgi-fcgi', 'fpm-fcgi'])) {
                    register_shutdown_function(function () {
                        ignore_user_abort(true);
                        session_write_close();
                        fastcgi_finish_request();
                        self::run();
                    });
                } else {
                    throw new \UnexpectedValueException("Coudn't run cron (unknown SAPI)");
                }
            }
        }
    }

    public static function setIndexFilePath(string $path)
    {
        self::$indexFilePath = $path;
    }

    public static function setPhpExecutable(string $executable)
    {
        self::$phpExecutable = $executable;
    }

    public static function setCronLocksDir(string $dir)
    {
        self::$cronLocksDir = $dir;
    }

    private static function runTask(?string $route, string $taskId, callable $callable, array $args, string $when, bool $force = false): void
    {
        if ($route === null) {
            $execFile = self::$indexFilePath;
            if (!file_exists($execFile)) {
                throw new \RuntimeException('Index file not found');
            }

            $phpExecutable = self::$phpExecutable;
            shell_exec("$phpExecutable $execFile cron $taskId $force > /dev/null 2>&1 &");
            return;
        }

        if ($route != $taskId) return;

        if (!is_dir(self::$cronLocksDir)) {
            mkdir(self::$cronLocksDir, 0777, true);
        }

        $taskFile = self::$cronLocksDir . '/' . $taskId;
        $taskUpdateTimestamp = file_get_contents($taskFile);
        $taskUpdateDate = null;
        if ($taskUpdateTimestamp) {
            $taskUpdateDate = (new \DateTime('now'))->setTimestamp($taskUpdateTimestamp);
        }

        $readyToStart = $force || self::readyToStart($taskUpdateDate, $when);
        if ($readyToStart) {
            $lockedFile = fopen($taskFile, "w+");
            if (!$lockedFile) {
                throw new \RuntimeException("Can't open cron task file");
            }

            if (!flock($lockedFile, LOCK_EX | LOCK_NB)) {
                die("cron is busy");
            }

            $taskDone = false;
            register_shutdown_function(function () use ($lockedFile, $taskId, &$taskDone) {
                flock($lockedFile, LOCK_UN);
                fclose($lockedFile);

                if (!$taskDone) {
                    throw new \RuntimeException('Cron task ' . $taskId . ' has failed');
                }
            });

            fwrite($lockedFile, time());
            call_user_func($callable, ...$args);
            $taskDone = true;
        }
    }

    private static function readyToStart(?\DateTime $taskUpdateDate, string $whenToStart): bool
    {
        $readyToStart = false;
        if (!$taskUpdateDate) {
            $readyToStart = true;
        } else {
            if (strpos($whenToStart, ':') !== false) {
                list($hours, $minutes, $seconds) = explode(':', $whenToStart);

                $now = new \DateTime('now');
                $nearestPastScheduledTime = clone $now;
                $nearestPastScheduledTime->setTime($hours, $minutes, $seconds);
                if ($nearestPastScheduledTime > $now)
                    $nearestPastScheduledTime->sub(new \DateInterval('P1D'));

                $readyToStart = $taskUpdateDate < $nearestPastScheduledTime;
            } else {
                $readyToStart = $taskUpdateDate <= new \DateTime('-' . $whenToStart);
            }
        }

        return $readyToStart;
    }
}