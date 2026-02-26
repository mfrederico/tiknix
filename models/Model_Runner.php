<?php
/**
 * FUSE Model for Runner (Workstation) beans
 *
 * Runners are remote machines where agents execute tasks via SSH+tmux.
 * Each runner has SSH connection details and optional SSH key association.
 */
class Model_Runner extends \RedBeanPHP\SimpleModel {

    /**
     * Validate before store
     */
    public function update() {
        $bean = $this->bean;

        // Name required
        if (empty(trim($bean->name ?? ''))) {
            throw new \InvalidArgumentException('Workstation name is required');
        }

        // Host required
        if (empty(trim($bean->host ?? ''))) {
            throw new \InvalidArgumentException('Host address is required');
        }

        // Default SSH user
        if (empty($bean->ssh_user)) {
            $bean->ssh_user = 'claudeuser';
        }

        // Default SSH port
        if (!$bean->ssh_port || $bean->ssh_port < 1 || $bean->ssh_port > 65535) {
            $bean->ssh_port = 22;
        }

        // Default max concurrent jobs
        if (!$bean->max_concurrent_jobs || $bean->max_concurrent_jobs < 1) {
            $bean->max_concurrent_jobs = 2;
        }

        // Validate capabilities JSON
        if ($bean->capabilities) {
            $decoded = json_decode($bean->capabilities);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Invalid capabilities JSON');
            }
        }

        // Set updated timestamp
        $bean->updated_at = date('Y-m-d H:i:s');
    }

    /**
     * Get the SSH key bean associated with this runner
     */
    public function getSshKey(): ?\RedBeanPHP\OODBBean {
        if ($this->bean->sshkey_id) {
            $key = \R::load('sshkey', (int)$this->bean->sshkey_id);
            return $key->id ? $key : null;
        }
        return null;
    }

    /**
     * Get capabilities as array
     */
    public function getCapabilities(): array {
        return json_decode($this->bean->capabilities ?: '[]', true) ?: [];
    }

    /**
     * Get health status badge class
     */
    public function healthBadgeClass(): string {
        return match ($this->bean->health_status) {
            'healthy' => 'bg-success',
            'unhealthy' => 'bg-danger',
            default => 'bg-secondary',
        };
    }

    /**
     * Convert to array for API responses
     */
    public function toArray(): array {
        $bean = $this->bean;
        return [
            'id' => (int)$bean->id,
            'name' => $bean->name,
            'description' => $bean->description,
            'host' => $bean->host,
            'ssh_user' => $bean->ssh_user,
            'ssh_port' => (int)$bean->ssh_port,
            'sshkey_id' => $bean->sshkey_id ? (int)$bean->sshkey_id : null,
            'capabilities' => $this->getCapabilities(),
            'max_concurrent_jobs' => (int)$bean->max_concurrent_jobs,
            'is_active' => (bool)$bean->is_active,
            'is_default' => (bool)$bean->is_default,
            'health_status' => $bean->health_status ?? 'unknown',
            'ssh_validated' => (bool)$bean->ssh_validated,
            'created_at' => $bean->created_at,
            'updated_at' => $bean->updated_at,
        ];
    }
}
