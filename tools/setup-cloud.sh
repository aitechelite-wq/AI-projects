#!/usr/bin/env bash
# Non-root Debian cloud setup. Windows installation is documented in README.md.
set -euo pipefail
cd "$(dirname "$0")/.."
if command -v php >/dev/null 2>&1 && php tools/check-runtime.php; then
  exit 0
fi
CAFE_RUNTIME=/workspace/.php-runtime
if [[ -x "$CAFE_RUNTIME/bin/php" ]] && "$CAFE_RUNTIME/bin/php" tools/check-runtime.php; then
  exit 0
fi
if [[ ! -f /usr/share/keyrings/debian-archive-keyring.gpg || ! -x /usr/bin/apt-get ]]; then
  echo 'Install PHP 8.2+ with pdo_sqlite and fileinfo using your OS package manager.' >&2
  exit 1
fi
mkdir -p "$CAFE_RUNTIME/apt/lists/partial" "$CAFE_RUNTIME/apt/cache/archives/partial" "$CAFE_RUNTIME/bin" "$CAFE_RUNTIME/root"
cat > "$CAFE_RUNTIME/apt.conf" <<'CONFIG'
Dir::Etc::main "-";
Dir::Etc::parts "-";
Dir::Etc::sourcelist "/workspace/.php-runtime/sources.list";
Dir::Etc::sourceparts "-";
Dir::State::lists "/workspace/.php-runtime/apt/lists";
Dir::Cache "/workspace/.php-runtime/apt/cache";
Dir::Cache::archives "/workspace/.php-runtime/apt/cache/archives";
CONFIG
cat > "$CAFE_RUNTIME/sources.list" <<'SOURCES'
deb [signed-by=/usr/share/keyrings/debian-archive-keyring.gpg] https://deb.debian.org/debian trixie main
deb [signed-by=/usr/share/keyrings/debian-archive-keyring.gpg] https://deb.debian.org/debian-security trixie-security main
SOURCES
export APT_CONFIG="$CAFE_RUNTIME/apt.conf"
/usr/bin/apt-get update
CAFE_DOWNLOAD_DIR=$(mktemp -d "$CAFE_RUNTIME/download.XXXXXX")
trap 'rm -rf "$CAFE_DOWNLOAD_DIR"' EXIT
cd "$CAFE_DOWNLOAD_DIR"
/usr/bin/apt-get download php8.4-cli php8.4-common php8.4-sqlite3 libargon2-1
for package in ./*.deb; do dpkg-deb -x "$package" "$CAFE_RUNTIME/root"; done
cat > "$CAFE_RUNTIME/bin/php" <<'WRAPPER'
#!/usr/bin/env bash
set -euo pipefail
export LD_LIBRARY_PATH="/workspace/.php-runtime/root/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
exec /workspace/.php-runtime/root/usr/bin/php8.4 -n -d extension_dir=/workspace/.php-runtime/root/usr/lib/php/20240924 -d extension=pdo -d extension=pdo_sqlite -d extension=sqlite3 -d extension=fileinfo -d extension=ctype -d session.save_path=/tmp "$@"
WRAPPER
chmod +x "$CAFE_RUNTIME/bin/php"
cd /workspace/AI-projects
"$CAFE_RUNTIME/bin/php" tools/check-runtime.php
