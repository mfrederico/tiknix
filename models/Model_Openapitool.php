<?php

class Model_Openapitool {
    private $id;
    private $name;
    private $description;
    private $spec_url;
    private $is_active;
    private $created_at;
    private $updated_at;

    public function __construct($data = []) {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }
    }

    public static function findById($id) {
        $result = Bean::findOne('openapi_tools', 'id = ?', [$id]);
        return $result ? new self($result->export()) : null;
    }

    public function getId() {
        return $this->id;
    }

    public function getName() {
        return $this->name;
    }

    public function getDescription() {
        return $this->description;
    }

    public function getSpecUrl() {
        return $this->spec_url;
    }

    public function getIsActive() {
        return (bool) $this->is_active;
    }

    public function getCreatedAt() {
        return $this->created_at;
    }

    public function getUpdatedAt() {
        return $this->updated_at;
    }

    public function activate() {
        if (!$this->is_active) {
            $this->is_active = true;
            $this->updated_at = date('Y-m-d H:i:s');
            Bean::store($this);
            return true;
        }
        return false;
    }

    public function deactivate() {
        if ($this->is_active) {
            $this->is_active = false;
            $this->updated_at = date('Y-m-d H:i:s');
            Bean::store($this);
            return true;
        }
        return false;
    }

    public static function getActiveTools() {
        return Bean::find('openapi_tools', 'is_active = ? ORDER BY name', [1]);
    }

    public static function getAllActiveTools() {
        $activeTools = [];
        foreach (self::getActiveTools() as $tool) {
            $model = new self($tool->export());
            $activeTools[$model->id] = [
                'name' => $model->name,
                'description' => $model->description,
                'spec_url' => $model->spec_url
            ];
        }
        return $activeTools;
    }

    public function toArray() {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'spec_url' => $this->spec_url,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }
}