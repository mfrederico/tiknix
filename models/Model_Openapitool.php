<?php

class Model_Openapitool {
    public static function getAll() {
        return R::find('openapi_tools', 'ORDER BY name ASC');
    }
    
    public static function getById($id) {
        return R::load('openapi_tools', $id);
    }
    
    public static function create(array $data): Model_Openapitool {
        $tool = R::dispense('openapi_tools');
        
        $tool->name = trim($data['name']);
        $tool->description = trim($data['description'] ?? '');
        $tool->spec_url = trim($data['spec_url'] ?? '');
        $tool->endpoint_url = trim($data['endpoint_url'] ?? '');
        $tool->auth_type = $data['auth_type'] ?? 'none';
        $tool->status = 'pending'; // pending, active, error, disabled
        $tool->created_at = date('Y-m-d H:i:s');
        $tool->updated_at = date('Y-m-d H:i:s');
        
        R::store($tool);
        return $tool;
    }
    
    public static function update(Model_Openapitool $tool, array $data): bool {
        $tool->name = trim($data['name']);
        $tool->description = trim($data['description'] ?? '');
        $tool->endpoint_url = trim($data['endpoint_url'] ?? '');
        $tool->auth_type = $data['auth_type'] ?? 'none';
        $tool->status = $data['status'] ?? 'active';
        $tool->updated_at = date('Y-m-d H:i:s')
-->
<div class="container-fluid mt-4">
    <h2>Build OpenAPI management UI</h2>
    
    <!-- Navigation tabs -->
    <ul class="nav nav-tabs mb-3" id="openapiTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" id="tools-tab" data-bs-toggle="tab" data-bs-target="#tools" type="button" role="tab" aria-controls="tools" aria-selected="true">All Tools</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="add-tool-tab" data-bs-toggle="tab" data-bs-target="#add-tool" type="button" role="tab" aria-controls="add-tool" aria-selected="false">Add Tool</button>
        </li>
    </ul>
    
    <!-- Tab content -->
    <div class="tab-content">
        
        <!-- All Tools tab -->
        <div class="tab-pane fade show active" id="tools" role="tabpanel" aria-labelledby="tools-tab">
            <h3>All OpenAPI Tools</h3>
            
            <?php if (count($tools) === 0): ?>
                <p>No tools registered yet. Add your first tool below.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Spec Source</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tools as $tool): ?>
                                <tr data-tool-id="<?= htmlspecialchars((string)$tool->id) ?>">
                                    <td><?= htmlspecialchars($tool->name) ?></td>
                                    <td><?= htmlspecialchars(substr($tool->description, 0, 150)) . (strlen($tool->description) > 150 ? '...' : '') ?></td>
                                    <td>
                                        <?php if (!empty($tool->spec_url)): ?>
                                            <a href="<?= htmlspecialchars($tool->spec_url) ?>" target="_blank" class="text-decoration-none">
                                                <?= htmlspecialchars(parse_url($tool->spec_url, PHP_URL_HOST)) . (parse_url($tool->spec_url, PHP_URL_PATH) ? ' - ' . htmlspecialchars(basename(parse_url($tool->spec_url, PHP_URL_PATH))) : '') ?>
                                            </a>
                                        <?php else: ?>
                                            Local file
                                        <?php endif ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <span class="badge bg-<?= $tool->status === 'active' ? 'success' : ($tool->status === 'error' ? 'danger' : 'secondary') ?>"><?= htmlspecialchars($tool->status) ?></span>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-primary me-1" data-bs-toggle="modal" data-bs-target="#editToolModal" data-tool-id="<?= htmlspecialchars((string)$tool->id) ?>">
                                            Edit
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#confirmDeleteModal" data-tool-id="<?= htmlspecialchars((string)$tool->id) ?>" data-tool-name="<?= htmlspecialchars($tool->name) ?>">
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>
        </div>
        
        <!-- Add Tool tab -->
        <div class="tab-pane fade" id="add-tool" role="tabpanel" aria-labelledby="add-tool-tab">
            <h3>Add New OpenAPI Tool</h3>
            
            <form id="addToolForm">
                <div class="mb-3">
                    <label for="toolName" class="form-label">Tool Name</label>
                    <input type="text" class="form-control" id="toolName" name="name" required>
                </div>
                
                <div class="mb-3">
                    <label for="toolDescription" class="form-label">Description</label>
                    <textarea class="form-control" id="toolDescription" name="description" rows="4"></textarea>
                </div>
                
                <div class="mb-3">
                    <label for="specSource" class="form-label">OpenAPI Specification Source</label>
                    
                    <div class="input-group">
                        <input type="text" class="form-control" id="specSource" name="spec_source" placeholder="URL or local path" required>
                        <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Source Type</button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" data-source-type="url">API URL (e.g. Swagger UI)</a></li>
                            <li><a class="dropdown-item" href="#" data-source-type="file">Local File Upload</a></li>
                        </ul>
                    </div>
                    
                    <div id="specSourceOptions" class="mt-2">
                        <!-- Options will be dynamically shown/hidden -->
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="endpointUrl" class="form-label">Tool Endpoint URL</label>
                    <input type="text" class="form-control" id="endpointUrl" name="endpoint_url" placeholder="/api/tools/your-tool-name" required>
                </div>
                
                <div class="mb-3">
                    <label for="authType" class="form-label">Authentication Type</label>
                    <select class="form-select" id="authType" name="auth_type">
                        <option value="none">None (public)</option>
                        <option value="api_key">API Key</option>
                        <option value="basic_auth">Basic Auth</option>
                        <option value="oauth2">OAuth 2.0</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary w-100">Register Tool</button>
            </form>
            
            <div id="validationFeedback" class="mt-3"></div>
        </div>
        
    </div>
    
    <!-- Edit Tool Modal -->
    <div class="modal fade" id="editToolModal" tabindex="-1" aria-labelledby="editToolModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class