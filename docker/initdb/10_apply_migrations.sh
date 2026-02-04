#!/usr/bin/env bash
set -euo pipefail

MIGRATIONS_DIR="/docker-entrypoint-initdb.d/migrations"

if [[ ! -d "$MIGRATIONS_DIR" ]]; then
  echo "No migrations directory found at $MIGRATIONS_DIR"
  exit 0
fi

shopt -s nullglob
for file in "$MIGRATIONS_DIR"/*.sql; do
  echo "Applying migration: $(basename "$file")"
  mysql --force -uroot -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}" < "$file"
done
