CREATE TABLE IF NOT EXISTS openapi_tools (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    spec_url VARCHAR(255),
    spec_content TEXT,
    endpoint_url VARCHAR(255),
    auth_type ENUM('none', 'api_key', 'oauth') DEFAULT 'none',
    status ENUM('pending', 'active', 'error', 'disabled') DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_openapitools_name ON openapi_tools(name);