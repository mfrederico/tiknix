## MCP Server Security Model

The MCP server (`/mcp/message`) uses **two-layer authentication**:

### Layer 1: Route-Level (authcontrol table)
```
mcp::message = 101 (PUBLIC)
mcp::registry = 101 (PUBLIC)
```
**This is intentional!** These endpoints handle their own authentication.
Setting them to PUBLIC just means they're *reachable*, not *unprotected*.

### Layer 2: Controller-Level (API Key Auth)
The Mcp controller implements its own auth using API keys/tokens.

**Public methods** (no API key needed - discovery only):
- `initialize` - MCP protocol handshake
- `tools/list` - List available tools (metadata)
- `ping` - Health check

**Protected methods** (API key required):
- `tools/call` - Execute tools
- Any future action methods

### Why This Design?
1. MCP clients need to reach the endpoint to authenticate
2. Discovery (tools/list) is documentation, not execution
3. Standard MCP flow: connect → list tools → authenticate → call tools
4. The MCP Registry "Fetch Tools" feature needs unauthenticated discovery

### DON'T PANIC if you see:
- `mcp::message` at level 101 - This is correct
- `tools/list` returning data without auth - This is correct

### DO PANIC if you see:
- `tools/call` working without API key - This is a bug!
- New methods added to `$publicMethods` array without review

See `controls/Mcp.php` header comments for full security documentation.
