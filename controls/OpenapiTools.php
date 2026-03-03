<?php

class Controls_OpenapiTools extends BaseControls_Control {
    public function addTool() {
        $this->requirePermission('MANAGE_OPENAPI_TOOLS');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Flight::json(['error' => 'Method not allowed'], 405);
            return;
        }
        
        $data = $_POST;
        
        // Validate input
        $errors = [];
        if (empty($data['name'])) {
            $errors[] = 'Name is required';
        }
        if (empty($data['spec_url'])) {
            $errors[] = 'OpenAPI spec URL is required';
        }
        
        // Validate OpenAPI spec format
        $validator = new Lib_OpenApiValidator();
        $specUrl = $data['spec_url'];
        $specContent = @file_get_contents($specUrl);
        
        if (!$specContent) {
            $errors[] = "Failed to load OpenAPI spec from: {$specUrl}";
        } elseif (!$validator->validateJson($specContent)) {
            $errors[] = 'Invalid JSON format in OpenAPI spec';
        } elseif (!$validator->validateOpenApiSchema($specContent)) {
            $errors[] = 'Invalid OpenAPI schema';
        }
        
        if (!empty($errors)) {
            Flight::json(['error' => implode('; ', $errors)], 400);
            return;
        }
        
        // Check URL accessibility
        $httpCode = @http_response_code();
        if ($httpCode !== 200) {
            Flight::json([
                'error' => "Spec URL returned HTTP {$httpCode}"
            ], 400);
            return;
        }
        
        try {
            // Create tool record
            $tool = new Model_Openapitool([
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'spec_url' => $specUrl,
                'is_active' => true, // Default active
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            Bean::store($tool);
            Flight::json(['success' => "Tool '{$data['name']}' added successfully", 
                          'id' => $tool->id], 201);
        } catch (Exception $e) {
            Flight::json(['error' => 'Failed to add tool: ' . $e->getMessage()], 500);
        }
    }
    
    public function activateTool($params = []) {
        $this->requirePermission('MANAGE_OPENAPI_TOOLS');
        
        if (!isset($params['id']) || !is_numeric($params['id'])) {
            Flight::json(['error' => 'Invalid tool ID'], 400);
            return;
        }
        
        $toolId = (int) $params['id'];
        $tool = Model_Openapitool::findById($toolId);
        
        if (!$tool) {
            Flight::json(['error' => "Tool with ID {$toolId} not found"], 404);
            return;
        }
        
        if ($tool->getIsActive()) {
            Flight::json(['success' => "Tool '{$tool->name}' is already active"]);
            return;
        }
        
        if (!$tool->activate()) {
            Flight::json(['error' => "Failed to activate tool '{$tool->name}'"], 500);
            return;
        }
        
        Flight::json([
            'success' => "Tool '{$tool->name}' activated successfully",
            'id' => $tool->id,
            'name' => $tool->name
        ]);
    }
    
    public function deactivateTool($params = []) {
        $this->requirePermission('MANAGE_OPENAPI_TOOLS');
        
        if (!isset($params['id']) || !is_numeric($params['id'])) {
            Flight::json(['error' => 'Invalid tool ID'], 400);
            return;
        }
        
        $toolId = (int) $params['id'];
        $tool = Model_Openapitool::findById($toolId);
        
        if (!$tool) {
            Flight::json(['error' => "Tool with ID {$toolId} not found"], 404);
            return;
        }
        
        if (!$tool->getIsActive()) {
            Flight::json(['success' => "Tool '{$tool->name}' is already inactive"]);
            return;
        }
        
        if (!$tool->deactivate()) {
            Flight::json(['error' => "Failed to deactivate tool '{$tool->name}'"], 500);
            return;
        }
        
        Flight::json([
            'success' => "Tool '{$tool->name}' deactivated successfully",
            'id' => $tool->id,
            'name' => $tool->name
        ]);
    }
    
    public function listTools() {
        $this->requirePermission('VIEW_OPENAPI_TOOLS');
        
        // Get active tools only
        $activeTools = Model_Openapitool::getActiveTools();
        
        $toolsData = [];
        foreach ($activeTools as $tool) {
            $toolsData[] = [
                'id' => $tool->id,
                'name' => $tool->name,
                'description' => $tool->description,
                'spec_url' => $tool->spec_url,
                'is_active' => (bool) $tool->is_active,
                'created_at' => $tool->created_at,
                'updated_at' => $tool->updated_at
            ];
        }
        
        Flight::json(['tools' => $toolsData]);
    }
    
    public function deleteTool($params = []) {
        $this->requirePermission('MANAGE_OPENAPI_TOOLS');
        
        if (!isset($params['id']) || !is_numeric($params['id'])) {
            Flight::json(['error' => 'Invalid tool ID'], 400);
            return;
        }
        
        $toolId = (int) $params['id'];
        $tool = Model_Openapitool::findById($toolId);
        
        if (!$tool) {
            Flight::json(['error' => "Tool with ID {$toolId} not found"], 404);
            return;
        }
        
        // Prevent deletion of active tools
        if ($tool->getIsActive()) {
            Flight::json([
                'error' => "Cannot delete active tool '{$tool->name}'. Deactivate first."
            ], 400);
            return;
        }
        
        Bean::trash($tool);
        Flight::json(['success' => "Tool '{$tool->name}' deleted successfully"]);
    }
}