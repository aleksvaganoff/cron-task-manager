# CronTaskManager

[![GitHub license](https://img.shields.io/badge/license-MIT-blue.svg)](./LICENSE) ![PHP version](https://img.shields.io/badge/php-%3E%3D7.2-blue)

Simple library for scheduling cron tasks from PHP code. The main idea is to add possibility to register cron tasks directly from the application. 
It also helps to avoid multiple execution of the same task in the same time (for example, if the task is too slow).

## Features

- Run cron tasks based on user requests (no crontab needed)
- Run cron tasks using crontab
- Prevents multiple execution of the same task in the same time


## Installation

After you have installed composer, run `composer require gavex/cron-task-manager` or add the package to your `composer.json`

```
{
    "require": {
        "gavex/cron-task-manager": "1.*"
    }
}
```

## Example how to use

```php
require_once __DIR__ . '/../vendor/autoload.php'; 

\App\CronTaskManager::setIndexFilePath(__FILE__);
\App\CronTaskManager::setCronLocksDir(__DIR__.'/cron_locks');

\App\CronTaskManager::addTask('1 minute', function() {
    //do something
});

\App\CronTaskManager::addTask('10:00:00', ['Foo', 'bar']);

\App\CronTaskManager::register($runAfterRequest = true);
```


### License

Application is [MIT licensed](./LICENSE).
