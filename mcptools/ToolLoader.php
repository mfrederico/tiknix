<?php

namespace mcptools;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Coroutine\Server;
use OpenSwoole\Table;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\WaitGroup;
use OpenSwoole\Coroutine\Timer;

class ToolLoader {
    private string $generatedDir = __DIR__ . '/../../generated/tools/';
    
    public function loadAllTools(): void {
        $this->loadStaticTools();
        $this->loadDynamicTools();
    }
    
    private function loadStaticTools(): void {
        // Existing static tool loading logic...
    }
    
    private function loadDynamicTools(): void {
        if (!is_dir($this->generatedDir)) {
            return;
        }
        
        $files = glob($this->generatedDir . '*.php');
        foreach ($files as $file) {
            require_once $file;
            
            // Find class name (assumes singular filename)
            $className = str_replace(
                ['mcptools\\generated\\', '.php'], 
                ['', ''],
                substr($file, strlen($this->generatedDir))
            );
            
            if (!class_exists($className)) {
                echo "Warning: Class {$className} not found in {$file}\n";
                continue;
            }
            
            // Register with MCP system
            $tool = new $className();
            if (!$tool instanceof BaseTool) {
                echo "Warning: {$className} is not a valid tool\n";
                continue;
            }
            
            $mcpName = 'dynamic_openapi_' . $tool->getOperationId();
            $handlerConfig = json_encode([
                'spec_url' => $tool->getSpecUrl(),
                'operation_id' => $tool->getOperationId()
            ]);
            
            $result = $this->registerMcpTool(
                $mcpName, 
                get_class($tool), 
                ['handler_config' => $handlerConfig]
            );
            
            if ($result) {
                echo "Loaded dynamic tool: {$mcpName}\n";
            }
        }
    }
    
    private function registerMcpTool(string $name, string $class, array $config = []): bool {
        // Existing registration logic...
    }
}