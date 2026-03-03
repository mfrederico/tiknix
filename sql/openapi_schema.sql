-- Add OpenAPI tool registry table
CREATE TABLE openapi_tools (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT NULL,
    spec_url VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Add index for faster lookups by active status
CREATE INDEX idx_openapi_tools_is_active ON openapi_tools(is_active);