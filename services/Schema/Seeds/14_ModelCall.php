<?php
/**
 * 14_ModelCall.php — model calls a project's pipeline asked core to make on its owner's
 * model connection (MODEL_CONNECTIONS_PLAN.md, phase 4).
 *
 * The key never leaves core, so a pipeline agent step POSTs the call to
 * /brokerinfo/modelcall, gets a job id back at once, and polls /brokerinfo/modelresult
 * while core finishes the call after the response (a model can take longer than nginx's
 * 60 s fastcgi timeout). One row per call; also the usage record per project + connection.
 *
 * _ref, not _id: an instance is deprovisioned and a connection hard-deleted, and a real
 * FOREIGN KEY would make either delete fail forever. Indexed explicitly, as _ref columns
 * get no automatic index.
 */
use \RedBeanPHP\R;

if (!$_tableCheck('modelcall')) {
    $s = R::dispense('modelcall');
    $s->instance_ref   = 0;
    $s->connection_ref = 0;
    $s->member_ref     = 0;
    $s->model          = str_repeat('x', 160);
    $s->status         = str_repeat('x', 16);
    $s->result_text    = str_repeat('x', 20000);
    $s->usage_json     = str_repeat('x', 500);
    $s->error          = str_repeat('x', 1000);
    $s->http           = 0;
    $s->created_at     = date('Y-m-d H:i:s');
    $s->finished_at    = date('Y-m-d H:i:s');
    R::store($s);
    $_defer($s);
    unset($s);
}
R::exec('CREATE INDEX IF NOT EXISTS idx_modelcall_instance ON modelcall (instance_ref)');
R::exec('CREATE INDEX IF NOT EXISTS idx_modelcall_connection ON modelcall (connection_ref)');
