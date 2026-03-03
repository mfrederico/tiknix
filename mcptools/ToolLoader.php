<?php

class ToolLoader {
    private OpenApiToolFactory $factory;
    
    public function __construct(OpenApiToolFactory $factory) {
        $this->factory = $factory;
    }
    
    public function loadAllTools(): array {
        return $this->factory->loadTools();
    }
}