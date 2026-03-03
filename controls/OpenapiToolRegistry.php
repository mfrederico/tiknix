<?php

class Controls_OpenapiToolRegistry extends Controls_BaseControls {
    public function index() {
        if (!$this->requireLogin()) return;
        
        $activeTools = Model_Openapitoolregistry::getActiveTools();
        $inactiveTools = Model_Openapitoolregistry::findAll();
        foreach ($inactiveTools as $tool) {
            $tool->is_active = false;
        }
        
        $this->render('admin/openapi_tools/index', [
            'title' => 'OpenAPI Tools',
            'activeTools' => $activeTools,
            'inactiveTools' => $inactiveTools
        ]);
    }
    
    public function add() {
        if (!$this->requireLogin()) return;
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $data = $_POST + [
                    'name' => '',
                    'description' => '',
                    'spec_url' => ''
                ];
                
                $tool = Model_Openapitoolregistry::create($data);
                R::store($tool);
                
                Flight::json([
                    'success' => true,
                    'message' => "Tool '{$tool->name}' added successfully",
                    'redirect' => '/admin/openapi-tools'
                ]);
            } catch (Exception $e) {
                Flight::json(['success' => false, 'error' => $e->getMessage()], 400);
            }
        } else {
            $this->render('admin/openapi_tools/add', [
                'title' => 'Add OpenAPI Tool'
            ]);
        }
    }
    
    public function edit($id) {
        if (!$this->requireLogin()) return;
        
        $tool = Model_Openapitoolregistry::findById((int)$id);
        if (!$tool) {
            Flight::json(['success' => false, 'error' => 'Tool not found'], 404);
            return;
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $data = $_POST + [
                    'name' => '',
                    'description' => ''
                ];
                
                Model_Openapitoolregistry::update($tool, $data);
                R::store($tool);
                
                Flight::json([
                    'success' => true,
                    'message' => "Tool '{$tool->name}' updated successfully",
                    'redirect' => '/admin/openapi-tools'
                ]);
            } catch (Exception $e) {
                Flight::json(['success' => false, 'error' => $e->getMessage()], 400);
            }
        } else {
            $this->render('admin/openapi_tools/edit', [
                'title' => "Edit OpenAPI Tool: {$tool->name}",
                'tool' => $tool
            ]);
        }
    }
    
    public function toggle($id) {
        if (!$this->requireLogin()) return;
        
        $tool = Model_Openapitoolregistry::findById((int)$id);
        if (!$tool) {
            Flight::json(['success' => false, 'error' => 'Tool not found'], 404);
            return;
        }
        
        try {
            if ($tool->is_active) {
                Model_Openapitoolregistry::deactivate($tool);
                $action = 'deactivated';
            } else {
                Model_Openapitoolregistry::activate($tool);
                $action = 'activated';
            }
            
            R::store($tool);
            Flight::json([
                'success' => true,
                'message' => "Tool '{$tool->name}' {$action} successfully",
                'redirect' => '/admin/openapi-tools'
            ]);
        } catch (Exception $e) {
            Flight::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
    
    public function delete($id) {
        if (!$this->requireLogin()) return;
        
        $tool = Model_Openapitoolregistry::findById((int)$id);
        if (!$tool) {
            Flight::json(['success' => false, 'error' => 'Tool not found'], 404);
            return;
        }
        
        try {
            Model_Openapitoolregistry::delete($tool);
            R::trash($tool);
            
            Flight::json([
                'success' => true,
                'message' => "Tool '{$tool->name}' deleted successfully",
                'redirect' => '/admin/openapi-tools'
            ]);
        } catch (Exception $e) {
            Flight::json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}