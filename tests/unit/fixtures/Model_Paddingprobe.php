<?php
/**
 * A FUSE model that validates on store — what Model_Interview / Model_Handoffstep do.
 * Test fixture for SchemaPaddingTest: a seed's sizing probe (str_repeat('x', N)) must get
 * past this without weakening it.
 */
class Model_Paddingprobe extends \RedBeanPHP\SimpleModel {
    public const PHASES = ['discovery', 'brief'];

    public function update() {
        if (!in_array((string) $this->bean->phase, self::PHASES, true)) {
            throw new \InvalidArgumentException("Paddingprobe: unknown phase '{$this->bean->phase}'");
        }
    }
}
