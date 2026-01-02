#!/bin/bash
#
# Tiknix Framework Installer
# ==========================
# This script sets up a fresh Tiknix installation with sensible defaults.
#
# Requirements:
#   - Linux or WSL (Windows Subsystem for Linux)
#   - PHP 8.1 or higher
#   - Composer (will offer to install if missing)
#   - Git (for cloning, if not already downloaded)
#
# Optional (recommended for AI-assisted development):
#   - Claude CLI (npm install -g @anthropic-ai/claude-code)
#   - Node.js/npm (for Claude CLI)
#
# Usage:
#   chmod +x install.sh
#   ./install.sh
#
# For non-interactive install:
#   ./install.sh --auto
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AUTO_MODE=false
DEFAULT_PORT=8000
DEFAULT_HOST="localhost"

# Parse arguments
for arg in "$@"; do
    case $arg in
        --auto)
            AUTO_MODE=true
            shift
            ;;
        --port=*)
            DEFAULT_PORT="${arg#*=}"
            shift
            ;;
        --host=*)
            DEFAULT_HOST="${arg#*=}"
            shift
            ;;
        -h|--help)
            echo "Tiknix Framework Installer"
            echo ""
            echo "Usage: ./install.sh [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --auto        Non-interactive mode with defaults"
            echo "  --port=PORT   Set development server port (default: 8000)"
            echo "  --host=HOST   Set development server host (default: localhost)"
            echo "  -h, --help    Show this help message"
            exit 0
            ;;
    esac
done

# Print banner
print_banner() {
    echo -e "${CYAN}"
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║                                                            ║"
    echo "║   ████████╗██╗██╗  ██╗███╗   ██╗██╗██╗  ██╗               ║"
    echo "║   ╚══██╔══╝██║██║ ██╔╝████╗  ██║██║╚██╗██╔╝               ║"
    echo "║      ██║   ██║█████╔╝ ██╔██╗ ██║██║ ╚███╔╝                ║"
    echo "║      ██║   ██║██╔═██╗ ██║╚██╗██║██║ ██╔██╗                ║"
    echo "║      ██║   ██║██║  ██╗██║ ╚████║██║██╔╝ ██╗               ║"
    echo "║      ╚═╝   ╚═╝╚═╝  ╚═╝╚═╝  ╚═══╝╚═╝╚═╝  ╚═╝               ║"
    echo "║                                                            ║"
    echo "║         PHP Framework Installer v1.0                       ║"
    echo "║                                                            ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

# Print step header
step() {
    echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BOLD}$1${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
}

# Print success message
success() {
    echo -e "${GREEN}✓${NC} $1"
}

# Print warning message
warn() {
    echo -e "${YELLOW}⚠${NC} $1"
}

# Print error message
error() {
    echo -e "${RED}✗${NC} $1"
}

# Print info message
info() {
    echo -e "${CYAN}ℹ${NC} $1"
}

# Ask yes/no question
ask_yn() {
    if [ "$AUTO_MODE" = true ]; then
        return 0
    fi
    local prompt="$1"
    local default="${2:-y}"

    if [ "$default" = "y" ]; then
        prompt="$prompt [Y/n] "
    else
        prompt="$prompt [y/N] "
    fi

    read -r -p "$prompt" response
    response=${response:-$default}

    case "$response" in
        [yY][eE][sS]|[yY]) return 0 ;;
        *) return 1 ;;
    esac
}

# Check command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Get PHP version
get_php_version() {
    php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null
}

# Compare versions
version_gte() {
    [ "$(printf '%s\n' "$1" "$2" | sort -V | head -n1)" = "$2" ]
}

# Global PHP version (detected during check)
DETECTED_PHP_VERSION=""

# Arrays to track missing dependencies
MISSING_APT=()
MISSING_NPM=()
MISSING_NAMES=()

# Add missing dependency to tracking arrays
add_missing() {
    local name="$1"
    local apt_pkg="$2"
    local npm_pkg="$3"

    MISSING_NAMES+=("$name")
    if [ -n "$apt_pkg" ]; then
        MISSING_APT+=("$apt_pkg")
    fi
    if [ -n "$npm_pkg" ]; then
        MISSING_NPM+=("$npm_pkg")
    fi
}

