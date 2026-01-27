#!/bin/bash
#
# Laravel Continuous Delivery - Bootstrap Script
#
# This script automates the initial deployment setup for a Laravel application
# using the advanced deployment strategy.
#
# Usage:
#   sudo ./bootstrap.sh
#
# Or with custom variables:
#   REPO_URL=git@github.com:org/repo.git APP_PATH=/var/www/myapp sudo -E ./bootstrap.sh
#

set -euo pipefail

#=============================================================================
# CONFIGURATION - Modify these variables for your environment
#=============================================================================

# Repository URL (SSH or HTTPS)
REPO_URL="${REPO_URL:-git@github.com:your-org/your-repo.git}"

# Application base path (will contain releases/, shared/, current)
APP_PATH="${APP_PATH:-/var/www/my-app}"

# Web server user
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"

# CD database path (isolated SQLite for deployment records)
CD_DATABASE_PATH="${CD_DATABASE_PATH:-/var/lib/sage-grids-cd}"

# PHP binary path (useful when multiple PHP versions installed)
PHP_BIN="${PHP_BIN:-php}"

# PHP version for FPM socket (used in nginx config suggestion)
PHP_VERSION="${PHP_VERSION:-8.2}"

# App configuration key (matches config/continuous-delivery.php)
APP_KEY="${APP_KEY:-default}"

# Git branch to clone
GIT_BRANCH="${GIT_BRANCH:-main}"

# Whether to run npm build (set to "no" to skip)
RUN_NPM_BUILD="${RUN_NPM_BUILD:-yes}"

# Whether to run migrations (set to "no" to skip)
RUN_MIGRATIONS="${RUN_MIGRATIONS:-yes}"

#=============================================================================
# COLORS AND HELPERS
#=============================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

success() {
    echo -e "${GREEN}[OK]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
    exit 1
}

run_as_web_user() {
    sudo -u "$WEB_USER" "$@"
}

#=============================================================================
# PRE-FLIGHT CHECKS
#=============================================================================

info "Running pre-flight checks..."

# Check if running as root or with sudo
if [[ $EUID -ne 0 ]]; then
    error "This script must be run as root or with sudo"
fi

# Check required commands
for cmd in git composer; do
    if ! command -v "$cmd" &> /dev/null; then
        error "Required command not found: $cmd"
    fi
done

# Check PHP binary
if ! command -v "$PHP_BIN" &> /dev/null; then
    error "PHP binary not found: $PHP_BIN"
fi

