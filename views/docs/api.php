<div class="container">
    <div class="row">
        <div class="col-md-3">
            <!-- Sidebar Navigation -->
            <div class="sticky-top pt-3">
                <h5>Documentation</h5>
                <div class="list-group">
                    <a href="/docs" class="list-group-item list-group-item-action">
                        <i class="bi bi-book"></i> README
                    </a>
                    <a href="/docs/api" class="list-group-item list-group-item-action active">
                        <i class="bi bi-code-slash"></i> API Reference
                    </a>
                    <a href="/docs/cli" class="list-group-item list-group-item-action">
                        <i class="bi bi-terminal"></i> CLI Reference
                    </a>
                    <a href="/help" class="list-group-item list-group-item-action">
                        <i class="bi bi-question-circle"></i> Help Center
                    </a>
                </div>
            </div>
        </div>
        
        <div class="col-md-9">
            <h1>API Documentation</h1>
            
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> This framework supports building RESTful APIs. Below are examples and guidelines for creating API endpoints.
            </div>
            
            <h2>Creating API Endpoints</h2>
            
            <h3>Basic API Controller</h3>
            <pre><code class="language-php">&lt;?php
namespace app;

use \Flight as Flight;
use \RedBeanPHP\R as R;

class Api extends BaseControls\Control {
    
    // GET /api/users
    public function users() {
        $users = R::findAll('member', 'status = ?', ['active']);
        
        $this->json([
            'success' => true,
            'data' => array_values($users)
        ]);
    }
    
    // GET /api/user?id=123
    public function user() {
        $request = Flight::request();
        $id = $request->query->id;
        
        if (!$id) {
            $this->json(['error' => 'ID required'], 400);
            return;
        }
        
        $user = R::load('member', $id);
        if (!$user->id) {
            $this->json(['error' => 'User not found'], 404);
            return;
        }
        
        $this->json([
            'success' => true,
            'data' => $user->export()
        ]);
    }
    
    // POST /api/user
    public function create() {
        $request = Flight::request();
        
        // Validate input
        $username = $request->data->username;
        $email = $request->data->email;
        
        if (!$username || !$email) {
            $this->json(['error' => 'Username and email required'], 400);
            return;
        }
        
        // Create user
        $user = R::dispense('member');
        $user->username = $username;
        $user->email = $email;
        $user->created_at = date('Y-m-d H:i:s');
        
        try {
            $id = R::store($user);
            $this->json([
                'success' => true,
                'id' => $id
            ]);
        } catch (\Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }
}</code></pre>
            
            <h2>Authentication</h2>
            
            <h3>API Key Authentication</h3>
            <pre><code class="language-php">protected function requireApiKey() {
    $request = Flight::request();
    $apiKey = $request->header('X-API-Key') ?? $request->query->api_key;
    
    if (!$apiKey) {
        $this->json(['error' => 'API key required'], 401);
        return false;
    }
    
    // Validate API key
    $member = R::findOne('member', 'api_key = ?', [$apiKey]);
    if (!$member) {
        $this->json(['error' => 'Invalid API key'], 401);
        return false;
    }
    
    // Set member context
    Flight::set('api_member', $member);
    return true;
}</code></pre>
            
            <h3>JWT Authentication</h3>
            <p>For JWT authentication, install the firebase/php-jwt package:</p>
            <pre><code>composer require firebase/php-jwt</code></pre>
            
            <h2>Response Formats</h2>
            
            <h3>Success Response</h3>
            <pre><code class="language-json">{
    "success": true,
    "data": {
        "id": 123,
        "username": "john_doe",
        "email": "john@example.com"
    },
    "meta": {
        "total": 1,
        "page": 1
    }
}</code></pre>
            
            <h3>Error Response</h3>
            <pre><code class="language-json">{
    "success": false,
    "error": {
        "code": 404,
        "message": "Resource not found",
        "details": "User with ID 999 does not exist"
    }
}</code></pre>
            