# Interactive dependency installer
install_missing_deps() {
    if [ ${#MISSING_NAMES[@]} -eq 0 ]; then
        return 0
    fi

    echo ""
    step "Missing Dependencies"

    echo ""
    echo -e "${BOLD}The following dependencies are missing:${NC}"
    echo ""

    local i=1
    for name in "${MISSING_NAMES[@]}"; do
        echo "  $i) $name"
        ((i++))
    done
    echo ""
    echo "  A) Install ALL missing dependencies"
    echo "  S) Skip (exit installer)"
    echo ""

    if [ "$AUTO_MODE" = true ]; then
        info "Auto mode: Installing all missing dependencies..."
        selected_indices=($(seq 1 ${#MISSING_NAMES[@]}))
    else
        echo -n "Enter selection (e.g., 1,3,5 or A for all, S to skip): "
        read -r selection

        if [[ "$selection" =~ ^[Ss]$ ]]; then
            error "Installation cancelled. Please install dependencies manually."
            exit 1
        fi

        if [[ "$selection" =~ ^[Aa]$ ]]; then
            selected_indices=($(seq 1 ${#MISSING_NAMES[@]}))
        else
            IFS=',' read -ra selected_indices <<< "$selection"
        fi
    fi

    # Build install commands based on selection
    local apt_to_install=()
    local npm_to_install=()

    for idx in "${selected_indices[@]}"; do
        idx=$((idx - 1))  # Convert to 0-based
        if [ $idx -ge 0 ] && [ $idx -lt ${#MISSING_NAMES[@]} ]; then
            local name="${MISSING_NAMES[$idx]}"

            # Map names to packages (use detected PHP version)
            local php_ver="${DETECTED_PHP_VERSION:-8.1}"
            case "$name" in
                "PHP 8.1+")
                    apt_to_install+=("php${php_ver}" "php${php_ver}-cli")
                    ;;
                "PHP SQLite")
                    apt_to_install+=("php${php_ver}-sqlite3")
                    ;;
                "PHP mbstring")
                    apt_to_install+=("php${php_ver}-mbstring")
                    ;;
                "sqlite3")
                    apt_to_install+=("sqlite3")
                    ;;
                "Git")
                    apt_to_install+=("git")
                    ;;
                "Node.js")
                    apt_to_install+=("nodejs")
                    ;;
                "npm")
                    apt_to_install+=("npm")
                    ;;
                "tmux")
                    apt_to_install+=("tmux")
                    ;;
                "SSH")
                    apt_to_install+=("openssh-client")
                    ;;
                "Claude CLI")
                    npm_to_install+=("@anthropic-ai/claude-code")
                    ;;
                "Composer")
                    # Composer is handled separately
                    install_composer
                    ;;
            esac
        fi
    done

    # Run apt install if there are packages
    if [ ${#apt_to_install[@]} -gt 0 ]; then
        echo ""
        info "Installing system packages..."
        echo -e "${CYAN}sudo apt install -y ${apt_to_install[*]}${NC}"
        echo ""

        if sudo apt install -y "${apt_to_install[@]}"; then
            success "System packages installed"
        else
            error "Failed to install some packages"
            return 1
        fi
    fi

    # Run npm install if there are packages
    if [ ${#npm_to_install[@]} -gt 0 ]; then
        echo ""
        info "Installing npm packages globally..."
        echo -e "${CYAN}npm install -g ${npm_to_install[*]}${NC}"
        echo ""

        if npm install -g "${npm_to_install[@]}"; then
            success "npm packages installed"
        else
            error "Failed to install npm packages"
            return 1
        fi
    fi

    echo ""
    success "Dependencies installed! Re-checking requirements..."
    echo ""

    # Reset and re-check
    MISSING_APT=()
    MISSING_NPM=()
    MISSING_NAMES=()

    return 0
}

# Check system requirements
check_requirements() {
    step "Checking System Requirements"

    local has_errors=false

    # Reset missing arrays
    MISSING_APT=()
    MISSING_NPM=()
    MISSING_NAMES=()

    # Check PHP
    echo -n "Checking PHP... "
    if command_exists php; then
        DETECTED_PHP_VERSION=$(get_php_version)
        if version_gte "$DETECTED_PHP_VERSION" "8.1"; then
            success "PHP $DETECTED_PHP_VERSION found"
        else
            error "PHP $DETECTED_PHP_VERSION found, but 8.1+ required"
            add_missing "PHP 8.1+"
            has_errors=true
        fi
    else
        error "PHP not found"
        add_missing "PHP 8.1+"
        DETECTED_PHP_VERSION="8.1"  # Default for installation
        has_errors=true
    fi

    # Check PHP extensions
    if command_exists php; then
        echo -n "Checking PHP SQLite extension... "
        if php -m 2>/dev/null | grep -qi sqlite; then
            success "Available"
        else
            error "Not found"
            add_missing "PHP SQLite"
            has_errors=true
        fi

        echo -n "Checking PHP mbstring extension... "
        if php -m 2>/dev/null | grep -qi mbstring; then
            success "Available"
        else
            error "Not found"
            add_missing "PHP mbstring"
            has_errors=true
        fi
    fi

    # Check sqlite3 CLI (needed for password setup)
    echo -n "Checking sqlite3... "
    if command_exists sqlite3; then
        success "sqlite3 found"
    else
        error "Not found"
        add_missing "sqlite3"
        has_errors=true
    fi

    # Check Composer
    echo -n "Checking Composer... "
    if command_exists composer; then
        COMPOSER_VERSION=$(composer --version 2>/dev/null | grep -oP '\d+\.\d+\.\d+' | head -1)
        success "Composer $COMPOSER_VERSION found"
    else
        error "Not found"
        add_missing "Composer"
        has_errors=true
    fi

    # Check Git (required for version control)
    echo -n "Checking Git... "
    if command_exists git; then
        GIT_VERSION=$(git --version | grep -oP '\d+\.\d+\.\d+' | head -1)
        success "Git $GIT_VERSION found"
    else
        error "Not found"
        add_missing "Git"
        has_errors=true
    fi

    # Check Node.js/npm (required for Claude CLI)
    echo -n "Checking Node.js... "
    if command_exists node; then
        NODE_VERSION=$(node --version 2>/dev/null)
        success "Node.js $NODE_VERSION found"

        echo -n "Checking npm... "
        if command_exists npm; then
            NPM_VERSION=$(npm --version 2>/dev/null)
            success "npm $NPM_VERSION found"
        else
            error "Not found"
            add_missing "npm"
            has_errors=true
        fi
    else
        error "Not found"
        add_missing "Node.js"
        add_missing "npm"
        has_errors=true
    fi

    # Check tmux (required for Claude CLI background tasks)
    echo -n "Checking tmux... "
    if command_exists tmux; then
        TMUX_VERSION=$(tmux -V 2>/dev/null | grep -oP '[\d.]+' | head -1)
        success "tmux $TMUX_VERSION found"
    else
        error "Not found"
        add_missing "tmux"
        has_errors=true
    fi

    # Check SSH (recommended for remote development)
    echo -n "Checking SSH... "
    if command_exists ssh; then
        SSH_VERSION=$(ssh -V 2>&1 | grep -oP 'OpenSSH_[\d.]+' | head -1)
        success "$SSH_VERSION found"
    else
        warn "Not found (recommended for remote development)"
        add_missing "SSH"
        # Not required, so don't set has_errors
    fi

    # Check Claude CLI (required for AI-assisted development)
    echo -n "Checking Claude CLI... "
    if command_exists claude; then
        CLAUDE_VERSION=$(claude --version 2>/dev/null || echo "installed")
        success "Claude CLI found"
    else
        error "Not found"
        add_missing "Claude CLI"
        has_errors=true
    fi

    # Check for APCu (optional, for caching)
    echo -n "Checking PHP APCu extension... "
    if php -m 2>/dev/null | grep -qi apcu; then
        success "Available (enables 9.4x faster queries)"
    else
        info "Not found (optional, enables query caching)"
    fi

    echo ""

    # If there are required missing dependencies, must install them
    if [ "$has_errors" = true ]; then
        install_missing_deps

        # Re-run checks after installation
        check_requirements
        return $?
    fi

    # If only recommended deps are missing, offer to install (but don't block)
    if [ ${#MISSING_NAMES[@]} -gt 0 ]; then
        echo ""
        info "Some recommended dependencies are missing (installation will continue)"
        if [ "$AUTO_MODE" = false ]; then
            if ask_yn "Would you like to install recommended dependencies?"; then
                install_missing_deps
            fi
        fi
    fi

    success "All required dependencies satisfied!"
}

# Install Composer
install_composer() {
    info "Installing Composer..."

    EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

    if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then
        rm composer-setup.php
        error "Composer installer signature mismatch"
        exit 1
    fi

    php composer-setup.php --quiet
    rm composer-setup.php

    # Try to install globally
    if [ -w /usr/local/bin ]; then
        sudo mv composer.phar /usr/local/bin/composer
        success "Composer installed globally"
    else
        mv composer.phar "$SCRIPT_DIR/composer.phar"
        warn "Installed composer.phar locally (use: php composer.phar instead of composer)"
    fi
}

# Setup configuration
setup_config() {
    step "Setting Up Configuration"

    CONFIG_FILE="$SCRIPT_DIR/conf/config.ini"
    EXAMPLE_FILE="$SCRIPT_DIR/conf/config.sqlite.example.ini"

    if [ -f "$CONFIG_FILE" ]; then
        warn "Configuration file already exists at conf/config.ini"
        if ask_yn "Would you like to create a backup and generate a new one?" "n"; then
            BACKUP_FILE="$SCRIPT_DIR/conf/config.ini.backup.$(date +%Y%m%d_%H%M%S)"
            mv "$CONFIG_FILE" "$BACKUP_FILE"
            info "Backup created at: $BACKUP_FILE"
        else
            info "Keeping existing configuration"
            return 0
        fi
    fi

    if [ -f "$EXAMPLE_FILE" ]; then
        cp "$EXAMPLE_FILE" "$CONFIG_FILE"
        success "Configuration file created from SQLite template"

        # Update baseurl with the configured host and port
        sed -i "s|baseurl = \"http://localhost:8000\"|baseurl = \"http://${DEFAULT_HOST}:${DEFAULT_PORT}\"|" "$CONFIG_FILE"
        info "Base URL set to http://${DEFAULT_HOST}:${DEFAULT_PORT}"
    else
        error "SQLite example config not found at $EXAMPLE_FILE"
        exit 1
    fi
}

# Setup directories
setup_directories() {
    step "Setting Up Directories"

    # Create necessary directories
    local dirs=("log" "cache" "uploads" "database")

    for dir in "${dirs[@]}"; do
        if [ ! -d "$SCRIPT_DIR/$dir" ]; then
            mkdir -p "$SCRIPT_DIR/$dir"
            success "Created directory: $dir/"
        else
            info "Directory exists: $dir/"
        fi
    done

    # Set permissions
    info "Setting directory permissions..."

    chmod -R 755 "$SCRIPT_DIR" 2>/dev/null || true
    chmod -R 777 "$SCRIPT_DIR/log" 2>/dev/null || warn "Could not set log/ permissions"
    chmod -R 777 "$SCRIPT_DIR/cache" 2>/dev/null || warn "Could not set cache/ permissions"
    chmod -R 777 "$SCRIPT_DIR/uploads" 2>/dev/null || warn "Could not set uploads/ permissions"
    chmod -R 777 "$SCRIPT_DIR/database" 2>/dev/null || warn "Could not set database/ permissions"

    success "Directory permissions set"
}

# Setup SSH key for remote Claude development
setup_ssh_key() {
    step "SSH Key Setup (for Remote Development)"

    local SSH_DIR="$HOME/.ssh"
    local KEY_NAME="tiknix_ecdsa"
    local KEY_PATH="$SSH_DIR/$KEY_NAME"

    # Check if ssh-keygen exists
    if ! command_exists ssh-keygen; then
        warn "ssh-keygen not found, skipping SSH key setup"
        info "Install with: sudo apt install openssh-client"
        return 0
    fi

    # Create .ssh directory if it doesn't exist
    if [ ! -d "$SSH_DIR" ]; then
        mkdir -p "$SSH_DIR"
        chmod 700 "$SSH_DIR"
        info "Created $SSH_DIR directory"
    fi

    # Check if key already exists
    if [ -f "$KEY_PATH" ]; then
        info "SSH key already exists at $KEY_PATH"
        echo ""
        echo -e "${BOLD}Your public key (for remote servers):${NC}"
        echo ""
        cat "${KEY_PATH}.pub"
        echo ""
        success "SSH key ready for use"
        return 0
    fi

    echo ""
    info "An SSH key is needed for remote Claude development (SSH+TMUX+CLAUDE)"
    echo ""

    if [ "$AUTO_MODE" = true ]; then
        generate_key=true
    else
        if ask_yn "Generate a new ECDSA SSH key for Tiknix?"; then
            generate_key=true
        else
            info "Skipping SSH key generation"
            return 0
        fi
    fi

    if [ "$generate_key" = true ]; then
        echo ""
        info "Generating ECDSA SSH key..."

        # Generate ECDSA key (more secure than RSA, faster than Ed25519 on some systems)
        ssh-keygen -t ecdsa -b 521 -f "$KEY_PATH" -N "" -C "tiknix@$(hostname)"

        if [ $? -eq 0 ]; then
            chmod 600 "$KEY_PATH"
            chmod 644 "${KEY_PATH}.pub"
            success "SSH key generated at $KEY_PATH"

            echo ""
            echo -e "${BOLD}Your public key (add this to remote servers' ~/.ssh/authorized_keys):${NC}"
            echo ""
            echo -e "${CYAN}"
            cat "${KEY_PATH}.pub"
            echo -e "${NC}"
            echo ""

            # Add to SSH config for easy use
            local SSH_CONFIG="$SSH_DIR/config"
            if [ ! -f "$SSH_CONFIG" ] || ! grep -q "# Tiknix Remote Development" "$SSH_CONFIG" 2>/dev/null; then
                echo "" >> "$SSH_CONFIG"
                echo "# Tiknix Remote Development" >> "$SSH_CONFIG"
                echo "# Add your remote hosts below:" >> "$SSH_CONFIG"
                echo "# Host tiknix-remote" >> "$SSH_CONFIG"
                echo "#     HostName your-server.com" >> "$SSH_CONFIG"
                echo "#     User your-username" >> "$SSH_CONFIG"
                echo "#     IdentityFile $KEY_PATH" >> "$SSH_CONFIG"
                echo "" >> "$SSH_CONFIG"
                info "Added template to $SSH_CONFIG"
            fi

            # Offer to start ssh-agent and add key
            if [ "$AUTO_MODE" = false ]; then
                if ask_yn "Add key to ssh-agent for this session?"; then
                    eval "$(ssh-agent -s)" > /dev/null 2>&1
                    ssh-add "$KEY_PATH" 2>/dev/null
                    success "Key added to ssh-agent"
                fi
            fi

            echo ""
            info "To use on remote servers:"
            echo "  1. Copy the public key above to remote server's ~/.ssh/authorized_keys"
            echo "  2. Or use: ssh-copy-id -i $KEY_PATH user@remote-host"
            echo "  3. Then connect: ssh -i $KEY_PATH user@remote-host"
            echo ""
        else
            error "Failed to generate SSH key"
            return 1
        fi
    fi
}

# Setup Claude CLI authentication
setup_claude_auth() {
    step "Claude CLI Authentication"

    # Check if claude is installed
    if ! command_exists claude; then
        warn "Claude CLI not installed, skipping authentication"
        info "Install Claude CLI and run this installer again, or run 'claude' manually"
        return 0
    fi

    # Test if Claude is already authenticated by running a simple command
    info "Checking Claude CLI authentication status..."

    # Try to run a simple prompt - if it works, we're authenticated
    # Use timeout to prevent hanging, redirect to capture output
    local test_result
    test_result=$(timeout 15 claude -p "respond with only the word: authenticated" 2>&1) || true

    if echo "$test_result" | grep -qi "authenticated"; then
        success "Claude CLI is already authenticated"
        return 0
    fi

    # Check if the error indicates we need to authenticate
    if echo "$test_result" | grep -qi -E "(authorize|login|auth|OAuth|sign in|API key|/login)"; then
        echo ""
        warn "Claude CLI needs to be authenticated"
        echo ""

        if [ "$AUTO_MODE" = true ]; then
            warn "Cannot authenticate Claude in auto mode - requires user interaction"
            info "Run 'claude' manually after installation to authenticate"
            return 0
        fi

        if ask_yn "Authenticate Claude CLI now?" "y"; then
            echo ""
            echo -e "${BOLD}${CYAN}════════════════════════════════════════════════════════════${NC}"
            echo -e "${BOLD}  Claude CLI Authentication${NC}"
            echo -e "${BOLD}${CYAN}════════════════════════════════════════════════════════════${NC}"
            echo ""
            echo -e "  Claude CLI will now start in interactive mode."
            echo ""
            echo -e "  ${BOLD}Instructions:${NC}"
            echo -e "  1. Type ${YELLOW}/login${NC} and press Enter"
            echo -e "  2. A browser will open (or you'll get a URL to copy)"
            echo -e "  3. Complete the OAuth authorization in your browser"
            echo -e "  4. Once done, type ${YELLOW}/exit${NC} to return to the installer"
            echo ""
            echo -e "${BOLD}${CYAN}════════════════════════════════════════════════════════════${NC}"
            echo ""

            read -r -p "Press Enter to start Claude CLI..."

            # Run claude interactively so user can type /login
            claude

            echo ""

            # Verify authentication worked
            info "Verifying authentication..."
            local verify_result
            verify_result=$(timeout 15 claude -p "respond with only: success" 2>&1) || true

            if echo "$verify_result" | grep -qi "success"; then
                echo ""
                success "Claude CLI authenticated successfully!"
            else
                echo ""
                warn "Claude authentication may not have completed"
                info "You can run 'claude' manually later to complete authentication"
            fi
        else
            warn "Skipping Claude authentication"
            info "Run 'claude' manually when ready to authenticate"
        fi
    else
        # Some other error or it might already be working
        warn "Could not determine Claude auth status"
        info "Run 'claude' manually to verify or authenticate"
    fi
}

# Install dependencies
install_dependencies() {
    step "Installing PHP Dependencies"

    cd "$SCRIPT_DIR"

    if [ -f "composer.phar" ]; then
        php composer.phar install --no-interaction --prefer-dist
    else
        composer install --no-interaction --prefer-dist
    fi

    success "PHP dependencies installed"
}

# Prompt for admin password
prompt_admin_password() {
    if [ "$AUTO_MODE" = true ]; then
        # In auto mode, generate a random password
        ADMIN_PASSWORD=$(php -r "echo bin2hex(random_bytes(8));")
        info "Generated random admin password: $ADMIN_PASSWORD"
        echo ""
        warn "SAVE THIS PASSWORD - it will not be shown again!"
        echo ""
        return 0
    fi

    echo ""
    info "Set up your admin account password"
    echo ""

    while true; do
        # Read password (hidden input)
        read -r -s -p "Enter admin password (min 6 chars): " ADMIN_PASSWORD
        echo ""

        if [ ${#ADMIN_PASSWORD} -lt 6 ]; then
            error "Password must be at least 6 characters"
            continue
        fi

        read -r -s -p "Confirm admin password: " ADMIN_PASSWORD_CONFIRM
        echo ""

        if [ "$ADMIN_PASSWORD" != "$ADMIN_PASSWORD_CONFIRM" ]; then
            error "Passwords do not match. Please try again."
            continue
        fi

        break
    done

    success "Admin password set"
}

# Set admin password in database
set_admin_password() {
    if [ -z "$ADMIN_PASSWORD" ]; then
        return 0
    fi

    info "Updating admin password in database..."

    # Generate password hash using PHP
    PASSWORD_HASH=$(php -r "echo password_hash('$ADMIN_PASSWORD', PASSWORD_DEFAULT);")

    # Update the database
    sqlite3 "$SCRIPT_DIR/database/tiknix.db" "UPDATE member SET password = '$PASSWORD_HASH' WHERE username = 'admin';"

    if [ $? -eq 0 ]; then
        success "Admin password updated"
    else
        error "Failed to update admin password"
    fi
}

# Setup MCP connection for Claude Code
setup_mcp_connection() {
    step "Setting up MCP Connection for Claude Code"

    # Check if Claude CLI is available
    if ! command_exists claude; then
        warn "Claude CLI not installed, skipping MCP setup"
        info "Install Claude CLI and run: claude mcp add tiknix <url> --header 'Authorization: Bearer <token>'"
        return 0
    fi

    # Get the base URL from config or use default
    local base_url="http://localhost:8000"
    if [ -f "$SCRIPT_DIR/conf/config.ini" ]; then
        local config_url=$(grep -E "^baseurl\s*=" "$SCRIPT_DIR/conf/config.ini" | sed 's/.*=\s*"\?\([^"]*\)"\?/\1/' | tr -d ' ')
        if [ -n "$config_url" ]; then
            base_url="$config_url"
        fi
    fi
    local mcp_url="${base_url}/mcp/message"

    echo ""
    info "MCP Server URL: $mcp_url"

    # Generate API token
    local api_token="tk_$(php -r "echo bin2hex(random_bytes(32));")"

    # Get admin member ID (should be 1)
    local admin_id=$(sqlite3 "$SCRIPT_DIR/database/tiknix.db" "SELECT id FROM member WHERE username = 'admin' LIMIT 1;")

    if [ -z "$admin_id" ]; then
        error "Could not find admin user in database"
        return 1
    fi

    # Check if API key already exists for admin
    local existing_key=$(sqlite3 "$SCRIPT_DIR/database/tiknix.db" "SELECT id FROM apikey WHERE member_id = $admin_id AND name = 'Tiknix Installer' LIMIT 1;")

    if [ -n "$existing_key" ]; then
        info "API key already exists, regenerating token..."
        sqlite3 "$SCRIPT_DIR/database/tiknix.db" "UPDATE apikey SET token = '$api_token', updated_at = datetime('now') WHERE id = $existing_key;"
    else
        # Insert new API key
        info "Creating API key for admin user..."
        sqlite3 "$SCRIPT_DIR/database/tiknix.db" "INSERT INTO apikey (member_id, name, token, scopes, allowed_servers, is_active, created_at) VALUES ($admin_id, 'Tiknix Installer', '$api_token', '[\"mcp:*\"]', '[]', 1, datetime('now'));"
    fi

    if [ $? -ne 0 ]; then
        error "Failed to create API key"
        return 1
    fi

    success "API key created for MCP access"

    # Store the token for later display
    TIKNIX_API_TOKEN="$api_token"
    TIKNIX_MCP_URL="$mcp_url"

    # Configure Claude Code MCP server
    echo ""
    info "Configuring Claude Code MCP server..."

    # Create Claude settings directory if needed
    local claude_settings_dir="$HOME/.claude"
    local claude_settings_file="$claude_settings_dir/settings.json"

    mkdir -p "$claude_settings_dir"

    # Check if settings.json exists
    if [ -f "$claude_settings_file" ]; then
        # Backup existing settings
        cp "$claude_settings_file" "$claude_settings_file.backup.$(date +%Y%m%d_%H%M%S)"
        info "Backed up existing Claude settings"

        # Check if we can use jq for JSON manipulation
        if command_exists jq; then
            # Use jq to add/update the tiknix MCP server
            local temp_file=$(mktemp)
            jq --arg url "$mcp_url" --arg token "$api_token" '
                .mcpServers.tiknix = {
                    "type": "http",
                    "url": $url,
                    "headers": {
                        "Authorization": ("Bearer " + $token)
                    }
                }
            ' "$claude_settings_file" > "$temp_file" 2>/dev/null

            if [ $? -eq 0 ]; then
                mv "$temp_file" "$claude_settings_file"
                success "Updated Claude settings.json with Tiknix MCP server"
            else
                rm -f "$temp_file"
                warn "Could not update settings.json automatically"
                info "Using claude mcp add command instead..."
                use_claude_mcp_add=true
            fi
        else
            # No jq, try to use claude mcp add
            use_claude_mcp_add=true
        fi
    else
        # Create new settings.json
        cat > "$claude_settings_file" << SETTINGS_EOF
{
  "mcpServers": {
    "tiknix": {
      "type": "http",
      "url": "$mcp_url",
      "headers": {
        "Authorization": "Bearer $api_token"
      }
    }
  }
}
SETTINGS_EOF
        success "Created Claude settings.json with Tiknix MCP server"
    fi

    # If we need to use claude mcp add command
    if [ "$use_claude_mcp_add" = true ]; then
        info "Adding MCP server via Claude CLI..."
        if claude mcp add --transport http tiknix "$mcp_url" --header "Authorization: Bearer $api_token" 2>/dev/null; then
            success "Added Tiknix MCP server to Claude Code"
        else
            warn "Could not add MCP server automatically"
            echo ""
            echo -e "${BOLD}Manual setup required:${NC}"
            echo "Run this command to add the MCP server:"
            echo ""
            echo -e "${CYAN}claude mcp add --transport http tiknix \"$mcp_url\" --header \"Authorization: Bearer $api_token\"${NC}"
            echo ""
        fi
    fi

    echo ""
    echo -e "${BOLD}${GREEN}MCP Connection Details:${NC}"
    echo -e "  URL:   ${CYAN}$mcp_url${NC}"
    echo -e "  Token: ${CYAN}${api_token:0:20}...${NC}"
    echo ""
    info "Restart Claude Code to load the MCP tools"
}

# Initialize database
init_database() {
    step "Initializing Database"

    cd "$SCRIPT_DIR"

    local fresh_install=true

    if [ -f "database/tiknix.db" ]; then
        warn "Database file already exists"
        if ask_yn "Would you like to reset it? (All data will be lost)" "n"; then
            rm "database/tiknix.db"
            info "Database removed, will create fresh"
        else
            info "Keeping existing database"
            fresh_install=false
        fi
    fi

    if [ "$fresh_install" = true ]; then
        # Create database directory if needed
        mkdir -p "$SCRIPT_DIR/database"

        # Check if schema file exists
        if [ ! -f "$SCRIPT_DIR/sql/schema.sql" ]; then
            error "Schema file not found at sql/schema.sql"
            return 1
        fi

        info "Creating database from schema..."

        # Use sqlite3 directly to import schema (more reliable than PHP)
        if sqlite3 "$SCRIPT_DIR/database/tiknix.db" < "$SCRIPT_DIR/sql/schema.sql" 2>&1; then
            success "Database schema loaded"
        else
            error "Failed to load database schema"
            return 1
        fi

        # Verify tables were created
        local table_count=$(sqlite3 "$SCRIPT_DIR/database/tiknix.db" "SELECT count(*) FROM sqlite_master WHERE type='table';")
        if [ "$table_count" -gt 0 ]; then
            success "Database initialized ($table_count tables created)"
        else
            error "No tables were created"
            return 1
        fi

        # Prompt for admin password on fresh install
        prompt_admin_password
        set_admin_password
    fi
}

# Create server.php router
create_server_router() {
    step "Creating Development Server Router"

    cat > "$SCRIPT_DIR/server.php" << 'ROUTER'
<?php
/**
 * Tiknix Development Server Router
 * ================================
 *
 * This file enables the PHP built-in server to work with Tiknix's
 * routing system. It handles static files and routes all other
 * requests through the framework.
 *
 * Usage:
 *   php -S localhost:8000 server.php
 *
 * Or use the serve.sh script:
 *   ./serve.sh
 *   ./serve.sh --port=8080
 *   ./serve.sh --host=0.0.0.0 --port=8000
 */

// Get the requested URI
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Define static file extensions
$staticExtensions = [
    'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'ico', 'svg', 'webp',
    'woff', 'woff2', 'ttf', 'eot', 'otf',
    'pdf', 'zip', 'tar', 'gz',
    'mp3', 'mp4', 'webm', 'ogg',
    'json', 'xml', 'txt', 'map'
];

// Check if this is a static file in public/
$publicPath = __DIR__ . '/public' . $uri;
$rootPath = __DIR__ . $uri;

// Get file extension
$extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

// Serve static files
if (in_array($extension, $staticExtensions)) {
    // Check public directory first
    if (file_exists($publicPath) && is_file($publicPath)) {
        return serveStatic($publicPath, $extension);
    }
    // Then check root directory
    if (file_exists($rootPath) && is_file($rootPath)) {
        return serveStatic($rootPath, $extension);
    }
}

// Check for actual files in public directory (like favicon.ico, robots.txt)
if ($uri !== '/' && file_exists($publicPath) && is_file($publicPath)) {
    return serveStatic($publicPath, $extension);
}

// Route everything else through the framework
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

// Include the main entry point
require_once __DIR__ . '/public/index.php';

/**
 * Serve a static file with proper MIME type
 */
function serveStatic($filepath, $extension) {
    $mimeTypes = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'json'  => 'application/json',
        'xml'   => 'application/xml',
        'txt'   => 'text/plain',
        'html'  => 'text/html',
        'htm'   => 'text/html',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'ico'   => 'image/x-icon',
        'svg'   => 'image/svg+xml',
        'webp'  => 'image/webp',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'eot'   => 'application/vnd.ms-fontobject',
        'otf'   => 'font/otf',
        'pdf'   => 'application/pdf',
        'zip'   => 'application/zip',
        'mp3'   => 'audio/mpeg',
        'mp4'   => 'video/mp4',
        'webm'  => 'video/webm',
        'ogg'   => 'audio/ogg',
        'map'   => 'application/json',
    ];

    $mime = $mimeTypes[$extension] ?? 'application/octet-stream';

    header("Content-Type: $mime");
    header("Content-Length: " . filesize($filepath));

    // Cache static files for development (1 hour)
    header("Cache-Control: public, max-age=3600");

    readfile($filepath);
    return false; // PHP built-in server will not process further
}
ROUTER

    success "Created server.php router"

    # Create convenience serve script
    cat > "$SCRIPT_DIR/serve.sh" << 'SERVE'
#!/bin/bash
#
# Tiknix Development Server
# Run: ./serve.sh [OPTIONS]
#
# Options:
#   --port=PORT   Set server port (default: 8000)
#   --host=HOST   Set server host (default: localhost)
#   --open        Open browser after starting
#

HOST="localhost"
PORT="8000"
OPEN_BROWSER=false

for arg in "$@"; do
    case $arg in
        --port=*)
            PORT="${arg#*=}"
            ;;
        --host=*)
            HOST="${arg#*=}"
            ;;
        --open)
            OPEN_BROWSER=true
            ;;
        -h|--help)
            echo "Tiknix Development Server"
            echo ""
            echo "Usage: ./serve.sh [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --port=PORT   Server port (default: 8000)"
            echo "  --host=HOST   Server host (default: localhost)"
            echo "  --open        Open browser after starting"
            exit 0
            ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Function to update MCP configuration when port changes
update_mcp_config() {
    local new_url="http://${HOST}:${PORT}/mcp/message"
    local claude_settings="$HOME/.claude/settings.json"

    # Update config.ini baseurl first
    local config_file="$SCRIPT_DIR/conf/config.ini"
    if [ -f "$config_file" ]; then
        local new_baseurl="http://${HOST}:${PORT}"
        sed -i.tmp "s|^baseurl[[:space:]]*=.*|baseurl = \"$new_baseurl\"|" "$config_file" 2>/dev/null
        rm -f "$config_file.tmp"
    fi

    # Check if claude CLI is available
    if ! command -v claude &>/dev/null; then
        return 0
    fi

    # Check if tiknix MCP server exists
    if ! claude mcp get tiknix &>/dev/null 2>&1; then
        return 0
    fi

    # Get current URL from claude mcp get
    local current_url=$(claude mcp get tiknix 2>/dev/null | grep -o 'http[s]*://[^"]*' | head -1)

    if [ "$current_url" != "$new_url" ] && [ -n "$current_url" ]; then
        echo "  Updating MCP URL: $new_url"

        # Get the API token from settings.json
        local api_token=""
        if [ -f "$claude_settings" ]; then
            api_token=$(grep -o 'Bearer [^"]*' "$claude_settings" 2>/dev/null | head -1 | sed 's/Bearer //')
        fi

        if [ -n "$api_token" ]; then
            # Remove and re-add with new URL
            claude mcp remove tiknix 2>/dev/null
            if claude mcp add --transport http tiknix "$new_url" --header "Authorization: Bearer $api_token" 2>/dev/null; then
                echo "  ✓ MCP server updated (restart Claude to apply)"
            else
                echo "  ⚠ Could not update MCP server automatically"
            fi
        else
            echo "  ⚠ Could not find API token to update MCP server"
            echo "  Run: claude mcp remove tiknix && claude mcp add --transport http tiknix \"$new_url\" --header \"Authorization: Bearer YOUR_TOKEN\""
        fi
    fi
}

# Update MCP config before starting server
update_mcp_config

echo ""
echo "  Tiknix Development Server"
echo "  ========================="
echo ""
echo "  URL: http://${HOST}:${PORT}"
echo "  MCP: http://${HOST}:${PORT}/mcp/message"
echo ""
echo "  Press Ctrl+C to stop"
echo ""

# Open browser if requested
if [ "$OPEN_BROWSER" = true ]; then
    sleep 1 && (xdg-open "http://${HOST}:${PORT}" 2>/dev/null || open "http://${HOST}:${PORT}" 2>/dev/null) &
fi

php -S "${HOST}:${PORT}" -t "${SCRIPT_DIR}" "${SCRIPT_DIR}/server.php"
SERVE

    chmod +x "$SCRIPT_DIR/serve.sh"
    success "Created serve.sh convenience script"
}

# Print completion message
print_completion() {
    step "Installation Complete!"

    echo ""
    echo -e "${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║                  Installation Successful!                   ║${NC}"
    echo -e "${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
    echo ""
    echo -e "${BOLD}Quick Start:${NC}"
    echo ""
    echo "  1. Start the development server:"
    echo -e "     ${CYAN}./serve.sh${NC}"
    echo ""
    echo "  2. Open your browser:"
    echo -e "     ${CYAN}http://${DEFAULT_HOST}:${DEFAULT_PORT}${NC}"
    echo ""
    echo "  3. Or with custom host/port:"
    echo -e "     ${CYAN}./serve.sh --host=0.0.0.0 --port=8080${NC}"
    echo "     Then open: http://0.0.0.0:8080"
    echo ""
    echo -e "${BOLD}Admin Login:${NC}"
    echo "  Username: admin"
    if [ -n "$ADMIN_PASSWORD" ]; then
        if [ "$AUTO_MODE" = true ]; then
            echo "  Password: $ADMIN_PASSWORD"
            echo -e "  ${YELLOW}** SAVE THIS PASSWORD - generated randomly **${NC}"
        else
            echo "  Password: (the password you just set)"
            echo -e "  ${GREEN}✓ Custom password configured${NC}"
        fi
    else
        echo "  Password: (existing password)"
    fi
    echo ""
    echo -e "${BOLD}Key URLs:${NC}"
    echo "  Home:        http://${DEFAULT_HOST}:${DEFAULT_PORT}/"
    echo "  Dashboard:   http://${DEFAULT_HOST}:${DEFAULT_PORT}/dashboard"
    echo "  Admin:       http://${DEFAULT_HOST}:${DEFAULT_PORT}/admin"
    echo "  MCP Server:  http://${DEFAULT_HOST}:${DEFAULT_PORT}/mcp"
    echo ""
    echo -e "${BOLD}AI-Assisted Development:${NC}"
    if command_exists claude; then
        echo -e "  ${GREEN}Claude CLI is installed!${NC}"
        echo "  Run 'claude' in this directory to start coding with AI"
    else
        echo "  Install Claude CLI for AI-assisted development:"
        echo -e "     ${CYAN}npm install -g @anthropic-ai/claude-code${NC}"
    fi
    echo ""
    echo -e "${BOLD}MCP Tools Available:${NC}"
    if [ -n "$TIKNIX_API_TOKEN" ]; then
        echo -e "  ${GREEN}✓ MCP server configured for Claude Code${NC}"
        echo "  Restart Claude to load tools:"
        echo "    tiknix:hello, tiknix:validate_php, tiknix:security_scan"
        echo "    tiknix:list_tasks, tiknix:get_task, tiknix:update_task"
        echo "  Verify with: claude mcp list"
    else
        echo "  Configure MCP at: http://${DEFAULT_HOST}:${DEFAULT_PORT}/apikeys"
    fi
    echo ""
    echo -e "${BOLD}Remote Development (SSH+TMUX+CLAUDE):${NC}"
    local SSH_KEY="$HOME/.ssh/tiknix_ecdsa"
    if [ -f "$SSH_KEY" ]; then
        echo -e "  ${GREEN}SSH key ready:${NC} $SSH_KEY"
        echo "  Copy to remote: ssh-copy-id -i $SSH_KEY user@remote-host"
        echo "  Connect: ssh -i $SSH_KEY user@remote-host"
    else
        echo "  Run installer again to generate SSH key"
    fi
    echo ""
    echo -e "${BOLD}Documentation:${NC}"
    echo "  README.md          - Getting started guide"
    echo "  FLIGHTPHP_README.md - FlightPHP patterns"
    echo "  REDBEAN_README.md  - Database patterns"
    echo ""
    echo -e "${BOLD}Production Deployment:${NC}"
    echo "  1. Set environment = \"production\" in conf/config.ini"
    echo "  2. Set debug = false"
    echo "  3. Set build_mode = false"
    echo "  4. Enable CSRF protection"
    echo ""
}

# Main installation flow
main() {
    print_banner

    echo -e "${BOLD}Welcome to the Tiknix Framework Installer!${NC}"
    echo ""
    echo "This script will:"
    echo "  1. Check system requirements"
    echo "  2. Install PHP dependencies (Composer)"
    echo "  3. Set up configuration files"
    echo "  4. Initialize the SQLite database"
    echo "  5. Set up SSH key for remote development"
    echo "  6. Authenticate Claude CLI (if needed)"
    echo "  7. Configure MCP connection for Claude Code"
    echo "  8. Create a development server script"
    echo ""

    if [ "$AUTO_MODE" = false ]; then
        if ! ask_yn "Continue with installation?"; then
            echo "Installation cancelled."
            exit 0
        fi
    fi

    check_requirements
    setup_config
    setup_directories
    install_dependencies
    init_database
    setup_ssh_key
    setup_claude_auth
    setup_mcp_connection
    create_server_router
    print_completion
}

# Run main function
main
