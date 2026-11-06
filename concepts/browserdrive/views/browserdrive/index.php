<?php
/** The runner: a person leaves this page open, and it plays the steps of the sessions agents open (assets/runner.js). */
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<style>
  .bd { display: grid; gap: 14px; }
  .bd-bar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
  .bd-stage { width: 100%; overflow: hidden; border: 1px solid rgba(127,127,127,.35); border-radius: 10px; background: #fff; }
  #bdFrame { width: 1280px; height: 800px; border: 0; transform-origin: 0 0; display: block; background: #fff; }
  .bd-cap { position: absolute; left: 10px; top: 10px; z-index: 3; padding: 4px 10px; border-radius: 8px; background: rgba(20,14,40,.85); color: #fff; font: 13px ui-monospace, monospace; opacity: 0; transition: opacity .2s; pointer-events: none; } .bd-cap.on { opacity: 1; }
  .bd-ptr { position: absolute; left: -9px; top: -9px; width: 18px; height: 18px; border-radius: 50%; background: rgba(255,80,160,.55); border: 2px solid #ff3d9a; z-index: 4; opacity: 0; transition: transform .25s ease, opacity .2s; pointer-events: none; } .bd-ptr.on { opacity: 1; } .bd-ptr.down { background: #ff3d9a; transform-origin: center; }
  .bd-box { position: absolute; z-index: 2; border: 2px solid #ff3d9a; border-radius: 4px; box-shadow: 0 0 0 4px rgba(255,61,154,.2); opacity: 0; transition: opacity .2s; pointer-events: none; } .bd-box.on { opacity: 1; }
  #bdLog { list-style: none; margin: 0; padding: 8px; max-height: 260px; overflow: auto; font: 12px/1.5 ui-monospace, monospace; border: 1px solid rgba(127,127,127,.35); border-radius: 10px; }
  #bdLog .bad { color: #c2410c; } #bdLog .ok { color: #2f7d32; }
  .bd textarea { width: 100%; font: 13px ui-monospace, monospace; }
  .bd table { border-collapse: collapse; font-size: 13px; } .bd td, .bd th { padding: 3px 10px 3px 0; text-align: left; }
</style>
<div class="bd">
  <h1>Browser drive</h1>
  <p>Leave this page open: it is the browser for <b>browser-drive sessions</b>. When an agent or a script opens one using <b>your own</b> API key, this page claims it, shows the page below and plays each step (click, type, wait, read, screenshot…), then sends the result back. The steps act as <b>you</b>, signed in here, so sessions may only open the sandbox pages listed below. It keeps working in a background tab.</p>
  <div class="bd-bar"><button type="button" id="bdStart" data-auto="<?= $allowed ? '1' : '0' ?>">Start</button><span id="bdStatus"><?= $allowed ? 'starting…' : 'No path is allowed yet: list the sandbox pages below first.' ?></span></div>
  <div class="bd-stage" id="bdStage"><iframe id="bdFrame" title="the driven page"></iframe></div>
  <ul id="bdLog"></ul>
  <form method="post" action="/browserdrive/allow">
    <?= csrf_field() ?>
    <label><b>Sandbox pages a session may open</b> (one per line; <code>/demo</code> exactly, <code>/demo/*</code> it and everything under it). Only pages and data that are safe to change.</label>
    <textarea name="paths" rows="4"><?= $h(implode("\n", $allowed)) ?></textarea>
    <label class="d-block my-2"><input type="checkbox" name="scripts" value="1"<?= $scripts ? ' checked' : '' ?>> <b>Let sessions run JavaScript</b> (<code>evaluate</code>, <code>waitFor</code> with <code>js</code>). Script runs as you and can reach every page of this site, not only the ones listed above.</label>
    <button type="submit">Save</button>
  </form>
  <h3>Recent sessions</h3>
  <table><tr><th>#</th><th>Path</th><th>Status</th><th>Steps</th><th>Opened</th><th>Note</th></tr>
  <?php foreach ($recent as $s): ?><tr><td><?= (int) $s->id ?></td><td><code><?= $h($s->path) ?></code></td><td><?= $h($s->status) ?><?= $s->why ? ' · ' . $h($s->why) : '' ?></td><td><?= (int) $s->steps ?></td><td><?= $h($s->openedAt) ?></td><td><?= $h($s->note) ?></td></tr><?php endforeach; ?>
  </table>
  <p class="small">Agents and scripts: your own API key, with the MCP tool <code>browserdrive_drive</code> or the Node client <a href="/browserdrive/client">/browserdrive/client</a>.</p>
</div>
<script>window.BD_CSRF = <?= json_encode(csrf_token()) ?>;</script>
<script src="/browserdrive/runnerjs"></script>
