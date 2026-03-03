<?php

class Model_Openapitoolregistry {
    public static function findAll() {
        return R::find('openapi_tools', 'ORDER BY name');
    }

    public static function findById($id) {
        return R::findOne('openapi_tools', 'id = ?', [$id]);
    }

    public static function create(array $data): OpenApiTool {
        $tool = R::dispense('openapi_tools');
        
        $tool->name = trim($data['name']);
        $tool->description = isset($data['description']) ? trim($data['description']) : '';
        $tool->spec_url = trim($data['spec_url']);
        $tool->is_active = true;
        $tool->created_at = date('Y-m-d H:i:s');
        $tool->updated_at = date('Y-m-d H:i:s');
        
        if (empty($tool->name)) {
            throw new InvalidArgumentException('Name is required');
        }
        
        if (!filter_var($tool->spec_url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid spec URL format');
        }
        
        return $tool;
    }

    public static function update(OpenApiTool $tool, array $data): void {
        $tool->name = trim($data['name']);
        $tool->description = isset($data['description']) ? trim($data['description']) : '';
        $tool->spec_url = trim($data['spec_url']);
        $tool->updated_at = date('Y-m-d H:i:s');
        
        if (empty($tool->name)) {
            throw new InvalidArgumentException('Name is required');
        }
        
        if (!filter_var($tool->spec_url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid spec URL format');
        }
        
        R::store($tool);
    }

    public static function delete(OpenApiTool $tool): void {
        R::trash($tool);
    }

    public static function activate(OpenApiTool $tool): void {
        $tool->is_active = true;
        $tool->updated_at = date('Y-m-d H:i:s');
        R::store($tool);
    }

    public static function deactivate(OpenApiTool $tool): void {
        $tool->is_active = false;
        $tool->updated_at = date('Y-m-d H:i:s');
        R::store($tool);
    }

    public static function getActiveTools(): array {
        return R::find('openapi_tools', 'is_active = ? ORDER BY name', [true]);
    }
}