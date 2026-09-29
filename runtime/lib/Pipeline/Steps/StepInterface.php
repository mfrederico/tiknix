<?php
/**
 * Pipeline\Steps\StepInterface — the one contract every step type implements.
 *
 * The single source of truth for a step type (its input schema AND its executor),
 * so there is no drift (myctobot had three). The Executor resolves {variables} in
 * the config BEFORE calling run(), so a step sees concrete values.
 *
 * run() returns:
 *   ['ok'=>bool, 'output'=>mixed, 'stdout'=>string, 'stderr'=>string, 'exit'=>int]
 * `output` is the structured result later steps reach via {<step>.output.path}.
 */

namespace app\Pipeline\Steps;

interface StepInterface {
    /** The `type` token used in a pipeline file (e.g. "shell"). */
    public static function type(): string;

    /** One-line description + the config keys this step accepts (for get_pipeline_components / the editor). */
    public static function schema(): array;

    /**
     * Execute with an already-variable-resolved config. $run carries run built-ins
     * (run_id, run_uid, run_directory, root) for steps that need them.
     */
    public function run(array $config, array $run): array;
}
