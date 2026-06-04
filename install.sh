#!/usr/bin/env bash
#
# SafeChat installer
# - Supports Ubuntu 22.04+ and Debian 12+
# - Idempotent: safe to re-run
# - Production-oriented: validates prerequisites, hardens defaults, and stops on errors
#

set -Eeuo pipefail
shopt -s inherit_errexit 2>/dev/null || true

EXIT_GENERAL=1
EXIT_USAGE=2
EXIT_UNSUPPORTED_OS=3
EXIT_PERMISSION=4
EXIT_DEPENDENCY=5
EXIT_SERVICE=6
EXIT_VALIDATION=7

if [[ -t 1 ]]; then
  RED='\033[0;31m'
  GREEN='\033[0;32m'
  YELLOW='\033[1;33m'
  BLUE='\033[0;34m'
  CYAN='\033[0;36m'
  BOLD='\033[1m'
  RESET='\033[0m'
else
  RED=''
  GREEN=''
  YELLOW=''
  BLUE=''
  CYAN=''
  BOLD=''
  RESET=''
fi

CURRENT_STEP=0
TOTAL_STEPS=0
CURRENT_STEP_LABEL="initializing"
APT_UPDATED=false
OS_ID=""
OS_VERSION=""
OS_CODENAME=""
DB_ENGINE="mariadb"
DB_SERVICE=""
MYSQL_SOCKET="/run/mysqld/mysqld.sock"
MYSQL_ADMIN_USER="root"
DB_NAME="safechat"
DB_USER="safechat"
DB_PASS=""
DB_PASS_FILE="/root/safechat-db-pass.txt"
DB_NAME_EXPLICIT=false
DB_USER_EXPLICIT=false
DB_PASS_EXPLICIT=false
DOMAIN=""
EMAIL=""
PHP_VERSION=""
WEB_USER="www-data"
APP_DIR=""
DEFAULT_APP_DIR="/var/www/SafeChat"
APP_URL=""
WITH_DB=true
WITH_NGINX=true
WITH_SSL=false
NON_INTERACTIVE=false
REPAIRED_MARIADB_POST_START=false

log() { printf "%b[INFO]%b %s\n" "${CYAN}" "${RESET}" "$*"; }
ok() { printf "%b[ OK ]%b %s\n" "${GREEN}" "${RESET}" "$*"; }
warn() { printf "%b[WARN]%b %s\n" "${YELLOW}" "${RESET}" "$*"; }
err() { printf "%b[ERR ]%b %s\n" "${RED}" "${RESET}" "$*" >&2; }

die() {
  local message="$1"
  local code="${2:-$EXIT_GENERAL}"
  err "$message"
  exit "$code"
}

usage() {
  cat <<'EOF'
Usage:
  sudo bash install.sh [options]

Options:
  --app-dir PATH            Application directory (default: /var/www/SafeChat)
  --php-version VERSION     PHP version to install (8.3 or 8.4). Auto-detect by default.
  --with-nginx              Install and configure Nginx (default)
  --without-nginx           Skip Nginx installation/configuration
  --with-db                 Install and configure a local database server (default)
  --without-db              Skip local database provisioning
  --db-engine ENGINE        Database engine: mariadb or mysql (default: mariadb)
  --db-name NAME            Database name (default: safechat)
  --db-user USER            Database user (default: safechat)
  --db-pass PASS            Database password (default: reuse existing or generate new)
  --domain DOMAIN           Domain/server_name for Nginx and APP_URL
  --with-ssl                Request and install Let's Encrypt certificate
  --email EMAIL             Email address required by certbot when --with-ssl is used
  --web-user USER           Web server user for writable directories (default: www-data)
  --non-interactive         Fail instead of waiting for missing required inputs
  --help                    Show this help message

Examples:
  sudo bash install.sh --with-db --with-nginx --domain example.com
  sudo bash install.sh --without-db --without-nginx --app-dir /var/www/SafeChat
  sudo bash install.sh --with-ssl --domain chat.example.com --email ops@example.com
EOF
}

