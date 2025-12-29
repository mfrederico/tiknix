<?php
/**
 * OpenSwoole Server Configuration
 */

return [
    // Server settings
    'host' => '127.0.0.1',
    'port' => 9501,

    // OpenSwoole settings
    'swoole' => [
        'worker_num' => 4,
        'max_request' => 10000,
        'dispatch_mode' => 2,
        'enable_coroutine' => true,
        'max_coroutine' => 3000,
        'open_http2_protocol' => false,
        'package_max_length' => 2 * 1024 * 1024, // 2MB
    ],

    // MCP settings
    'mcp' => [
        'session_timeout' => 1800, // 30 minutes
        'cleanup_interval' => 300, // 5 minutes
    ],

    // Tiknix integration
    'tiknix' => [
        'config_file' => 'conf/config.ini',
        'base_path' => dirname(__DIR__, 2),
    ],

    // Logging
    'logging' => [
        'level' => 'DEBUG',
        'file' => 'log/swoole.log',
    ],
];
