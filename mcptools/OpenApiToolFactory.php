<?php

namespace McpTools;

use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Coroutine;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Coroutine\Server;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\WaitGroup;

class OpenApiToolFactory {
    private array $tools = [];
    
    public function __construct() {
        // Load active tools from registry
        $activeTools = \Model_Openapitoolregistry::getActiveTools();
        
        foreach ($activeTools as $toolData) {
            try {
                $this->loadFromRegistry($toolData);
            } catch (\Exception $e) {
                error_log("Failed to load tool {$toolData['name']}: " . $e->getMessage());
            }
        }
    }
    
    private function loadFromRegistry(array $toolData): void {
        // Load OpenAPI spec from URL
        $spec = $this->loadOpenApiSpec($toolData['spec_url']);
        
        if (!$spec) {
            throw new \Exception("Failed to load OpenAPI spec");
        }
        
        // Parse and register tool
        $parser = new \lib\OpenApiParser();
        $parsedTools = $parser->parseFromArray($spec);
        
        foreach ($parsedTools as $tool) {
            if ($this->isValidToolSpec($tool)) {
                $this->registerTool($tool, $toolData['name']);
            }
        }
    }
    
    private function loadOpenApiSpec(string $url): ?array {
        // Use coroutine to fetch spec asynchronously
        return Coroutine::create(function() use ($url) {
            $client = new Client('localhost', 80);
            
            try {
                $client->get($url);
                
                if ($client->statusCode === 200) {
                    $specJson = $client->getBody();
                    
                    // Parse JSON with error handling
                    return json_decode($specJson, true, 512, JSON_THROW_ON_ERROR);
                }
            } catch (\Throwable $e) {
                error_log("HTTP request failed: " . $e->getMessage());
            } finally {
                $client->close();
            }
            
            return null;
        });
    }
    
    private function isValidToolSpec(array $tool): bool {
        // Basic validation - can be extended
        return !empty($tool['name']) && !empty($tool['description']);
    }
}