begin_step() {
  CURRENT_STEP=$((CURRENT_STEP + 1))
  CURRENT_STEP_LABEL="$1"
  printf "\n%b[%d/%d]%b %b%s%b\n" "${BLUE}" "${CURRENT_STEP}" "${TOTAL_STEPS}" "${RESET}" "${BOLD}" "${CURRENT_STEP_LABEL}" "${RESET}"
}

on_error() {
  local line="$1"
  local code="$2"
  err "Step failed: ${CURRENT_STEP_LABEL} (line ${line}, exit ${code})"
  exit "$code"
}
trap 'on_error "${LINENO}" "$?"' ERR

command_exists() { command -v "$1" >/dev/null 2>&1; }
service_unit_exists() {
  local unit="${1}.service"
  local load_state=""

  if systemctl list-unit-files "$unit" --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -Fxq "$unit"; then
    return 0
  fi

  load_state="$(systemctl show "$unit" --property=LoadState --value 2>/dev/null || true)"
  if [[ "$load_state" == "loaded" ]]; then
    return 0
  fi

  [[ -f "/etc/systemd/system/${unit}" || -f "/lib/systemd/system/${unit}" || -f "/usr/lib/systemd/system/${unit}" ]]
}

retry() {
  local attempts="$1"
  local sleep_seconds="$2"
  shift 2

  local n=1
  while true; do
    if "$@"; then
      return 0
    fi
    if (( n >= attempts )); then
      return 1
    fi
    warn "Retry ${n}/${attempts} failed for: $*"
    sleep "$sleep_seconds"
    n=$((n + 1))
  done
}

sanitize_crlf() {
  local path="$1"
  [[ -f "$path" ]] || return 0
  sed -i 's/\r$//' "$path" || true
}

resolve_app_dir() {
  if [[ -n "$APP_DIR" ]]; then
    APP_DIR="$(cd "$APP_DIR" && pwd -P)"
    return 0
  fi

  local cwd=""
  cwd="$(pwd -P)"

  local script_dir=""
  script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"

  local source_dir=""
  if [[ -f "${cwd}/artisan" && -f "${cwd}/composer.json" ]]; then
    source_dir="$cwd"
  elif [[ -f "${script_dir}/artisan" && -f "${script_dir}/composer.json" ]]; then
    source_dir="$script_dir"
  fi

  if [[ -f "${DEFAULT_APP_DIR}/artisan" && -f "${DEFAULT_APP_DIR}/composer.json" ]]; then
    APP_DIR="$DEFAULT_APP_DIR"
    return 0
  fi

  if [[ -z "$source_dir" ]]; then
    APP_DIR="$cwd"
    return 0
  fi

  if [[ -e "$DEFAULT_APP_DIR" ]]; then
    if [[ -n "$(ls -A "$DEFAULT_APP_DIR" 2>/dev/null || true)" ]]; then
      die "Default app dir exists but does not look like SafeChat: ${DEFAULT_APP_DIR}. Please set --app-dir explicitly." "$EXIT_VALIDATION"
    fi
  else
    mkdir -p "$DEFAULT_APP_DIR"
  fi

  if [[ "$source_dir" != "$DEFAULT_APP_DIR" ]]; then
    cp -a "${source_dir}/." "$DEFAULT_APP_DIR/"
    ok "Project copied to ${DEFAULT_APP_DIR}"
  fi

  APP_DIR="$DEFAULT_APP_DIR"
}

validate_args() {
  [[ "$DB_ENGINE" == "mariadb" || "$DB_ENGINE" == "mysql" ]] || die "Unsupported --db-engine: ${DB_ENGINE}" "$EXIT_USAGE"
  [[ -z "$PHP_VERSION" || "$PHP_VERSION" == "8.3" || "$PHP_VERSION" == "8.4" ]] || die "--php-version must be 8.3 or 8.4" "$EXIT_USAGE"

  if [[ "$WITH_SSL" == true && "$WITH_NGINX" != true ]]; then
    die "--with-ssl requires --with-nginx" "$EXIT_USAGE"
  fi

  if [[ "$WITH_SSL" == true && ( -z "$DOMAIN" || -z "$EMAIL" ) ]]; then
    die "--with-ssl requires both --domain and --email" "$EXIT_USAGE"
  fi

  if [[ "$WITH_DB" != true && "$NON_INTERACTIVE" == true && "$DB_NAME_EXPLICIT" != true && "$DB_USER_EXPLICIT" != true && "$DB_PASS_EXPLICIT" != true ]]; then
    warn "--without-db was selected with --non-interactive and no DB_* overrides were provided; existing .env database settings will be preserved if present"
  fi

  if [[ -n "$DOMAIN" && -z "$APP_URL" ]]; then
    APP_URL="https://${DOMAIN}"
    if [[ "$WITH_SSL" != true ]]; then
      APP_URL="http://${DOMAIN}"
    fi
  fi
}

