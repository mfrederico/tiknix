<?php

class OpenApiParser {
    public function parseFromArray(array $spec): array {
        $tools = [];
        
        // Extract paths and operations
        if (isset($spec['paths'])) {
            foreach ($spec['paths'] as $path => $operations) {
                foreach ($operations as $method => $operation) {
                    $toolName = $this->generateToolName($path, $method);
                    
                    $tools[] = [
                        'name' => $toolName,
                        'description' => $operation['summary'] ?? '',
                        'schema' => $this->buildToolSchema($operation),
                        'method' => strtoupper($method)
                    ];
                }
            }
        }
        
        return array_values($tools);
    }
    
    private function generateToolName(string $path, string $method): string {
        // Create snake_case name from path and method
        return preg_replace('/[^a-z0-9]/i', '', strtolower(str_replace(['/', '-'], '_', $path . '_' . $method)));
    }
}