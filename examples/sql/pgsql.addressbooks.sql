CREATE TABLE addressbooks (
    id SERIAL NOT NULL,
    synctoken INTEGER NOT NULL DEFAULT 1
);

ALTER TABLE ONLY addressbooks
    ADD CONSTRAINT addressbooks_pkey PRIMARY KEY (id);

CREATE TABLE addressbookinstances (
    id SERIAL NOT NULL,
    addressbookid INTEGER NOT NULL,
    principaluri VARCHAR(255),
    access SMALLINT NOT NULL DEFAULT 1,
    displayname VARCHAR(255),
    uri VARCHAR(200),
    description TEXT,
    share_href VARCHAR(100),
    share_displayname VARCHAR(255),
    share_invitestatus SMALLINT NOT NULL DEFAULT 2
);

ALTER TABLE ONLY addressbookinstances
    ADD CONSTRAINT addressbookinstances_pkey PRIMARY KEY (id);

CREATE UNIQUE INDEX addressbookinstances_principaluri_uri_ukey
    ON addressbookinstances USING btree (principaluri, uri);

CREATE UNIQUE INDEX addressbookinstances_addressbookid_principaluri_ukey
    ON addressbookinstances USING btree (addressbookid, principaluri);

CREATE UNIQUE INDEX addressbookinstances_addressbookid_share_href_ukey
    ON addressbookinstances USING btree (addressbookid, share_href);

CREATE TABLE cards (
    id SERIAL NOT NULL,
    addressbookid INTEGER NOT NULL,
    carddata BYTEA,
    uri VARCHAR(200),
    lastmodified INTEGER,
    etag VARCHAR(32),
    size INTEGER NOT NULL
);

ALTER TABLE ONLY cards
    ADD CONSTRAINT cards_pkey PRIMARY KEY (id);

CREATE UNIQUE INDEX cards_ukey
    ON cards USING btree (addressbookid, uri);

CREATE TABLE addressbookchanges (
    id SERIAL NOT NULL,
    uri VARCHAR(200) NOT NULL,
    synctoken INTEGER NOT NULL,
    addressbookid INTEGER NOT NULL,
    operation SMALLINT NOT NULL
);

ALTER TABLE ONLY addressbookchanges
    ADD CONSTRAINT addressbookchanges_pkey PRIMARY KEY (id);

CREATE INDEX addressbookchanges_addressbookid_synctoken_ix
    ON addressbookchanges USING btree (addressbookid, synctoken);