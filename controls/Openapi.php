<?php

use Flight\Flight;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

class Openapi {
    public static function list() {
        try {
            $tools = Model_Openapitool::getAll();
            
            if (empty($tools)) {
                Flight::json(['success' => true, 'data' => []]);
                return;
            }
            
            $toolData = array_map(function($t) {
                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'description' => $t->description,
                    'endpoint_url' => $t->endpoint_url,
                    'auth_type' => $t->auth_type,
                    'status' => $t->status,
                    'created_at' => $t->created_at,
                    'updated_at' => $t->updated_at
                ];
            }, $tools);
            
            Flight::json(['success' => true, 'data' => $toolData]);
        } catch (\Exception $e) {
            Flight::response()->status(500);
            Flight::json(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    public static function add(Request $request, Response $response) {
        try {
            $data = json_decode($request->getBody(), true);
            
            if (empty($data['name'])) {
                Flight::response()->status(400);
                Flight::json(['success' => false, 'error' => 'Name is required']);
                return;
            }
            
            // Validate URL format
            if (!empty($data['spec_url']) && !filter_var($data['spec_url'], FILTER_VALIDATE_URL)) {
                Flight::response()->status(400);
                Flight::json(['success' => false, 'error' => 'Invalid spec URL']);
                return;
            }
            
            $tool = Model_Openapitool::create($data);
            Flight::json([
                'success' => true,
                'message' => 'OpenAPI tool created successfully',
                'data' => [
                    'id' => $tool->id,
                    'name' => $tool->name
                ]
            ]);
        } catch (\Exception $e) {
            Flight::response()->status(500);
            Flight::json(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    public static function update(Request $request, Response $response, array $params = []) {
        try {
            $id = (int) ($params['id'] ?? 0);
            if (!$id) {
                Flight::response()->status(400);
                Flight::json(['success' => false, 'error' => 'Tool ID required']);
                return;
            }
            
            $tool = Model_Openapitool::getById($id);
            if (!$tool->id) {
                Flight::response()->status(404);
                Flight::json(['success' => false, 'error' => 'Tool not found']);
                return;
            }
            
            $data = json_decode($request->getBody(), true);
            
            // Only allow updating name and description
            if (!isset($data['name']) && !isset($data['description'])) {
                Flight::response()->status(400);
                Flight::json(['success' => false, 'error' => 'Name or description required']);
                return;
            }
            
            $updated = Model_Openapitool::update($tool, $data);
            
            if ($updated) {
                Flight::json([
                    'success' => true,
                    'message' => 'Tool updated successfully',
                    'data' => ['id' => $tool->id]
                ]);
            } else {
                Flight::response()->status(500);
                Flight::json(['success' => false, 'error' => 'Failed to update tool']);
            }
        } catch (\Exception $e) {
            Flight::response()->status(500);
            Flight::json(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    public static function delete(Request $request, Response $response, array $params = []) {
        try {
            $id = (int) ($params['id'] ?? 0);
            if (!$id) {
                Flight::response()->status(400);
                Flight::json(['success' => false, 'error' => 'Tool ID required']);
                return;
            }
            
            $tool = Model_Openapitool::getById($id);
            if (!$tool->id) {
                Flight::response()->status(404);
                Flight::json(['success' => false, 'error' => 'Tool not found']);
                return;
            }
            
            $deleted = Model_Openapitool::delete($tool);
            
            if ($deleted) {
                Flight::json([
                    'success' => true,
                    'message' => 'Tool deleted successfully',
                    'data' => ['id' => $id]
                ]);
            } else {
                Flight::response()->status(500);
                Flight::json(['success' => false, 'error' => 'Failed to delete tool']);
            }
        } catch (\Exception $e) {
            Flight::response()->status(500);
            Flight::json(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    public static function validate(Request $request, Response $response) {
        try {
            $data = json_decode($request->getBody(), true);
            
            if (empty($data['name'])) {
                Flight::response()->status(400);
                Flight::json(['success' => false, 'error' => 'Name is required']);
                return;
            }
            
            // Check name uniqueness
            $existing = R::findOne('openapi_tools', 'name = ?', [$data['name']]);
            if ($existing && $existing->id) {
                Flight::response()->status(409);
                Flight::json(['success' => false, 'error' => 'Tool name already exists']);
                return;
            }
            
            // Validate URL format
            if (!empty($data['spec_url']) && !filter_var($data['spec_url'], FILTER_VALIDATE_URL)) {
                Flight::response()->status(400);
                Flight::json(['success' => false, 'error' => 'Invalid spec URL']);
                return;
            }
            
            // Optional: Validate endpoint format
            if (!empty($data['endpoint_url']) && !filter_var($data['endpoint_url'], FILTER_VALIDATE_URL)) {
                Flight::response()->status(400);
                Flight::json(['success' => false, 'error' => 'Invalid endpoint URL']);
                return;
            }
            
            Flight::json([
                'success' => true,
                'message' => 'Tool name is valid',
                'data' => ['name_available' => true]
            ]);
        } catch (\Exception $e) {
            Flight::response()->status(500);
            Flight::