/**
 * Tiknix Regression Test Suite
 *
 * Run with: npx playwright test tiknix-regression.spec.js
 *
 * Prerequisites:
 * - npm install -D @playwright/test
 * - npx playwright install
 */

const { test, expect } = require('@playwright/test');

const BASE_URL = 'https://tiknix.com';
const TEST_USER = { username: 'admin', password: 'admin1234' };

// Helper to login
async function login(page) {
  await page.goto(`${BASE_URL}/auth/login`);
  await page.getByRole('textbox', { name: 'Username or Email' }).fill(TEST_USER.username);
  await page.getByRole('textbox', { name: 'Password' }).fill(TEST_USER.password);
  await page.getByRole('button', { name: 'Login' }).click();
  await expect(page).toHaveURL(`${BASE_URL}/dashboard`);
}

// ============================================
// AUTHENTICATION TESTS
// ============================================

test.describe('Authentication', () => {
  test('should display login page', async ({ page }) => {
    await page.goto(`${BASE_URL}/auth/login`);
    await expect(page).toHaveTitle('Login');
    await expect(page.getByRole('heading', { name: 'Login' })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Username or Email' })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Password' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Login' })).toBeVisible();
  });

  test('should login with valid credentials', async ({ page }) => {
    await page.goto(`${BASE_URL}/auth/login`);
    await page.getByRole('textbox', { name: 'Username or Email' }).fill(TEST_USER.username);
    await page.getByRole('textbox', { name: 'Password' }).fill(TEST_USER.password);
    await page.getByRole('button', { name: 'Login' }).click();

    await expect(page).toHaveURL(`${BASE_URL}/dashboard`);
    await expect(page.getByText('Welcome back, admin!')).toBeVisible();
  });

  test('should reject invalid credentials', async ({ page }) => {
    await page.goto(`${BASE_URL}/auth/login`);
    await page.getByRole('textbox', { name: 'Username or Email' }).fill('invalid');
    await page.getByRole('textbox', { name: 'Password' }).fill('wrongpassword');
    await page.getByRole('button', { name: 'Login' }).click();

    // Should stay on login page or show error
    await expect(page).toHaveURL(/\/auth\/login/);
  });

  test('should logout successfully', async ({ page }) => {
    await login(page);
    await page.goto(`${BASE_URL}/auth/logout`);

    // Should redirect to home and show logout message
    await expect(page).toHaveURL(BASE_URL + '/');
    await expect(page.getByText('You have been logged out')).toBeVisible();
  });

  test('should display register page', async ({ page }) => {
    await page.goto(`${BASE_URL}/auth/register`);
    await expect(page.getByRole('heading', { name: 'Register' })).toBeVisible();
  });

  test('should display forgot password page', async ({ page }) => {
    await page.goto(`${BASE_URL}/auth/forgot`);
    await expect(page).toHaveURL(`${BASE_URL}/auth/forgot`);
  });
});

// ============================================
// NAVIGATION TESTS
// ============================================

test.describe('Navigation', () => {
  test('should display home page', async ({ page }) => {
    await page.goto(BASE_URL);
    await expect(page).toHaveTitle('Welcome');
    await expect(page.getByRole('heading', { name: 'Welcome to Tiknix' })).toBeVisible();
  });

  test('should have working footer links', async ({ page }) => {
    await page.goto(BASE_URL);

    // Check footer links exist
    await expect(page.getByRole('link', { name: 'Home' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Documentation' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Help Center' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Contact' })).toBeVisible();
  });

  test('should navigate to documentation', async ({ page }) => {
    await page.goto(`${BASE_URL}/docs`);
    await expect(page).toHaveURL(`${BASE_URL}/docs`);
  });

  test('should navigate to help center', async ({ page }) => {
    await page.goto(`${BASE_URL}/help`);
    await expect(page).toHaveURL(`${BASE_URL}/help`);
  });
});

// ============================================
// DASHBOARD TESTS (Requires Login)
// ============================================

test.describe('Dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('should display dashboard after login', async ({ page }) => {
    await expect(page).toHaveTitle('Dashboard');
    await expect(page.getByRole('heading', { name: 'Welcome to Your Dashboard' })).toBeVisible();
  });

  test('should show user profile information', async ({ page }) => {
    await expect(page.getByText('Username:')).toBeVisible();
    await expect(page.getByText('admin@example.com')).toBeVisible();
  });

  test('should have quick action links', async ({ page }) => {
    await expect(page.getByRole('link', { name: 'My Profile' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Settings' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Admin Panel' })).toBeVisible();
  });
});

// ============================================
// ADMIN PANEL TESTS
// ============================================

test.describe('Admin Panel', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('should display admin dashboard', async ({ page }) => {
    await page.goto(`${BASE_URL}/admin`);
    await expect(page).toHaveTitle('Admin Dashboard');
    await expect(page.getByRole('heading', { name: 'Admin Dashboard' })).toBeVisible();
  });

  test('should show system statistics', async ({ page }) => {
    await page.goto(`${BASE_URL}/admin`);
    await expect(page.getByText('Total Members')).toBeVisible();
    await expect(page.getByText('Permissions')).toBeVisible();
    await expect(page.getByText('Active Sessions')).toBeVisible();
  });

  test('should have quick action links', async ({ page }) => {
    await page.goto(`${BASE_URL}/admin`);
    await expect(page.getByRole('link', { name: 'Member Management' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Permission Management' })).toBeVisible();
  });
});

// ============================================
// MCP REGISTRY TESTS
// ============================================

test.describe('MCP Registry', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('should display MCP registry list', async ({ page }) => {
    await page.goto(`${BASE_URL}/mcpregistry`);
    await expect(page).toHaveTitle('MCP Server Registry');
    await expect(page.getByRole('heading', { name: 'MCP Registry' })).toBeVisible();
  });

  test('should show registered servers', async ({ page }) => {
    await page.goto(`${BASE_URL}/mcpregistry`);
    await expect(page.getByText('servers registered')).toBeVisible();
  });

  test('should have search and filter options', async ({ page }) => {
    await page.goto(`${BASE_URL}/mcpregistry`);
    await expect(page.getByRole('textbox', { name: 'Search servers...' })).toBeVisible();
    await expect(page.getByRole('combobox').first()).toBeVisible();
  });

  test('should navigate to add server page', async ({ page }) => {
    await page.goto(`${BASE_URL}/mcpregistry/add`);
    await expect(page).toHaveTitle('Add MCP Server');
    await expect(page.getByRole('heading', { name: 'Add MCP Server' })).toBeVisible();
  });

  // Known bug - CSRF issue
  test.skip('should create new MCP server', async ({ page }) => {
    await page.goto(`${BASE_URL}/mcpregistry/add`);
    await page.getByRole('textbox', { name: 'Name *' }).fill('Test Server');
    await page.getByRole('textbox', { name: 'Endpoint URL *' }).fill('http://localhost:9999/mcp');
    await page.getByRole('button', { name: 'Create Server' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/mcpregistry`);
  });
});

// ============================================
// TEAMS TESTS
// ============================================

test.describe('Teams', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('should display teams list', async ({ page }) => {
    await page.goto(`${BASE_URL}/teams`);
    await expect(page).toHaveTitle('My Teams');
    await expect(page.getByRole('heading', { name: 'My Teams' })).toBeVisible();
  });

  test('should show empty state when no teams', async ({ page }) => {
    await page.goto(`${BASE_URL}/teams`);
    // Check for either teams list or empty state
    const hasTeams = await page.getByText('No Teams Yet').isVisible().catch(() => false);
    if (hasTeams) {
      await expect(page.getByRole('link', { name: 'Create Your First Team' })).toBeVisible();
    }
  });

  // Known bug - 404 on create page
  test.skip('should navigate to create team page', async ({ page }) => {
    await page.goto(`${BASE_URL}/teams/create`);
    await expect(page.getByRole('heading', { name: 'Create Team' })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Team Name' })).toBeVisible();
  });
});

// ============================================
// WORKBENCH TESTS
// ============================================

test.describe('Workbench', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('should display workbench dashboard', async ({ page }) => {
    await page.goto(`${BASE_URL}/workbench`);
    await expect(page).toHaveTitle('Workbench');
    await expect(page.getByRole('heading', { name: 'Workbench' })).toBeVisible();
  });

  test('should show status filters', async ({ page }) => {
    await page.goto(`${BASE_URL}/workbench`);
    await expect(page.getByRole('link', { name: /All Tasks/ })).toBeVisible();
    await expect(page.getByRole('link', { name: /Pending/ })).toBeVisible();
    await expect(page.getByRole('link', { name: /Running/ })).toBeVisible();
    await expect(page.getByRole('link', { name: /Completed/ })).toBeVisible();
  });

  test('should show type filters', async ({ page }) => {
    await page.goto(`${BASE_URL}/workbench`);
    await expect(page.getByRole('link', { name: 'Feature' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Bug Fix' })).toBeVisible();
  });

  // Known bug - 404 on create page
  test.skip('should navigate to create task page', async ({ page }) => {
    await page.goto(`${BASE_URL}/workbench/create`);
    await expect(page.getByRole('heading', { name: 'Create Task' })).toBeVisible();
  });
});

// ============================================
// MEMBER PROFILE TESTS
// ============================================

test.describe('Member Profile', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('should display member profile', async ({ page }) => {
    await page.goto(`${BASE_URL}/member/profile`);
    await expect(page).toHaveTitle('My Profile');
    await expect(page.getByRole('heading', { name: 'My Profile' })).toBeVisible();
  });

  test('should show profile information', async ({ page }) => {
    await page.goto(`${BASE_URL}/member/profile`);
    await expect(page.getByText('Username:')).toBeVisible();
    await expect(page.getByText('Email:')).toBeVisible();
    await expect(page.getByText('Account Level:')).toBeVisible();
  });

  test('should have edit profile link', async ({ page }) => {
    await page.goto(`${BASE_URL}/member/profile`);
    await expect(page.getByRole('link', { name: 'Edit Profile' })).toBeVisible();
  });
});

// ============================================
// API KEYS TESTS
// ============================================

test.describe('API Keys', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('should display API keys list', async ({ page }) => {
    await page.goto(`${BASE_URL}/apikeys`);
    await expect(page).toHaveTitle('API Keys');
    await expect(page.getByRole('heading', { name: 'API Keys' })).toBeVisible();
  });

  test('should show API keys table', async ({ page }) => {
    await page.goto(`${BASE_URL}/apikeys`);
    await expect(page.getByRole('columnheader', { name: 'Name' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Token' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Status' })).toBeVisible();
  });

  test('should navigate to create API key page', async ({ page }) => {
    await page.goto(`${BASE_URL}/apikeys/add`);
    await expect(page).toHaveTitle('Create API Key');
    await expect(page.getByRole('heading', { name: 'Create API Key' })).toBeVisible();
  });

  test('should show scope options', async ({ page }) => {
    await page.goto(`${BASE_URL}/apikeys/add`);
    await expect(page.getByText('mcp:*')).toBeVisible();
    await expect(page.getByText('mcp:read')).toBeVisible();
  });

  // Known bug - CSRF issue
  test.skip('should create new API key', async ({ page }) => {
    await page.goto(`${BASE_URL}/apikeys/add`);
    await page.getByRole('textbox', { name: 'Name *' }).fill('Test Key');
    await page.getByRole('checkbox', { name: /mcp:\*/ }).check();
    await page.getByRole('button', { name: 'Create Key' }).click();
    await expect(page).toHaveURL(`${BASE_URL}/apikeys`);
  });
});

// ============================================
// CONTACT FORM TESTS
// ============================================

test.describe('Contact Form', () => {
  test('should display contact form', async ({ page }) => {
    await page.goto(`${BASE_URL}/contact`);
    await expect(page).toHaveTitle('Contact Support');
    await expect(page.getByRole('heading', { name: 'Contact Support' })).toBeVisible();
  });

  test('should have all form fields', async ({ page }) => {
    await page.goto(`${BASE_URL}/contact`);
    await expect(page.getByRole('textbox', { name: 'Your Name *' })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Email Address *' })).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Category' })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Subject *' })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Message *' })).toBeVisible();
  });

  test('should submit contact form', async ({ page }) => {
    await page.goto(`${BASE_URL}/contact`);
    await page.getByRole('textbox', { name: 'Your Name *' }).fill('Test User');
    await page.getByRole('textbox', { name: 'Email Address *' }).fill('test@example.com');
    await page.getByRole('textbox', { name: 'Subject *' }).fill('Test Subject');
    await page.getByRole('textbox', { name: 'Message *' }).fill('Test message content');
    await page.getByRole('button', { name: 'Send Message' }).click();

    await expect(page.getByText('Thank you for contacting us!')).toBeVisible();
  });
});

// ============================================
// ERROR HANDLING TESTS
// ============================================

test.describe('Error Handling', () => {
  test('should show 404 page for invalid routes', async ({ page }) => {
    await page.goto(`${BASE_URL}/nonexistent-page-12345`);
    await expect(page.getByRole('heading', { name: '404' })).toBeVisible();
    await expect(page.getByText('Page Not Found')).toBeVisible();
  });

  test('should redirect unauthenticated users from protected routes', async ({ page }) => {
    await page.goto(`${BASE_URL}/dashboard`);
    // Should redirect to login
    await expect(page).toHaveURL(/\/auth\/login/);
  });
});
