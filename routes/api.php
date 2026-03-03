<?php

// OpenAPI tools management
Flight::route('POST /api/openapi-tools', function() {
    (new Controls_OpenapiTools())->addTool();
});

Flight::route('PUT /api/openapi-tools/activate/@id', function($id) {
    (new Controls_OpenapiTools())->activateTool(['id' => $id]);
});

Flight::route('PUT /api/openapi-tools/deactivate/@id', function($id) {
    (new Controls_OpenapiTools())->deactivateTool(['id' => $id]);
});

Flight::route('GET /api/openapi-tools', function() {
    (new Controls_OpenapiTools())->listTools();
});

Flight::route('DELETE /api/openapi-tools/@id', function($id) {
    (new Controls_OpenapiTools())->deleteTool(['id' => $id]);
});