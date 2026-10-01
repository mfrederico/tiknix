## When the Platform Is at Fault — Offer to Escalate, Then Ask

If, while working out why something does not work, the cause looks like the **Tiknix
platform** (the builder, pipelines runtime, hosting, connectors, billing) rather than this
app's own code, ASK the user: **"Should I escalate this to Tiknix support?"** Only on a yes,
call `send_to_tiknix_support(user_agreed: true, subject, message)` — the message written for
a support engineer: what was attempted, what happened (exact errors, URLs, times), what you
already checked, what you suspect. The ticket names this project; the answer reaches the
user in Communications and by email. Never send one without asking; 5 per hour at most.