# Check PHP version
PHP_CURRENT=$("$PHP_BIN" -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
if [[ $(echo "$PHP_CURRENT < 8.2" | bc -l) -eq 1 ]]; then
    error "PHP 8.2 or higher required. Found: $PHP_CURRENT (binary: $PHP_BIN)"
fi

success "Pre-flight checks passed"

#=============================================================================
# DISPLAY CONFIGURATION
#=============================================================================

echo ""
echo "============================================"
echo "  Laravel CD Bootstrap Configuration"
echo "============================================"
echo ""
echo "  Repository:     $REPO_URL"
echo "  App Path:       $APP_PATH"
echo "  Web User:       $WEB_USER:$WEB_GROUP"
echo "  CD Database:    $CD_DATABASE_PATH"
echo "  PHP Binary:     $PHP_BIN"
echo "  Git Branch:     $GIT_BRANCH"
echo "  NPM Build:      $RUN_NPM_BUILD"
echo "  Run Migrations: $RUN_MIGRATIONS"
echo ""
echo "============================================"
echo ""

read -p "Continue with these settings? [y/N] " -n 1 -r
echo ""
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    info "Aborted by user"
    exit 0
fi

#=============================================================================
# STEP 1: CREATE DIRECTORIES
#=============================================================================

echo ""
info "Step 1: Creating directories..."

# Create application base directory
if [[ ! -d "$APP_PATH" ]]; then
    mkdir -p "$APP_PATH"
    success "Created: $APP_PATH"
else
    warn "Already exists: $APP_PATH"
fi

# Create CD database directory
if [[ ! -d "$CD_DATABASE_PATH" ]]; then
    mkdir -p "$CD_DATABASE_PATH"
    chown "$WEB_USER:$WEB_GROUP" "$CD_DATABASE_PATH"
    chmod 755 "$CD_DATABASE_PATH"
    success "Created: $CD_DATABASE_PATH"
else
    warn "Already exists: $CD_DATABASE_PATH"
fi

# Set ownership of app directory
chown -R "$WEB_USER:$WEB_GROUP" "$APP_PATH"
success "Set ownership: $APP_PATH -> $WEB_USER:$WEB_GROUP"

#=============================================================================
# STEP 2: CLONE REPOSITORY
#=============================================================================

echo ""
info "Step 2: Cloning repository..."

# Generate release name
RELEASE_NAME=$(date +%Y%m%d%H%M%S)
RELEASES_PATH="$APP_PATH/releases"
RELEASE_PATH="$RELEASES_PATH/$RELEASE_NAME"

# Create releases directory
run_as_web_user mkdir -p "$RELEASES_PATH"

# Clone repository
info "Cloning into: $RELEASE_PATH"
run_as_web_user git clone --branch "$GIT_BRANCH" --single-branch "$REPO_URL" "$RELEASE_PATH"
success "Repository cloned"

#=============================================================================
# STEP 3: INSTALL DEPENDENCIES
#=============================================================================

echo ""
info "Step 3: Installing Composer dependencies..."

cd "$RELEASE_PATH"
run_as_web_user composer install --no-dev --optimize-autoloader --no-interaction
success "Composer dependencies installed"

#=============================================================================
# STEP 4: CREATE TEMPORARY .env FOR SETUP
#=============================================================================

echo ""
info "Step 4: Creating temporary .env..."

if [[ -f "$RELEASE_PATH/.env.example" ]]; then
    run_as_web_user cp "$RELEASE_PATH/.env.example" "$RELEASE_PATH/.env"
    run_as_web_user "$PHP_BIN" "$RELEASE_PATH/artisan" key:generate
    success "Temporary .env created with app key"
else
    warn "No .env.example found, creating minimal .env"
    run_as_web_user bash -c "echo 'APP_KEY=' > '$RELEASE_PATH/.env'"
    run_as_web_user "$PHP_BIN" "$RELEASE_PATH/artisan" key:generate
fi

#=============================================================================
# STEP 5: RUN DEPLOYER SETUP
#=============================================================================

echo ""
info "Step 5: Running deployer:setup..."

run_as_web_user "$PHP_BIN" "$RELEASE_PATH/artisan" deployer:setup "$APP_KEY" \
    --strategy=advanced \
    --release="$RELEASE_NAME"

success "Deployer setup complete"

#=============================================================================
# STEP 6: CONFIGURE SHARED .env
#=============================================================================

echo ""
info "Step 6: Configuring shared .env..."

SHARED_ENV="$APP_PATH/shared/.env"

if [[ -f "$SHARED_ENV" ]]; then
    # Add CD-specific config if not present
    if ! grep -q "CD_DATABASE_PATH" "$SHARED_ENV"; then
        run_as_web_user bash -c "cat >> '$SHARED_ENV'" << EOF

# Continuous Delivery Configuration
CD_DATABASE_PATH=$CD_DATABASE_PATH/deployments.sqlite
EOF
        success "Added CD config to shared/.env"
    fi

    warn "Please review and update: $SHARED_ENV"
    warn "Ensure database credentials and other settings are correct!"
else
    error "shared/.env not found. Setup may have failed."
fi

#=============================================================================
# STEP 7: LINK SHARED RESOURCES TO RELEASE
#=============================================================================

echo ""
info "Step 7: Linking shared resources..."

cd "$RELEASE_PATH"

# Remove default storage and link to shared
if [[ -d "$RELEASE_PATH/storage" && ! -L "$RELEASE_PATH/storage" ]]; then
    rm -rf "$RELEASE_PATH/storage"
fi

if [[ ! -L "$RELEASE_PATH/storage" ]]; then
    run_as_web_user ln -s "$APP_PATH/shared/storage" "$RELEASE_PATH/storage"
    success "Linked: storage -> shared/storage"
else
    warn "Already linked: storage"
fi

# Remove .env and link to shared
if [[ -f "$RELEASE_PATH/.env" && ! -L "$RELEASE_PATH/.env" ]]; then
    rm "$RELEASE_PATH/.env"
fi

if [[ ! -L "$RELEASE_PATH/.env" ]]; then
    run_as_web_user ln -s "$APP_PATH/shared/.env" "$RELEASE_PATH/.env"
    success "Linked: .env -> shared/.env"
else
    warn "Already linked: .env"
fi

#=============================================================================
# STEP 8: RUN MIGRATIONS (OPTIONAL)
#=============================================================================

if [[ "$RUN_MIGRATIONS" == "yes" ]]; then
    echo ""
    info "Step 8: Running migrations..."

    # Check if database is configured
    if run_as_web_user "$PHP_BIN" "$RELEASE_PATH/artisan" db:show &>/dev/null; then
        run_as_web_user "$PHP_BIN" "$RELEASE_PATH/artisan" migrate --force
        success "Application migrations complete"
    else
        warn "Database not configured, skipping application migrations"
    fi

    # Run CD migrations
    run_as_web_user "$PHP_BIN" "$RELEASE_PATH/artisan" deployer:migrate
    success "Deployer migrations complete"
else
    warn "Skipping migrations (RUN_MIGRATIONS=no)"
fi

#=============================================================================
# STEP 9: BUILD ASSETS (OPTIONAL)
#=============================================================================

if [[ "$RUN_NPM_BUILD" == "yes" ]]; then
    echo ""
    info "Step 9: Building assets..."

    if [[ -f "$RELEASE_PATH/package.json" ]]; then
        if command -v npm &> /dev/null; then
            cd "$RELEASE_PATH"
            npm ci
            npm run build
            success "Assets built"
        else
            warn "npm not found, skipping asset build"
        fi
    else
        warn "No package.json found, skipping asset build"
    fi
else
    warn "Skipping npm build (RUN_NPM_BUILD=no)"
fi

#=============================================================================
# STEP 10: OPTIMIZE FOR PRODUCTION
#=============================================================================

echo ""
info "Step 10: Optimizing for production..."

cd "$RELEASE_PATH"
run_as_web_user "$PHP_BIN" artisan config:cache
run_as_web_user "$PHP_BIN" artisan route:cache
run_as_web_user "$PHP_BIN" artisan view:cache
success "Production optimizations applied"

#=============================================================================
# STEP 11: SET FINAL PERMISSIONS
#=============================================================================

echo ""
info "Step 11: Setting permissions..."

chmod -R 775 "$APP_PATH/shared/storage"
chown -R "$WEB_USER:$WEB_GROUP" "$APP_PATH/shared/storage"
success "Storage permissions set"

#=============================================================================
# COMPLETE
#=============================================================================

CURRENT_LINK="$APP_PATH/current"

echo ""
echo "============================================"
echo -e "  ${GREEN}Bootstrap Complete!${NC}"
echo "============================================"
echo ""
echo "  Release:  $RELEASE_NAME"
echo "  Path:     $RELEASE_PATH"
echo "  Current:  $CURRENT_LINK"
echo ""
echo "  Directory structure:"
echo "    $APP_PATH/"
echo "    ├── current -> releases/$RELEASE_NAME"
echo "    ├── releases/"
echo "    │   └── $RELEASE_NAME/"
echo "    └── shared/"
echo "        ├── storage/"
echo "        └── .env"
echo ""
echo "============================================"
echo "  Next Steps"
echo "============================================"
echo ""
echo "  1. Update shared/.env with production settings:"
echo "     sudo -u $WEB_USER nano $SHARED_ENV"
echo ""
echo "  2. Configure your web server to point to:"
echo "     $CURRENT_LINK/public"
echo ""
echo "  3. Example Nginx config:"
echo "     root $CURRENT_LINK/public;"
echo ""
echo "  4. Set up queue worker (supervisor or systemd)"
echo ""
echo "  5. Configure GitHub webhook:"
echo "     URL: https://your-domain.com/api/deploy/github"
echo ""
echo "  6. Set up scheduler cron:"
echo "     * * * * * cd $CURRENT_LINK && $PHP_BIN artisan schedule:run >> /dev/null 2>&1"
echo ""
echo "============================================"
