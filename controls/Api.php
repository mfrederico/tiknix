<?php

namespace app;

use Flight;
use app\BaseControls\Control;
use app\PhpValidator;

/**
 * API Controller
 *
 * Provides JSON API endpoints for AJAX operations.
 * All methods return JSON responses.
 */
class Api extends Control
{
    /**
     * Validate PHP code
     *
     * POST /api/validate-php
     * Body: { "code": "<?php ...", "type": "tool"|"hook" }
     *
     * Returns validation results including syntax, structure, and security checks.
     */
    public function validatePhp(): void
    {
        // Require admin level for code validation
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('Unauthorized', 403);
            return;
        }

        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['code'])) {
            Flight::jsonError('Missing code parameter', 400);
            return;
        }

        $code = $input['code'];
        $type = $input['type'] ?? 'tool';

        if (!in_array($type, ['tool', 'hook'])) {
            $type = 'tool';
        }

        // Run validation
        $result = PhpValidator::validateAll($code, $type);

        Flight::json($result);
    }

    /**
     * Get tool metadata from code
     *
     * POST /api/tool-metadata
     * Body: { "code": "<?php ..." }
     */
    public function toolMetadata(): void
    {
        if (!Flight::hasLevel(LEVELS['ADMIN'])) {
            Flight::jsonError('Unauthorized', 403);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['code'])) {
            Flight::jsonError('Missing code parameter', 400);
            return;
        }

        $metadata = PhpValidator::extractToolMetadata($input['code']);

        Flight::json([
            'success' => $metadata !== null,
            'metadata' => $metadata
        ]);
    }
}
