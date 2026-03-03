<?php
require_once 'bootstrap.php';

class Controls_OpenApiToolRegistry extends Controls_BaseControls {
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
}