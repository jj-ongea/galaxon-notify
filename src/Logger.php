<?php

namespace ParimIntegration;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

class Logger
{
    private static $loggers = [];

    public static function getLogger(string $name): MonologLogger
    {
        if (!isset(self::$loggers[$name])) {
            $logger = new MonologLogger($name);
            
            // Create a formatter that includes datetime, level, and message
            $dateFormat = "Y-m-d H:i:s";
            $output = "[%datetime%] %level_name%: %message% %context% %extra%\n";
            $formatter = new LineFormatter($output, $dateFormat);

            // Add a rotating file handler
            $fileHandler = new RotatingFileHandler(
                dirname(__DIR__) . "/logs/{$name}.log",
                30, // Keep 30 days of logs
                MonologLogger::DEBUG
            );
            $fileHandler->setFormatter($formatter);
            
            // Add console output for CLI scripts
            if (php_sapi_name() === 'cli') {
                $streamHandler = new StreamHandler('php://stdout', MonologLogger::DEBUG);
                $streamHandler->setFormatter($formatter);
                $logger->pushHandler($streamHandler);
            }

            $logger->pushHandler($fileHandler);
            self::$loggers[$name] = $logger;
        }

        return self::$loggers[$name];
    }
} 