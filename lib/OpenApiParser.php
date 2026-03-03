<?php

use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Coroutine;

class OpenApiParser {
    private array $operations = [];
    
    public function parseOpenAPISpec(string $specUrl, string $content = null): array {
        if (!$content) {
            // Fetch from URL
            $response = $this->fetchFromUrl($specUrl);
            
            if ($response['success'] !== true) {
                return ['success' => false, 'error' => $response['error']];
            }
            
            $content = $response['data'];
        }
        
        $specData = json_decode($content, true);
        if (!$specData || !isset($specData['paths'])) {
            return ['success' => false, 'error' => 'Invalid OpenAPI spec format'];
        }
        
        // Extract operations
        $this->operations = [];
        foreach ($specData['paths'] as $path => $methods) {
            foreach (['get', 'post', 'put', 'delete', 'patch'] as $method) {
                if (!isset($methods[$method])) continue;
                
                $operationId = $methods[$method]['operationId'] ?? strtolower($method . '_' . preg_replace('/[^a-z0-9_]/i', '_', $path));
                $summary = $methods[$method]['summary'] ?? ucfirst(ucwords(str_replace(['-', '/'], ' ', basename($path)))));
                $description = trim(($methods[$method]['description'] ?? '') . ' ' . ($methods[$method]['summary'] ?? ''));
                
                $this->operations[] = [
                    'operation_id' => $operationId,
                    'method' => strtoupper($method),
                    'path' => $path,
                    'summary' => trim($summary),
                    'description' => trim($description),
                    'parameters' => $methods[$method]['parameters'] ?? [],
                    'request_body' => $methods[$method]['requestBody'] ?? null
                ];
            }
        }
        
        return ['success' => true, 'operations' => $this->operations];
    }
    
    private function fetchFromUrl(string $url): array {
        if (!preg_match('/^https?:\/\//', $url)) {
            return ['success' => false, 'error' => 'Invalid URL format'];
        }
        
        try {
            Coroutine::create(function() use ($url) {
                $client = new Client(parse_url($url)['host'], 80);
                
                if (parse_url($url)['scheme'] === 'https') {
                    $client->setSSLVerify(false);
                }
                
                $client->get(parse_url($url)['path']);
                
                if ($client->getStatusCode() !== 200) {
                    throw new Exception('HTTP error: ' . $client->getStatusCode());
                }
                
                $content = $client->getBody();
                $client->close();
                
                return ['success' => true, 'data' => $content];
            });
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function generateToolDefinitions(string $baseUrl): array {
        if (empty($this->operations)) {
            return ['success' => false, 'error' => 'No operations parsed'];
        }
        
        $tools = [];
        foreach ($this->operations as $op) {
            $pathParts = explode('/', trim($op['path'], '/'));
            $toolName = 'openapi_' . implode('_', array_map('snake_case', $pathParts)) . '_' . strtolower($op['method']);
            
            $parameters = $this->buildParametersSchema($op['parameters'], $op['request_body']);
            $description = trim(($op['summary'] ? '- ' . $op['summary'] : '') . ($op['description'] ? "\n\n" . $op['description'] : ''));
            
            $tools[] = [
                'name' => $toolName,
                'operation_id' => $op['operation_id'],
                'method' => $op['method'],
                'path' => $op['path'],
                'description' => $description,
                'parameters_schema' => json_encode($parameters)
            ];
        }
        
        return ['success' => true, 'tools' => $tools];
    }
    
    private function buildParametersSchema(array $params = [], ?array $requestBody = null): array {
        $schema = [];
        
        // Path parameters
        foreach ($params as $param) {
            if ($param['in'] === 'path') {
                $schema[$param['name']] = [
                    'type' => in_array($param['schema']['type'], ['string', 'integer', 'boolean']) ? $param['schema']['type'] : 'string',
                    'description' => $param['description'] ?? ucfirst(ucwords(str_replace('_', ' ', $param['name']))),
                    'required' => $param['required'] ?? false
                ];
            }
        }
        
        // Query parameters
        foreach ($params as $param) {
            if ($param['in'] === 'query') {
                $schema[$param['name']] = [
                    'type' => in_array($param['schema']['type'], ['string', 'integer', 'boolean']) ? $param['schema']['type'] : 'string',
                    'description' => $param['description'] ?? ucfirst(ucwords(str_replace('_', ' ', $param['name']))),
                    'required' => $param['required'] ?? false
                ];
            }
        }
        
        // Request body (JSON schema)
        if ($requestBody && $requestBody['content']['application/json'] ?? null) {
            $schema['body'] = [
                'type' => 'object',
                'description' => 'Request payload data',
                'properties' => $this->buildJsonSchemaProperties($requestBody['content']['application/json']['schema']['properties']),
                'required' => !empty($requestBody['content']['application/json']['schema']['required'] ?? [])
            ];
        }
        
        return $schema;
    }
    
    private function buildJsonSchemaProperties(array $props): array {
        $result = [];
        
        foreach ($props as $name => $def) {
            $type = $this->mapOpenAPIToJSONType($def['type'] ?? 'string', $def['format'] ?? null);
            
            $schema = [
                'type' => is_array($type) ? 'array' : $type,
                'description' => $def['description'] ?? ucfirst(ucwords(str_replace('_', ' ', $name))),
                'required' => in_array($name, ($def['required'] ?? []))
            ];
            
            if (is_array($type)) {
                $schema['items'] = ['type' => $type[0]];
                
                if (!empty($def['items']['oneOf'])) {
                    $schema['items']['anyOf'] = array_map(fn($item) => [
                        'type' => $this->mapOpenAPIToJSONType($item['type'], $item['format']),
                        'description' => $item['description'] ?? ''
                    ], $def['items']['oneOf']);
                }
            } elseif ($type === 'object') {
                $schema['properties'] = $this->buildJsonSchemaProperties($def['properties'] ?? []);
                $schema['required'] = !empty($def['required'] ?? []);
            }
            
            if (!empty($def['enum'])) {
                $schema['enum'] = array_map('strval', $def['enum']);
                $schema['type'] = 'string'; // Enums become strings
            }
            
            if ($type === 'integer' && !empty($def['format']) && $def['format'] === 'date-time') {
                $schema['format'] = 'date-time';
            }
            
            $result[$name] = $schema;
        }
        
        return $result;
    }
    
    private function mapOpenAPIToJSONType(string $type, ?string $format): mixed {
        if ($format === 'binary') {
            return ['string', 'file'];
        }
        
        switch (true) {
            case in_array($type, ['integer', 'number']):
                return $format === 'int32' || $format === 'int64' ? 'integer' : 'float';
            case $type === 'boolean':
                return 'boolean';
            case $type === 'array':
                if (!empty($def['items']['oneOf'])) {
                    return ['array', 'object']; // Mixed types in array
                }
                
                return [$this->mapOpenAPIToJSONType($def['items']['type'], $def['items']['format']), 'null'];
            case $type === 'string':
                if ($format === 'date-time') return 'datetime';
                if ($format === 'email' || $format === 'uri') return 'string';
                
                return 'string';
            default:
                return 'string'; // Default to string for unknown types
        }
    }
}