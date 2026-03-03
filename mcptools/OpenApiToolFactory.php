<?php

namespace mcptools;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Coroutine\Server;
use OpenSwoole\Table;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\WaitGroup;
use OpenSwoole\Coroutine\Timer;

class OpenApiToolFactory {
    private string $baseNamespace = 'mcptools\\generated\\';
    
    public function generateMcpToolsFromSpec(string $specUrl, array $operations): bool {
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($operations as $operation) {
            try {
                $toolClass = $this->generateToolClass($operation);
                if ($toolClass !== false) {
                    $successCount++;
                    
                    // Register with MCP system
                    $mcpName = 'dynamic_openapi_' . $operation['operation_id'];
                    $handlerConfig = json_encode([
                        'spec_url' => $specUrl,
                        'operation_id' => $operation['operation_id']
                    ]);
                    
                    $result = \mcptools\ToolLoader::registerMcpTool(
                        $mcpName, 
                        $toolClass, 
                        ['handler_config' => $handlerConfig]
                    );
                    
                    if ($result) {
                        echo "Created MCP tool: {$mcpName}\n";
                    }
                }
            } catch (\Exception $e) {
                $errorCount++;
                echo "Error generating tool for operation {$operation['operation_id']}: " . $e->getMessage() . "\n";
            }
        }
        
        return $successCount > 0;
    }
    
    private function generateToolClass(array $operation): string|bool {
        $className = $this->baseNamespace . ucfirst($operation['operation_id']);
        $filePath = str_replace('\\', '/', substr($className, 0, -4)) . '.php';
        
        if (file_exists($filePath)) {
            return $className;
        }
        
        // Generate PHP class
        $classContent = $this->generateClassTemplate($operation);
        
        Coroutine::create(function() use ($filePath, $classContent) {
            file_put_contents($filePath, $classContent);
            
            // Autoload the new class
            require_once $filePath;
        });
        
        return $className;
    }
    
    private function generateClassTemplate(array $operation): string {
        $mcpName = 'dynamic_openapi_' . $operation['operation_id'];
        $description = trim($operation['summary'] . '. ' . $operation['description']);
        
        // Build parameter schema
        $paramArray = [];
        foreach ($operation['parameters'] as $param) {
            $desc = $param['description'] ?? '';
            
            if (!empty($param['schema']['description'])) {
                $desc .= ' (' . $param['schema']['description'] . ')';
            }
            
            $paramArray[] = "'{$param['name']}' => " . json_encode([
                'type' => $this->getPhpTypeFromSchema($param['schema']),
                'description' => trim($desc),
                'required' => $param['required'] ?? false
            ]);
        }
        
        return <<<PHP
<?php

namespace mcptools\generated;

use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Table;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Coroutine\Server;
use OpenSwoole\Table;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\WaitGroup;
use OpenSwoole\Coroutine\Timer;

class {$operation['operation_id']} extends \mcptools\BaseTool {
    public function getName(): string {
        return '{$mcpName}';
    }
    
    public function getDescription(): string {
        return '{$description}';
    }
    
    public function execute(array \$args): array {
        // Extract parameters
        \$method = '{$operation['method']}';
        \$path = '{$operation['path']}';
        
        // Build URL
        \$baseUrl = \$_SERVER['REQUEST_SCHEME'] . '://' . \$_SERVER['HTTP_HOST'];
        \$url = \$baseUrl . str_replace(['{', '}'], ['\\$', ''], \$path);
        
        // Prepare headers
        \$headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . (\$_ENV['OPENAI_API_KEY'] ?? '')
        ];
        
        // Build request body
        \$requestBody = null;
        if (!empty(\$args['body'])) {
            \$requestBody = json_encode(\$args['body']);
        }
        
        // Make HTTP request
        Coroutine::create(function() use (\$url, \$method, \$headers, \$requestBody) {
            \$client = new Client(parse_url(\$url)['host'], 80);
            
            if (parse_url(\$url)['scheme'] === 'https') {
                \$client->setSSLVerify(false);
            }
            
            \$client->{$method}(\$path, \$headers, \$requestBody);
            
            if (\$client->getStatusCode() !== 200) {
                throw new Exception('HTTP error: ' . \$client->getStatusCode());
            }
            
            \$response = json_decode(\$client->getBody(), true);
            \$client->close();
            
            return \$response;
        });
        
        return ['success' => true, 'data' => \$result];
    }
}
PHP
    }
    
    private function getPhpTypeFromSchema(array $schema): string {
        if (isset($schema['type'])) {
            switch ($schema['type']) {
                case 'integer': return 'int';
                case 'boolean': return 'bool';
                default: return 'string';
            }
        }
        
        return 'mixed';
    }
}