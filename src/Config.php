<?php

namespace ParimIntegration;

use Dotenv\Dotenv;

class Config
{
    private static $instance = null;
    private $env = [];

    private function __construct()
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__));
        $dotenv->load();
        
        $this->env = $_ENV;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get(string $key, $default = null)
    {
        return $this->env[$key] ?? $default;
    }
} 