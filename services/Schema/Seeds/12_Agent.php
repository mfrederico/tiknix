<?php
/**
 * 12_Agent.php — the app's named agents (COMPONENTS_PLAN.md, "Named agents for the app").
 *
 * One row per agent a pipeline's `agent` step can name: kind cli (an EngineRegistry
 * engine, run through the install's bin/claude) or openai (any OpenAI-compatible
 * chat-completions endpoint), with its pre-prompt, model, timeout and — encrypted with the
 * install's own key — an API key. Sized here, on a padded ghost, so a production install
 * (frozen schema) has the table before the Data page's first save, and so text columns
 * are created wide enough that a long pre-prompt never triggers a RedBean widen-rebuild.
 */
use \RedBeanPHP\R;

if (!$_tableCheck('agent')) {
    $s = R::dispense('agent');
    $s->name        = '__schema_seed_' . str_repeat('x', 60);
    $s->description = str_repeat('x', 500);
    $s->kind        = str_repeat('x', 16);
    $s->engine      = str_repeat('x', 32);
    $s->model       = str_repeat('x', 100);
    $s->endpoint    = str_repeat('x', 255);
    $s->api_key_enc = str_repeat('x', 500);
    $s->timeout     = 600;
    $s->pre_prompt  = str_repeat('x', 8000);
    $s->is_default  = 0;
    $s->connection_ref = 0;
    $s->created_at  = date('Y-m-d H:i:s');
    $s->updated_at  = date('Y-m-d H:i:s');
    R::store($s);
    $_defer($s);
    unset($s);
}

// Added with the 'member' kind: which of the owner's model connections (a row id on core).
if ($_tableCheck('agent') && !array_key_exists('connection_ref', R::inspect('agent'))) {
    R::exec('ALTER TABLE agent ADD COLUMN connection_ref INTEGER DEFAULT 0');
}