            <h2>HTTP Status Codes</h2>
            
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Status</th>
                        <th>When to Use</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>200</code></td>
                        <td>OK</td>
                        <td>Successful GET or PUT request</td>
                    </tr>
                    <tr>
                        <td><code>201</code></td>
                        <td>Created</td>
                        <td>Successful POST request that creates a resource</td>
                    </tr>
                    <tr>
                        <td><code>204</code></td>
                        <td>No Content</td>
                        <td>Successful DELETE request</td>
                    </tr>
                    <tr>
                        <td><code>400</code></td>
                        <td>Bad Request</td>
                        <td>Invalid request parameters</td>
                    </tr>
                    <tr>
                        <td><code>401</code></td>
                        <td>Unauthorized</td>
                        <td>Missing or invalid authentication</td>
                    </tr>
                    <tr>
                        <td><code>403</code></td>
                        <td>Forbidden</td>
                        <td>Authenticated but not authorized</td>
                    </tr>
                    <tr>
                        <td><code>404</code></td>
                        <td>Not Found</td>
                        <td>Resource doesn't exist</td>
                    </tr>
                    <tr>
                        <td><code>422</code></td>
                        <td>Unprocessable Entity</td>
                        <td>Validation errors</td>
                    </tr>
                    <tr>
                        <td><code>500</code></td>
                        <td>Internal Server Error</td>
                        <td>Server error</td>
                    </tr>
                </tbody>
            </table>
            
            <h2>Pagination</h2>
            
            <pre><code class="language-php">public function list() {
    $request = Flight::request();
    $page = (int)($request->query->page ?? 1);
    $perPage = (int)($request->query->per_page ?? 20);
    $offset = ($page - 1) * $perPage;
    
    $total = R::count('member');
    $members = R::findAll('member', 
        'ORDER BY created_at DESC LIMIT ? OFFSET ?', 
        [$perPage, $offset]
    );
    
    $this->json([
        'success' => true,
        'data' => array_values($members),
        'meta' => [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage)
        ]
    ]);
}</code></pre>
            
            <h2>CORS Configuration</h2>
            
            <p>Enable CORS in your <code>conf/config.ini</code>:</p>
            <pre><code class="language-ini">[cors]
enabled = true
origin = "*"
methods = "GET, POST, PUT, DELETE, OPTIONS"
headers = "Content-Type, Authorization, X-API-Key"</code></pre>
            
            <h2>Rate Limiting</h2>
            
            <p>Implement basic rate limiting:</p>
            <pre><code class="language-php">protected function checkRateLimit($identifier, $limit = 60, $window = 60) {
    $key = "rate_limit:{$identifier}";
    $count = R::count('api_requests', 
        'identifier = ? AND created_at > ?', 
        [$key, date('Y-m-d H:i:s', time() - $window)]
    );
    
    if ($count >= $limit) {
        $this->json(['error' => 'Rate limit exceeded'], 429);
        return false;
    }
    
    // Log request
    $request = R::dispense('api_requests');
    $request->identifier = $key;
    $request->created_at = date('Y-m-d H:i:s');
    R::store($request);
    
    return true;
}</code></pre>
            
            <h2>Testing API Endpoints</h2>
            
            <h3>Using cURL</h3>
            <pre><code class="language-bash"># GET request
curl -X GET http://localhost:8000/api/users

# POST request with JSON
curl -X POST http://localhost:8000/api/user \
  -H "Content-Type: application/json" \
  -d '{"username":"john","email":"john@example.com"}'

# With API key
curl -X GET http://localhost:8000/api/users \
  -H "X-API-Key: your-api-key-here"</code></pre>
            
            <h3>Using JavaScript Fetch</h3>
            <pre><code class="language-javascript">// GET request
fetch('/api/users')
  .then(response => response.json())
  .then(data => console.log(data));

// POST request
fetch('/api/user', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    username: 'john',
    email: 'john@example.com'
  })
})
.then(response => response.json())
.then(data => console.log(data));</code></pre>
        </div>
    </div>
</div>

<style>
.sticky-top {
    top: 20px;
}

pre {
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 15px;
    overflow-x: auto;
}

code {
    color: #e83e8c;
}

pre code {
    color: inherit;
}

h2 {
    margin-top: 30px;
    padding-bottom: 10px;
    border-bottom: 1px solid #dee2e6;
}

h3 {
    margin-top: 20px;
    color: #495057;
}
</style>