require_root() {
  if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
    die "Run as root (use sudo)." "$EXIT_PERMISSION"
  fi
}

ensure_runtime_prereqs() {
  command_exists apt-get || die "apt-get is required on this host." "$EXIT_DEPENDENCY"
  command_exists systemctl || die "systemctl is required on this host." "$EXIT_DEPENDENCY"
  [[ -f /etc/os-release ]] || die "/etc/os-release not found." "$EXIT_UNSUPPORTED_OS"
}

version_ge() {
  local current="$1"
  local minimum="$2"
  [[ "$(printf '%s\n%s\n' "$minimum" "$current" | sort -V | head -n1)" == "$minimum" ]]
}

detect_os() {
  # shellcheck disable=SC1091
  . /etc/os-release
  OS_ID="${ID:-}"
  OS_VERSION="${VERSION_ID:-}"
  OS_CODENAME="${VERSION_CODENAME:-${UBUNTU_CODENAME:-}}"

  [[ -n "$OS_ID" && -n "$OS_VERSION" ]] || die "Unable to detect OS version." "$EXIT_UNSUPPORTED_OS"

  case "$OS_ID" in
    ubuntu)
      version_ge "$OS_VERSION" "22.04" || die "Ubuntu 22.04+ required (detected ${OS_VERSION})." "$EXIT_UNSUPPORTED_OS"
      ;;
    debian)
      version_ge "$OS_VERSION" "12" || die "Debian 12+ required (detected ${OS_VERSION})." "$EXIT_UNSUPPORTED_OS"
      ;;
    *)
      die "Unsupported OS: ${OS_ID} ${OS_VERSION}. Supported: Ubuntu 22.04+ and Debian 12+." "$EXIT_UNSUPPORTED_OS"
      ;;
  esac

  [[ -n "$OS_CODENAME" ]] || die "Could not determine distribution codename." "$EXIT_UNSUPPORTED_OS"
  ok "Detected ${OS_ID} ${OS_VERSION} (${OS_CODENAME})"
}

apt_update_once() {
  if [[ "$APT_UPDATED" == false ]]; then
    export DEBIAN_FRONTEND=noninteractive
    retry 3 3 apt-get update -y
    APT_UPDATED=true
  fi
}

