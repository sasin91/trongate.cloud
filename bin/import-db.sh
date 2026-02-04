#!/bin/bash
set -e

DB_DIR="$(dirname "$0")/../db"

# Check for SQL files
if [ ! -d "$DB_DIR" ]; then
    echo "Error: DB directory not found at $DB_DIR"
    exit 1
fi

count=$(ls "$DB_DIR"/*.sql 2>/dev/null | wc -l)
if [ "$count" -eq 0 ]; then
    echo "Warning: No SQL files found in $DB_DIR"
    exit 0
fi

# Determine MySQL/MariaDB executable
MYSQL_BIN="mysql"
if ! command -v $MYSQL_BIN &> /dev/null; then
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
cat $(ls "$DB_DIR"/*.sql | sort -V) | $MYSQL_BIN "$@"

echo "All imports completed successfully."
