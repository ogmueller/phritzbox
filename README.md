Phritzbox
=========

This application is a companion to AVM's Fritz!Box and smart home devices.

Features
--------

* Manage and monitor connected smart home devices via CLI
* Collect, visualize and analyze smart home data (e.g. temperature, energy consumption, ...)


CLI
---

All devices can be managed via CLI using
```bash
$ bin/console COMMAND
```

The available COMMANDs are:

```
smart:device:list                       List all available SmartHome devices
smart:device:stats                      Show basic information of a SmartHome devices
smart:src:comfort                       Read setpoint for comfort temperature of a SmartHome smart radiator control [°C]
smart:src:off                           Turn off a SmartHome smart radiator control
smart:src:on                            Turn on a SmartHome smart radiator control
smart:src:saving                        Read setpoint for saving temperature of a SmartHome smart radiator control [°C]
smart:src:setpoint                      Read or set setpoint temperature of a SmartHome smart radiator control [°C]
smart:switch:energy                     Read energy quantity delivered over a SmartHome outlet [Wh]
smart:switch:list                       List all known SmartHome outlets
smart:switch:name                       Get name of a SmartHome outlet
smart:switch:off                        Turn off a SmartHome outlet
smart:switch:on                         Turn on a SmartHome outlet
smart:switch:power                      Read current power consumption of a SmartHome outlet [mW]
smart:switch:present                    Determine availability of a SmartHome outlet
smart:switch:toggle                     Toggle power state of a SmartHome outlet
smart:temperature                       Read temperature of a SmartHome device [°C]
smart:template:list                     List all available SmartHome templates
```

Collect data
------------

The best way to collect all data from all devices is using a cron task. It will automatically and regularly collect all necessary data and stores it to the database. The task itself is going to fetch new data only. So you can't execute this task too often. The Fritz!Box is caching smart home device data, but depending on the type of data, caching time is limited. Temperature for example is stored for 24h. If it hasn't been fetch by then, it is gone. So it is recommended to execute the cron at least every couple of hours. If you want to the most current data, the cron needs to be executed more often.

Replace `path-to-phritzbox` with your phritzbox file system path and put the following content into your [crontab](https://tecadmin.net/crontab-in-linux-with-20-examples-of-cron-schedule/):
```cron
# collect smart home device data twice an hour
*/30 *  * * *   /path-to-phritzbox/bin/console cron:smart:save
```





Symfony Demo Application
========================

The "Symfony Demo Application" is a reference application created to show how
to develop applications following the [Symfony Best Practices][1].

Requirements
------------

  * PHP 7.1.3 or higher;
  * PDO-SQLite PHP extension enabled;
  * and the [usual Symfony application requirements][2].

Installation
------------

Install the [Symfony client][4] binary and run this command:

```bash
$ symfony new --demo my_project
```

Alternatively, you can use Composer:

```bash




$ composer create-project symfony/symfony-demo my_project
```

Usage
-----

There's no need to configure anything to run the application. If you have
installed the [Symfony client][4] binary, run this command to run the built-in
web server and access the application in your browser at <http://localhost:8000>:

```bash
$ cd my_project/
$ symfony serve
```

If you don't have the Symfony client installed, run `php bin/console server:run`.
Alternatively, you can [configure a web server][3] like Nginx or Apache to run
the application.

Tests
-----

Execute this command to run tests:

```bash
$ cd my_project/
$ ./bin/phpunit
```

[1]: https://symfony.com/doc/current/best_practices/index.html
[2]: https://symfony.com/doc/current/reference/requirements.html
[3]: https://symfony.com/doc/current/cookbook/configuration/web_server_configuration.html
[4]: https://symfony.com/download
[5]: https://github.com/symfony/webpack-encore
