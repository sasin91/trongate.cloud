#!/bin/bash
# Import db/*.sql in order. Extra arguments go to the mysql/mariadb client.
set -euo pipefail

DB_DIR="$(dirname "$0")/../db"

# Check for SQL files
if [ ! -d "$DB_DIR" ]; then
    echo "Error: DB directory not found at $DB_DIR"
    exit 1
fi

shopt -s nullglob
files=("$DB_DIR"/*.sql)
count=${#files[@]}

if [ "$count" -eq 0 ]; then
    echo "Warning: No SQL files found in $DB_DIR"
    exit 0
fi

mapfile -t files < <(printf '%s\n' "${files[@]}" | sort -V)

# Determine MySQL/MariaDB executable
MYSQL_BIN="mysql"
if ! command -v "$MYSQL_BIN" &> /dev/null; then
    if command -v mariadb &> /dev/null; then
        MYSQL_BIN="mariadb"
    else
        echo "Error: mysql/mariadb client not found in PATH or standard locations."
        exit 1
    fi
fi

echo "Using $MYSQL_BIN"
echo "Importing $count SQL files..."

# Batch all files into one stream
cat "${files[@]}" | "$MYSQL_BIN" "$@"

echo "All imports completed successfully."
