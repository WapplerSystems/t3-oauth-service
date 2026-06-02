CREATE TABLE tx_oauthsvc_client (
    metadata text
);

CREATE TABLE tx_oauthsvc_connection (
    code_verifier varchar(255) DEFAULT '' NOT NULL,
    metadata text
);