#!/bin/bash
set -e

DB_URL="${1:-mysql://root:@127.0.0.1/tcloud}"
DB_DIR="$(dirname "$0")/../db"

# Regex for parsing mysql://user:pass@host:port/dbname
# Note: This regex assumes simple structure. Complex passwords with @ might break this simple parsing.
# We match everything before the LAST @ as credentials.
REGEX="^mysql://([^:]+)(:(.*))?@([^:/]+)(:([0-9]+))?/(.*)$"

if [[ $DB_URL =~ $REGEX ]]; then
    DB_USER="${BASH_REMATCH[1]}"
    DB_PASS="${BASH_REMATCH[3]}" # Match 3 is inner group of (:pass)
    DB_HOST="${BASH_REMATCH[4]}"
    DB_PORT="${BASH_REMATCH[6]}"
    DB_NAME="${BASH_REMATCH[7]}"
    
    # Default port if missing
    if [ -z "$DB_PORT" ]; then
        DB_PORT=3306
    fi
else
    echo "Error: Invalid DB URL format. Expected: mysql://user:pass@host:port/dbname"
    exit 1
fi

if [ ! -d "$DB_DIR" ]; then
    echo "Error: DB directory not found at $DB_DIR"
    exit 1
fi

# Find SQL files, sorted naturally (version sort)
# Using 'sort -V' if available, otherwise standard sort
count=$(ls "$DB_DIR"/*.sql 2>/dev/null | wc -l)
if [ "$count" -eq 0 ]; then
    echo "No SQL files found in $DB_DIR"
    exit 0
fi

# Determine MySQL/MariaDB executable
MYSQL_BIN="mysql"
if ! command -v $MYSQL_BIN &> /dev/null; then
    if command -v mariadb &> /dev/null; then
        MYSQL_BIN="mariadb"
    else
        # Common locations check
        if [ -f "$HOME/.config/herd/bin/mysql" ]; then
            MYSQL_BIN="$HOME/.config/herd/bin/mysql"
        elif [ -f "$HOME/.config/herd/bin/mariadb" ]; then
            MYSQL_BIN="$HOME/.config/herd/bin/mariadb"
        elif [ -f "/usr/local/bin/mysql" ]; then
            MYSQL_BIN="/usr/local/bin/mysql"
        elif [ -f "/usr/local/bin/mariadb" ]; then
            MYSQL_BIN="/usr/local/bin/mariadb"
        else
            echo "Error: mysql/mariadb client not found in PATH or standard locations."
            exit 1
        fi
    fi
fi

echo "Found $count files to import using $MYSQL_BIN"

# Create Database if not exists
echo -n "Ensuring database '$DB_NAME' exists... "
CMD_CREATE="$MYSQL_BIN -h $DB_HOST -P $DB_PORT -u $DB_USER"
if [ -n "$DB_PASS" ]; then
    CMD_CREATE="$CMD_CREATE -p$DB_PASS"
fi

OUTPUT=$($CMD_CREATE -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;" 2>&1)
if [ $? -eq 0 ]; then
    echo "[OK]"
else
    echo "[FAILED]"
    echo "$OUTPUT"
    exit 1
fi

for file in $(ls "$DB_DIR"/*.sql | sort -V); do
    filename=$(basename "$file")
    echo -n "Importing $filename... "
    
    CMD="$MYSQL_BIN -h $DB_HOST -P $DB_PORT -u $DB_USER"
    if [ -n "$DB_PASS" ]; then
        CMD="$CMD -p$DB_PASS"
    fi
    CMD="$CMD $DB_NAME"
    
    OUTPUT=$(cat "$file" | $CMD 2>&1)
    if [ $? -eq 0 ]; then
            echo "[OK]"
    else
            echo "[FAILED]"
            echo "$OUTPUT"
            exit 1
    fi
done

echo "All imports completed successfully."
