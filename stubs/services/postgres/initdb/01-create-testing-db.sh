#!/bin/sh
# Runs once, when the PostgreSQL volume is created: the database of the tests next to the development one.
set -e

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE DATABASE testing OWNER "$POSTGRES_USER";
EOSQL
