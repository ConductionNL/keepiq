-- PostgreSQL extension initialization for the Doriath dev stack.
--
-- Doriath's own tables need nothing beyond stock PostgreSQL; these
-- extensions are for the OpenRegister backend mounted alongside it
-- (vector/trigram search). Trimmed copy of openregister's
-- docker/postgres/init-extensions.sql.

CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gin;
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

ALTER DATABASE nextcloud SET pg_trgm.similarity_threshold = 0.3;
