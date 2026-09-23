<?php
/**
 * 13_ModelConnection.php — members' own model endpoints (MODEL_CONNECTIONS_PLAN.md).
 *
 * One row per endpoint + key a member brings (Anthropic, Ollama, OpenRouter, z.ai, a
 * self-hosted server). Sized on a padded ghost so every text column is created wide — a
 * RedBean widen later rebuilds the table, and on SQLite that has cost whole tables before.
 */
use \RedBeanPHP\R;

if (!$_tableCheck('modelconnection')) {
    $s = R::dispense('modelconnection');
    $s->member_id      = 0;
    $s->name           = '__schema_seed_' . str_repeat('x', 60);
    $s->preset         = str_repeat('x', 32);
    $s->protocol       = str_repeat('x', 16);
    $s->base_url       = str_repeat('x', 255);
    $s->auth           = str_repeat('x', 16);
    $s->key_enc        = str_repeat('x', 1000);
    $s->planner_model  = str_repeat('x', 160);
    $s->worker_model   = str_repeat('x', 160);
    $s->auditor_model  = str_repeat('x', 160);
    $s->resolver_model = str_repeat('x', 160);
    $s->haiku_model    = str_repeat('x', 160);
    $s->last_test_at   = date('Y-m-d H:i:s');
    $s->last_test_ok   = 0;
    $s->last_test_msg  = str_repeat('x', 500);
    $s->created_at     = date('Y-m-d H:i:s');
    $s->updated_at     = date('Y-m-d H:i:s');
    R::store($s);
    $_defer($s);
    unset($s);
}
