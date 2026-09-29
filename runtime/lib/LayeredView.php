<?php
/**
 * LayeredView — Flight's view with a fallback: a template is looked up in the APP's views/
 * first, then in the runtime's views/. An app overrides a runtime page by placing a file at
 * the same relative path (app\Overrides tracks what that costs on an update). Absolute
 * template paths (a concept's own views) are untouched, exactly as in Flight's View.
 */

namespace app;

class LayeredView extends \flight\template\View {

    /** @var string[] extra directories searched after $this->path, in order */
    public array $fallbacks = [];

    public function getTemplate(string $file): string {
        $first = parent::getTemplate($file);
        if (str_starts_with($file, '/') || is_file($first)) return $first;
        $ext = $this->extension;
        $name = (!empty($ext) && substr($file, -strlen($ext)) !== $ext) ? $file . $ext : $file;
        foreach ($this->fallbacks as $dir) {
            $candidate = rtrim($dir, '/') . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate)) return $candidate;
        }
        return $first;   // not found anywhere: Flight's own "template not found" names the app path
    }
}
