<?php
/**
 * Port Manager
 *
 * Handles port assignment for test servers. Each member gets a consistent
 * port based on their member ID to avoid conflicts.
 *
 * Port range: 8001-9000 (BASE_PORT + 1 to BASE_PORT + PORT_RANGE)
 * Formula: BASE_PORT + (member_id % PORT_RANGE) + 1
 *
 * This ensures:
 * - Member 1 gets port 8002
 * - Member 100 gets port 8101
 * - Member 1001 gets port 8002 (wraps around)
 */

namespace app;

class PortManager {

    const BASE_PORT = 8000;
    const PORT_RANGE = 1000;

    /**
     * Get the assigned port for a member
     *
     * @param int $memberId Member ID
     * @return int Port number
     */
    public static function getPortForMember(int $memberId): int {
        // Add 1 to avoid port 8000 (often used by main server)
        return self::BASE_PORT + ($memberId % self::PORT_RANGE) + 1;
    }

    /**
     * Check if a port is available (not in use)
     *
     * @param int $port Port number to check
     * @param string $host Host to check (default: 127.0.0.1)
     * @return bool True if port is available
     */
    public static function isPortAvailable(int $port, string $host = '127.0.0.1'): bool {
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);

        if ($connection) {
            fclose($connection);
            return false; // Port is in use
        }

        return true; // Port is available
    }

    /**
     * Get port status information
     *
     * @param int $port Port number
     * @return array Status info with 'available' and 'message' keys
     */
    public static function getPortStatus(int $port): array {
        $available = self::isPortAvailable($port);

        return [
            'port' => $port,
            'available' => $available,
            'message' => $available
                ? "Port {$port} is available"
                : "Port {$port} is already in use"
        ];
    }

    /**
     * Find next available port starting from a base
     *
     * @param int $startPort Starting port to check
     * @param int $maxAttempts Maximum ports to try
     * @return int|null Available port or null if none found
     */
    public static function findAvailablePort(int $startPort, int $maxAttempts = 100): ?int {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $port = $startPort + $i;
            if (self::isPortAvailable($port)) {
                return $port;
            }
        }

        return null;
    }

    /**
     * Get port info for a task
     *
     * @param int $memberId Member ID
     * @return array Port info with 'port', 'available', 'fallback' keys
     */
    public static function getTaskPortInfo(int $memberId): array {
        $assignedPort = self::getPortForMember($memberId);
        $available = self::isPortAvailable($assignedPort);

        $result = [
            'port' => $assignedPort,
            'available' => $available,
            'fallback' => null
        ];

        // If assigned port is busy, try to find an alternative
        if (!$available) {
            $fallback = self::findAvailablePort($assignedPort + 1, 50);
            $result['fallback'] = $fallback;
        }

        return $result;
    }
}
