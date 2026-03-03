<?php

class OpenApiValidator {
    private array $validationErrors = [];
    
    public function validateSpec(array $specData): bool {
        if (!isset($specData['openapi']) || !preg_match('/^3\.[0-9]+\.[0-9]+$/', $specData['openapi'])) {
            $this->addValidationError('Invalid OpenAPI version');
            return false;
        }
        
        if (empty($specData['paths'])) {
            $this->