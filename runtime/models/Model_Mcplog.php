<?php
/**
 * MCP Log Model
 * Stores request/response history for MCP proxy debugging
 *
 * Fields:
 * - id: Primary key
 * - memberId: The authenticated member (0 for unauthenticated)
 * - apiKeyId: The API key used (if any)
 * - serverId: The MCP server ID (for proxied requests)
 * - method: JSON-RPC method (initialize, tools/list, tools/call, etc.)
 * - requestBody: Full JSON request body
 * - responseBody: Full JSON response body
 * - httpCode: HTTP response code
 * - duration: Request duration in milliseconds
 * - ipAddress: Client IP address
 * - userAgent: Client user agent
 * - error: Error message (if any)
 * - createdAt: Timestamp
 */
class Model_Mcplog extends \RedBeanPHP\SimpleModel {

    /**
     * Called before storing the bean
     */
    public function update() {
        if (empty($this->bean->createdAt)) {
            $this->bean->createdAt = date('Y-m-d H:i:s');
        }
    }

    /**
     * Get formatted duration
     */
    public function getFormattedDuration(): string {
        $ms = $this->bean->duration ?? 0;
        if ($ms < 1000) {
            return $ms . 'ms';
        }
        return round($ms / 1000, 2) . 's';
    }

    /**
     * Get truncated request body for display
     */
    public function getTruncatedRequest(int $length = 100): string {
        $body = $this->bean->requestBody ?? '';
        if (strlen($body) > $length) {
            return substr($body, 0, $length) . '...';
        }
        return $body;
    }

    /**
     * Get truncated response body for display
     */
    public function getTruncatedResponse(int $length = 100): string {
        $body = $this->bean->responseBody ?? '';
        if (strlen($body) > $length) {
            return substr($body, 0, $length) . '...';
        }
        return $body;
    }

    /**
     * Check if this log entry has an error
     */
    public function hasError(): bool {
        return !empty($this->bean->error) || ($this->bean->httpCode ?? 200) >= 400;
    }
}