apt_install() {
  local missing=()
  local pkg

  for pkg in "$@"; do
    if ! dpkg -s "$pkg" >/dev/null 2>&1; then
      missing+=("$pkg")
    fi
  done

  if (( ${#missing[@]} == 0 )); then
    ok "Packages already present: $*"
    return 0
  fi

  apt_update_once
  export DEBIAN_FRONTEND=noninteractive
  retry 2 3 apt-get install -y --no-install-recommends "${missing[@]}"
}

env_set() {
  local key="$1"
  local value="$2"
  local file="$3"

  python3 - "$key" "$value" "$file" <<'PY'
import re
import sys

key, value, path = sys.argv[1], sys.argv[2], sys.argv[3]
try:
    with open(path, "r", encoding="utf-8") as handle:
        lines = handle.read().splitlines()
except FileNotFoundError:
    lines = []

pattern = re.compile(rf"^{re.escape(key)}=.*$")
replacement = f"{key}={value}"
output = []
found = False

for line in lines:
    if pattern.match(line):
        output.append(replacement)
        found = True
    else:
        output.append(line)

if not found:
    output.append(replacement)

with open(path, "w", encoding="utf-8") as handle:
    handle.write("\n".join(output) + "\n")
PY
}

env_get() {
  local key="$1"
  local file="$2"
  [[ -f "$file" ]] || return 1
  grep -E "^${key}=" "$file" | tail -n1 | cut -d'=' -f2-
}

php_pkg_available() {
  local version="$1"
  apt-cache show "php${version}-fpm" >/dev/null 2>&1
}

ensure_php_repo_debian_sury() {
  local keyring="/usr/share/keyrings/php-sury.gpg"
  local list="/etc/apt/sources.list.d/php-sury.list"

  if [[ ! -f "$list" ]]; then
    log "Adding the Sury PHP repository for Debian"
    apt_install ca-certificates curl gnupg
    curl -fsSL https://packages.sury.org/php/apt.gpg | gpg --dearmor -o "$keyring"
    printf 'deb [signed-by=%s] https://packages.sury.org/php/ %s main\n' "$keyring" "$OS_CODENAME" >"$list"
    APT_UPDATED=false
  fi
}

ensure_php_repo_ubuntu_ondrej() {
  if ! grep -RqsE 'ondrej/php' /etc/apt/sources.list /etc/apt/sources.list.d/*.list 2>/dev/null; then
    log "Adding the Ondrej PHP PPA for Ubuntu"
    apt_install software-properties-common ca-certificates
    add-apt-repository -y ppa:ondrej/php
    APT_UPDATED=false
  fi
}

select_php_version() {
  if [[ -n "$PHP_VERSION" ]]; then
    ok "Using PHP ${PHP_VERSION} (requested)"
    return 0
  fi

  local candidate
  for candidate in 8.4 8.3; do
    if php_pkg_available "$candidate"; then
      PHP_VERSION="$candidate"
      ok "Selected PHP ${PHP_VERSION}"
      return 0
    fi
  done

  if [[ "$OS_ID" == "ubuntu" ]]; then
    ensure_php_repo_ubuntu_ondrej
  else
    ensure_php_repo_debian_sury
  fi

  apt_update_once
  for candidate in 8.4 8.3; do
    if php_pkg_available "$candidate"; then
      PHP_VERSION="$candidate"
      ok "Selected PHP ${PHP_VERSION}"
      return 0
    fi
  done

  die "Unable to find PHP 8.3+ packages." "$EXIT_DEPENDENCY"
}

ensure_php() {
  select_php_version
  apt_install \
    "php${PHP_VERSION}-cli" \
    "php${PHP_VERSION}-fpm" \
    "php${PHP_VERSION}-common" \
    "php${PHP_VERSION}-mysql" \
    "php${PHP_VERSION}-mbstring" \
    "php${PHP_VERSION}-xml" \
    "php${PHP_VERSION}-curl" \
    "php${PHP_VERSION}-bcmath" \
    "php${PHP_VERSION}-intl" \
    "php${PHP_VERSION}-zip" \
    openssl

  systemctl enable --now "php${PHP_VERSION}-fpm"
  command_exists php || die "php command is not available after installation." "$EXIT_DEPENDENCY"
  ok "$(php -v | head -n1)"
}

ensure_composer() {
  if command_exists composer; then
    ok "Composer already installed: $(composer --version 2>/dev/null | head -n1)"
    return 0
  fi

  local installer="/tmp/composer-setup.php"
  local expected actual

  apt_install curl
  curl -fsSL https://getcomposer.org/installer -o "$installer"
  expected="$(curl -fsSL https://composer.github.io/installer.sig)"
  actual="$(php -r "echo hash_file('sha384', '${installer}');")"

  [[ "$expected" == "$actual" ]] || die "Composer installer signature verification failed." "$EXIT_DEPENDENCY"

  php "$installer" --install-dir=/usr/local/bin --filename=composer
  rm -f "$installer"
  ok "Installed Composer: $(composer --version | head -n1)"
}

prepare_mariadb_post_start() {
  local script="/etc/mysql/debian-start"
  local inc_script="/etc/mysql/debian-start.inc.sh"
  local share_inc="/usr/share/mariadb/debian-start.inc.sh"

  mkdir -p /etc/mysql

  if [[ ! -f "$inc_script" && -f "$share_inc" ]]; then
    cp "$share_inc" "$inc_script"
    chmod +x "$inc_script" || true
  fi

  if [[ ! -x "$script" ]]; then
    warn "MariaDB debian-start script is missing or not executable. Recreating minimal script..."
    cat >"$script" <<EOF
#!/bin/bash
$inc_script mariadbd mysql
EOF
    chmod +x "$script" || true
  fi
}

repair_mariadb_execstartpost() {
  local override_dir="/etc/systemd/system/mariadb.service.d"
  local override_file="${override_dir}/safechat-post-start.conf"

  mkdir -p "$override_dir"
  prepare_mariadb_post_start

  cat >"$override_file" <<'EOF'
[Service]
ExecStartPost=
ExecStartPost=/bin/sh -c 'systemctl unset-environment _WSREP_START_POSITION'
ExecStartPost=/bin/sh /etc/mysql/debian-start
EOF

  systemctl daemon-reload
  systemctl reset-failed mariadb.service || true
  REPAIRED_MARIADB_POST_START=true
  if systemctl cat mariadb.service 2>/dev/null | grep -Fq 'safechat-post-start.conf'; then
    ok "Applied MariaDB post-start compatibility override"
  else
    warn "MariaDB override was written but could not be verified via systemctl cat"
  fi
}

db_service_has_execstartpost_error() {
  [[ "$DB_SERVICE" == "mariadb" ]] || return 1
  journalctl -u "${DB_SERVICE}.service" -n 100 --no-pager 2>/dev/null | grep -q 'status=203/EXEC'
}

pick_db_service() {
  local candidates=()
  local svc

  if [[ "$DB_ENGINE" == "mariadb" ]]; then
    candidates=(mariadb mysql)
  else
    candidates=(mysql mariadb)
  fi

  DB_SERVICE=""
  for svc in "${candidates[@]}"; do
    if service_unit_exists "$svc"; then
      DB_SERVICE="$svc"
      break
    fi
  done

  [[ -n "$DB_SERVICE" ]] || die "No supported database service unit found (mariadb/mysql)." "$EXIT_SERVICE"
}

start_db_service() {
  if [[ "$DB_SERVICE" == "mariadb" ]]; then
    prepare_mariadb_post_start
  fi
  if ! systemctl enable --now "$DB_SERVICE"; then
    err "Failed to start ${DB_SERVICE}. Checking status:"
    systemctl status "$DB_SERVICE" --no-pager || true
    return 1
  fi
  return 0
}

wait_for_db_ready() {
  local waited=0
  while (( waited < 45 )); do
    if db_service_has_execstartpost_error && [[ "$REPAIRED_MARIADB_POST_START" == false ]]; then
      warn "Detected MariaDB ExecStartPost 203/EXEC; applying compatibility repair"
      repair_mariadb_execstartpost
      systemctl restart "$DB_SERVICE" || true
    fi

    if systemctl is-active --quiet "$DB_SERVICE"; then
      if [[ -S "$MYSQL_SOCKET" ]] || mysqladmin ping -u"${MYSQL_ADMIN_USER}" --silent >/dev/null 2>&1; then
        return 0
      fi
    fi

    sleep 1
    waited=$((waited + 1))
  done

  return 1
}

show_db_failure_details() {
  warn "Database service failed: ${DB_SERVICE}.service"
  systemctl status "${DB_SERVICE}.service" --no-pager || true
  journalctl -u "${DB_SERVICE}.service" -n 200 --no-pager || true
  if [[ "$DB_SERVICE" == "mariadb" ]]; then
    warn "MariaDB service definition dump:"
    systemctl cat mariadb.service || true
  fi
}

ensure_db_server() {
  case "$DB_ENGINE" in
    mariadb) apt_install mariadb-server ;;
    mysql) apt_install mysql-server ;;
    *) die "Unsupported --db-engine: ${DB_ENGINE}" "$EXIT_USAGE" ;;
  esac

  pick_db_service

  mkdir -p /run/mysqld
  if id mysql >/dev/null 2>&1; then
    chown mysql:mysql /run/mysqld || true
  fi
  chmod 0755 /run/mysqld || true

  if ! start_db_service; then
    if db_service_has_execstartpost_error && [[ "$REPAIRED_MARIADB_POST_START" == false ]]; then
      warn "Detected MariaDB post-start execution issue during startup; retrying with compatibility fix"
      repair_mariadb_execstartpost
      start_db_service || true
    fi
  fi

  if ! wait_for_db_ready; then
    show_db_failure_details
    die "Database startup timed out." "$EXIT_SERVICE"
  fi

  ok "Database is running (${DB_SERVICE})"
}

generate_password() {
  openssl rand -base64 36 | tr -d '\n'
}

load_existing_db_password() {
  local env_file="${APP_DIR}/.env"
  local existing=""

  if [[ -z "$DB_PASS" && -f "$env_file" ]]; then
    existing="$(env_get DB_PASSWORD "$env_file" || true)"
    if [[ -n "$existing" ]]; then
      DB_PASS="$existing"
      ok "Reusing DB password from existing .env"
      return 0
    fi
  fi

  if [[ -z "$DB_PASS" && -f "$DB_PASS_FILE" ]]; then
    existing="$(tr -d '\r\n' <"$DB_PASS_FILE")"
    if [[ -n "$existing" ]]; then
      DB_PASS="$existing"
      ok "Reusing DB password from ${DB_PASS_FILE}"
      return 0
    fi
  fi
}

save_db_password() {
  printf "%s\n" "$DB_PASS" >"$DB_PASS_FILE"
  chmod 600 "$DB_PASS_FILE"
  ok "Saved DB password to ${DB_PASS_FILE}"
}

mysql_exec() {
  local sql="$1"
  if [[ -S "$MYSQL_SOCKET" ]]; then
    mysql -u"${MYSQL_ADMIN_USER}" --protocol=socket --socket="$MYSQL_SOCKET" -e "$sql"
  else
    mysql -u"${MYSQL_ADMIN_USER}" -h127.0.0.1 -P3306 -e "$sql"
  fi
}

ensure_db_app() {
  [[ -n "$DB_NAME" && -n "$DB_USER" ]] || die "DB name/user missing." "$EXIT_VALIDATION"

  load_existing_db_password
  if [[ -z "$DB_PASS" ]]; then
    DB_PASS="$(generate_password)"
    ok "Generated DB password for ${DB_USER}"
    save_db_password
  fi

  local sql
  sql="$(cat <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
)"

  retry 5 2 mysql_exec "$sql" || die "Unable to create or update the application database/user." "$EXIT_SERVICE"
  ok "Database and application user are ready"
}

ensure_nginx() {
  apt_install nginx
  systemctl enable --now nginx
  systemctl is-active --quiet nginx || die "Nginx failed to start." "$EXIT_SERVICE"
  ok "Nginx is running"
}

configure_nginx_site() {
  local server_name="${DOMAIN:-_}"
  local conf="/etc/nginx/sites-available/safechat.conf"
  local enabled="/etc/nginx/sites-enabled/safechat.conf"
  local sock="/run/php/php${PHP_VERSION}-fpm.sock"

  if [[ -f "$conf" ]]; then
    cp "$conf" "${conf}.$(date +%Y%m%d%H%M%S).bak"
  fi

  cat >"$conf" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${server_name};
    root ${APP_DIR}/public;
    index index.php;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${sock};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

  rm -f /etc/nginx/sites-enabled/default || true
  ln -sf "$conf" "$enabled"
  nginx -t
  systemctl reload nginx
  ok "Nginx site configured for ${server_name}"
}

ensure_ssl() {
  [[ -n "$DOMAIN" && -n "$EMAIL" ]] || die "--with-ssl requires both --domain and --email." "$EXIT_USAGE"
  apt_install certbot python3-certbot-nginx
  certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --non-interactive --redirect
  ok "HTTPS configured for ${DOMAIN}"
}

ensure_app_dir() {
  resolve_app_dir
  [[ -d "$APP_DIR" ]] || die "Application directory does not exist: ${APP_DIR}" "$EXIT_VALIDATION"
  [[ -f "${APP_DIR}/artisan" && -f "${APP_DIR}/composer.json" ]] || die "APP_DIR must contain artisan and composer.json (current: ${APP_DIR})." "$EXIT_VALIDATION"

  # Some deployments do not commit writable directories to git, so create the
  # standard Laravel runtime paths instead of failing early.
  mkdir -p \
    "${APP_DIR}/bootstrap/cache" \
    "${APP_DIR}/storage/app" \
    "${APP_DIR}/storage/framework/cache/data" \
    "${APP_DIR}/storage/framework/sessions" \
    "${APP_DIR}/storage/framework/views" \
    "${APP_DIR}/storage/framework/testing" \
    "${APP_DIR}/storage/logs"

  [[ -d "${APP_DIR}/storage" && -d "${APP_DIR}/bootstrap/cache" ]] || die "Expected Laravel writable directories are missing." "$EXIT_VALIDATION"
  ok "Using application directory: ${APP_DIR}"
}

ensure_base_packages() {
  apt_install ca-certificates curl gnupg git unzip python3 lsb-release
}

ensure_env() {
  local env_file="${APP_DIR}/.env"
  local existing_app_key=""
  local existing_encryption_key=""
  local should_set_db_vars=false

  if [[ ! -f "$env_file" ]]; then
    cp "${APP_DIR}/.env.example" "$env_file"
    sanitize_crlf "$env_file"
    ok "Created .env from .env.example"
  fi

  existing_app_key="$(env_get APP_KEY "$env_file" || true)"
  existing_encryption_key="$(env_get ENCRYPTION_KEY "$env_file" || true)"

  if [[ "$WITH_DB" == true || "$DB_NAME_EXPLICIT" == true || "$DB_USER_EXPLICIT" == true || "$DB_PASS_EXPLICIT" == true ]]; then
    should_set_db_vars=true
  fi

  env_set APP_ENV production "$env_file"
  env_set APP_DEBUG false "$env_file"
  env_set APP_URL "${APP_URL:-http://localhost}" "$env_file"
  env_set SESSION_SECURE_COOKIE "$([[ "$WITH_SSL" == true ]] && echo true || echo false)" "$env_file"
  env_set SAFECHAT_INSTALL_ENABLED false "$env_file"

  if [[ "$should_set_db_vars" == true ]]; then
    env_set DB_CONNECTION mysql "$env_file"
    env_set DB_HOST 127.0.0.1 "$env_file"
    env_set DB_PORT 3306 "$env_file"
    env_set DB_DATABASE "$DB_NAME" "$env_file"
    env_set DB_USERNAME "$DB_USER" "$env_file"
    env_set DB_PASSWORD "$DB_PASS" "$env_file"
  else
    warn "Skipping DB_* updates because local DB provisioning is disabled and no DB overrides were provided"
  fi

  if [[ -z "$existing_encryption_key" || "$existing_encryption_key" == "change-me-to-a-random-32-plus-character-string" ]]; then
    env_set ENCRYPTION_KEY "$(openssl rand -hex 32)" "$env_file"
    ok "Generated ENCRYPTION_KEY"
  fi

  if [[ -n "$existing_app_key" && "$existing_app_key" != "base64:"* ]]; then
    warn "APP_KEY exists but does not look like a Laravel-generated key; it will be refreshed during bootstrap"
  fi
}

ensure_vendor() {
  cd "$APP_DIR"
  export COMPOSER_ALLOW_SUPERUSER=1
  composer install --no-dev --optimize-autoloader --no-interaction
  ok "Composer dependencies installed"
}

set_permissions() {
  local owner_group=""
  if id "$WEB_USER" >/dev/null 2>&1; then
    owner_group="$(id -gn "$WEB_USER")"
    chown -R "${WEB_USER}:${owner_group}" "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
    ok "Writable directories owned by ${WEB_USER}:${owner_group}"
  else
    warn "Web user '${WEB_USER}' does not exist; skipping chown"
  fi

  chmod -R u=rwX,g=rwX,o= "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
  ok "Writable permissions applied"
}

artisan_safe() {
  cd "$APP_DIR"
  php artisan "$@"
}

ensure_app_bootstrap() {
  local env_file="${APP_DIR}/.env"
  if ! grep -qE '^APP_KEY=' "$env_file" || grep -qE '^APP_KEY=$' "$env_file"; then
    artisan_safe key:generate --force
  fi

  artisan_safe migrate --force
  artisan_safe config:cache
  artisan_safe route:cache
  artisan_safe view:cache
  ok "Laravel bootstrap tasks completed"
}

calculate_total_steps() {
  TOTAL_STEPS=6
  if [[ "$WITH_DB" == true ]]; then
    TOTAL_STEPS=$((TOTAL_STEPS + 2))
  fi
  if [[ "$WITH_NGINX" == true ]]; then
    TOTAL_STEPS=$((TOTAL_STEPS + 1))
  fi
  if [[ "$WITH_SSL" == true ]]; then
    TOTAL_STEPS=$((TOTAL_STEPS + 1))
  fi
}

print_summary() {
  printf "\n%bInstallation complete.%b\n" "${GREEN}" "${RESET}"
  printf "  App dir: %s\n" "$APP_DIR"
  printf "  PHP: %s\n" "$PHP_VERSION"
  if [[ "$WITH_DB" == true ]]; then
    printf "  DB engine: %s\n" "$DB_ENGINE"
    printf "  DB service: %s\n" "$DB_SERVICE"
    printf "  DB name/user: %s / %s\n" "$DB_NAME" "$DB_USER"
    printf "  DB password file: %s\n" "$DB_PASS_FILE"
  fi
  if [[ "$WITH_NGINX" == true ]]; then
    printf "  Nginx: enabled\n"
  fi
  if [[ -n "$DOMAIN" ]]; then
    printf "  URL: %s\n" "${APP_URL:-http://${DOMAIN}}"
  fi
}

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --app-dir) APP_DIR="${2:-}"; shift 2 ;;
      --php-version) PHP_VERSION="${2:-}"; shift 2 ;;
      --with-nginx) WITH_NGINX=true; shift ;;
      --without-nginx) WITH_NGINX=false; shift ;;
      --with-db) WITH_DB=true; shift ;;
      --without-db) WITH_DB=false; shift ;;
      --db-engine) DB_ENGINE="${2:-}"; shift 2 ;;
      --db-name) DB_NAME="${2:-}"; DB_NAME_EXPLICIT=true; shift 2 ;;
      --db-user) DB_USER="${2:-}"; DB_USER_EXPLICIT=true; shift 2 ;;
      --db-pass) DB_PASS="${2:-}"; DB_PASS_EXPLICIT=true; shift 2 ;;
      --domain) DOMAIN="${2:-}"; shift 2 ;;
      --with-ssl) WITH_SSL=true; shift ;;
      --email) EMAIL="${2:-}"; shift 2 ;;
      --web-user) WEB_USER="${2:-}"; shift 2 ;;
      --non-interactive) NON_INTERACTIVE=true; shift ;;
      --help) usage; exit 0 ;;
      *) die "Unknown option: $1" "$EXIT_USAGE" ;;
    esac
  done
}

main() {
  parse_args "$@"
  validate_args
  calculate_total_steps

  begin_step "Validating runtime environment"
  require_root
  ensure_runtime_prereqs
  detect_os
  ensure_app_dir

  begin_step "Installing base OS packages"
  ensure_base_packages

  begin_step "Installing and validating PHP"
  ensure_php

  begin_step "Installing Composer"
  ensure_composer

  if [[ "$WITH_DB" == true ]]; then
    begin_step "Installing and starting database server"
    ensure_db_server

    begin_step "Provisioning application database and user"
    ensure_db_app
  else
    warn "Skipping local database provisioning"
  fi

  begin_step "Preparing application environment"
  ensure_env

  begin_step "Installing PHP dependencies"
  ensure_vendor

  begin_step "Applying permissions and Laravel bootstrap"
  set_permissions
  ensure_app_bootstrap

  if [[ "$WITH_NGINX" == true ]]; then
    begin_step "Installing and configuring Nginx"
    ensure_nginx
    configure_nginx_site
  else
    warn "Skipping Nginx configuration"
  fi

  if [[ "$WITH_SSL" == true ]]; then
    begin_step "Configuring Let's Encrypt"
    ensure_ssl
  fi

  print_summary
}

main "$@"
