## Answering a Support Ticket (control plane)

When you investigate a ticket here, never send the answer yourself. Leave it for a person: the `support_draft` tool, or from a terminal
`php scripts/support-draft.php --ticket=N --reply=FILE --findings=FILE`. The reply is plain text for the customer; paths, log lines and
anything internal go in the findings, which only admins see. A person reads both on `/contact/view?id=N`, edits, and presses Send Response.
